<?php
/**
 * Stateless MCP continuation policy and request-state integrity.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Infrastructure\Protocol;

/**
 * Seals callback-owned continuation state and validates modern MRTR retries.
 *
 * The Adapter stores no server-side continuation state. Everything needed to
 * validate a retry is carried in a short-lived, HMAC-protected requestState.
 *
 * @since n.e.x.t
 */
final class ContinuationManager {

	private const DEFAULT_TTL_SECONDS = 900;
	private const TOKEN_PREFIX        = 'mcp1';
	private const SIGNING_CONTEXT     = 'mcp-adapter-continuation:';

	/** @var list<string> */
	// phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- False positive: sniff mistakes array() commas for multi-const commas.
	private const SUPPORTED_METHODS = array( 'tools/call', 'resources/read', 'prompts/get' );

	/** @var string */
	private string $server_id;

	/** @var callable(): int */
	private $clock;

	/**
	 * @param string        $server_id Server that owns the continuation.
	 * @param callable|null $clock     Optional integer timestamp provider for tests.
	 */
	public function __construct( string $server_id, ?callable $clock = null ) {
		$this->server_id = $server_id;
		$this->clock     = $clock ?? static fn(): int => time();
	}

	/**
	 * Whether request params contain either continuation field.
	 *
	 * @param array<string, mixed> $params Request params.
	 */
	public function has_continuation_fields( array $params ): bool {
		return array_key_exists( 'inputResponses', $params ) || array_key_exists( 'requestState', $params );
	}

	/**
	 * Whether a method can participate in MCP multi-round continuation.
	 */
	public function supports_method( string $method ): bool {
		return in_array( $method, self::SUPPORTED_METHODS, true );
	}

	/**
	 * Prepare an opted-in callback's input-required result for schema encoding.
	 *
	 * @param string                $method           Originating MCP method.
	 * @param array<string, mixed>  $params           Current request params.
	 * @param \stdClass|null        $request_identity Identity-preserving JSON-RPC request.
	 * @param array<string, mixed>  $result           Callback result contract.
	 *
	 * @return array<string, mixed> Result with Adapter-sealed requestState.
	 *
	 * @throws \InvalidArgumentException When the callback contract is malformed.
	 */
	public function prepare_result( string $method, array $params, ?\stdClass $request_identity, array $result ): array {
		if ( ! $this->supports_method( $method ) ) {
			throw new \InvalidArgumentException( 'This MCP method does not support input-required results.' );
		}

		$input_requests = $result['inputRequests'] ?? null;
		$callback_state = $result['requestState'] ?? null;

		if ( null !== $input_requests && ! is_array( $input_requests ) ) {
			throw new \InvalidArgumentException( 'inputRequests must be an object-shaped array.' );
		}

		if ( null !== $callback_state && ! is_string( $callback_state ) ) {
			throw new \InvalidArgumentException( 'requestState must be a string.' );
		}

		if ( null === $input_requests && null === $callback_state ) {
			throw new \InvalidArgumentException( 'An input-required result needs inputRequests or requestState.' );
		}

		$input_request_keys = null === $input_requests ? array() : array_map( 'strval', array_keys( $input_requests ) );
		sort( $input_request_keys, SORT_STRING );

		$result['resultType']   = 'input_required';
		$result['requestState'] = $this->seal(
			array(
				'v'         => 1,
				'server'    => $this->server_id,
				'user'      => $this->authenticated_user_id(),
				'method'    => $method,
				'component' => $this->component_identity( $method, $params ),
				'origin'    => $this->origin_digest( $method, $params, $request_identity ),
				'expires'   => $this->now() + $this->ttl( $method, $params ),
				'inputs'    => $input_request_keys,
				'state'     => $callback_state,
			)
		);

		return $result;
	}

	/**
	 * Verify and unpack continuation data from a modern retry.
	 *
	 * @param string                $method           Retried MCP method.
	 * @param array<string, mixed>  $params           Schema-normalized request params.
	 * @param \stdClass|null        $request_identity Identity-preserving JSON-RPC request.
	 *
	 * @return array{inputResponses: array<string, mixed>, requestState?: string}
	 *
	 * @throws \InvalidArgumentException When the sealed state or request binding is invalid.
	 */
	public function resume( string $method, array $params, ?\stdClass $request_identity ): array {
		if ( ! $this->supports_method( $method ) ) {
			throw new \InvalidArgumentException( 'Continuation is not supported for this MCP method.' );
		}

		if ( ! isset( $params['requestState'] ) || ! is_string( $params['requestState'] ) ) {
			throw new \InvalidArgumentException( 'A continuation retry requires the Adapter-issued requestState.' );
		}

		$payload = $this->unseal( $params['requestState'] );

		$expected = array(
			'v'         => 1,
			'server'    => $this->server_id,
			'user'      => $this->authenticated_user_id(),
			'method'    => $method,
			'component' => $this->component_identity( $method, $params ),
			'origin'    => $this->origin_digest( $method, $params, $request_identity ),
		);

		foreach ( $expected as $key => $value ) {
			if ( ! array_key_exists( $key, $payload ) || $payload[ $key ] !== $value ) {
				throw new \InvalidArgumentException( 'The continuation state does not match this request.' );
			}
		}

		if ( ! isset( $payload['expires'] ) || ! is_int( $payload['expires'] ) || $payload['expires'] < $this->now() ) {
			throw new \InvalidArgumentException( 'The continuation state has expired.' );
		}

		$expected_inputs = $payload['inputs'] ?? null;
		if ( ! is_array( $expected_inputs ) || ! $this->is_string_list( $expected_inputs ) ) {
			throw new \InvalidArgumentException( 'The continuation state has invalid input identifiers.' );
		}

		$provided_responses = $params['inputResponses'] ?? array();
		if ( ! is_array( $provided_responses ) ) {
			throw new \InvalidArgumentException( 'inputResponses must be an object-shaped array.' );
		}

		$responses = array();
		foreach ( $expected_inputs as $input_key ) {
			if ( ! array_key_exists( $input_key, $provided_responses ) ) {
				continue;
			}

			$responses[ $input_key ] = $provided_responses[ $input_key ];
		}

		$continuation = array( 'inputResponses' => $responses );
		if ( array_key_exists( 'state', $payload ) && null !== $payload['state'] ) {
			if ( ! is_string( $payload['state'] ) ) {
				throw new \InvalidArgumentException( 'The continuation state contains invalid callback data.' );
			}

			$continuation['requestState'] = $payload['state'];
		}

		return $continuation;
	}

	/**
	 * Return the capability object still required by embedded input requests.
	 *
	 * @param array<string, mixed> $input_requests      InputRequests map.
	 * @param array<string, mixed> $client_capabilities Current per-request capabilities.
	 *
	 * @return array<string, mixed> Empty when every request is supported.
	 */
	public function missing_capabilities( array $input_requests, array $client_capabilities ): array {
		$missing = array();

		foreach ( $input_requests as $input_request ) {
			$request = $this->object_to_array( $input_request );
			if ( null === $request || ! isset( $request['method'] ) || ! is_string( $request['method'] ) ) {
				continue;
			}

			$params = $this->object_to_array( $request['params'] ?? array() ) ?? array();

			switch ( $request['method'] ) {
				case 'roots/list':
					if ( ! array_key_exists( 'roots', $client_capabilities ) ) {
						$missing['roots'] = array();
					}
					break;

				case 'sampling/createMessage':
					$sampling = $this->object_to_array( $client_capabilities['sampling'] ?? null );
					if ( null === $sampling ) {
						$missing['sampling'] = array();
						$sampling            = array();
					}

					if ( ( array_key_exists( 'tools', $params ) || array_key_exists( 'toolChoice', $params ) ) && ! array_key_exists( 'tools', $sampling ) ) {
						$missing['sampling']['tools'] = array();
					}

					$include_context = $params['includeContext'] ?? 'none';
					if ( 'none' !== $include_context && ! array_key_exists( 'context', $sampling ) ) {
						$missing['sampling']['context'] = array();
					}
					break;

				case 'elicitation/create':
					$elicitation = $this->object_to_array( $client_capabilities['elicitation'] ?? null );
					$mode        = isset( $params['mode'] ) && is_string( $params['mode'] ) ? $params['mode'] : 'form';
					if ( null === $elicitation ) {
						$missing['elicitation'][ $mode ] = array();
						break;
					}

					$form_supported = array() === $elicitation || array_key_exists( 'form', $elicitation );
					if ( ( 'url' === $mode && ! array_key_exists( 'url', $elicitation ) ) || ( 'form' === $mode && ! $form_supported ) ) {
						$missing['elicitation'][ $mode ] = array();
					}
					break;
			}
		}

		return $missing;
	}

	/**
	 * Create the signed token string.
	 *
	 * @param array<string, mixed> $payload Integrity-protected state.
	 */
	private function seal( array $payload ): string {
		$json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION );
		if ( false === $json ) {
			throw new \InvalidArgumentException( 'The continuation state could not be encoded.' );
		}

		$body      = $this->base64url_encode( $json );
		$signature = hash_hmac( 'sha256', self::SIGNING_CONTEXT . $body, wp_salt( 'auth' ), true );

		return self::TOKEN_PREFIX . '.' . $body . '.' . $this->base64url_encode( $signature );
	}

	/**
	 * Verify and decode a signed token.
	 *
	 * @return array<string, mixed>
	 */
	private function unseal( string $token ): array {
		$parts = explode( '.', $token );
		if ( 3 !== count( $parts ) || self::TOKEN_PREFIX !== $parts[0] ) {
			throw new \InvalidArgumentException( 'The continuation state is malformed.' );
		}

		$body               = $parts[1];
		$provided_signature = $this->base64url_decode( $parts[2] );
		$expected_signature = hash_hmac( 'sha256', self::SIGNING_CONTEXT . $body, wp_salt( 'auth' ), true );

		if ( null === $provided_signature || ! hash_equals( $expected_signature, $provided_signature ) ) {
			throw new \InvalidArgumentException( 'The continuation state signature is invalid.' );
		}

		$json = $this->base64url_decode( $body );
		if ( null === $json ) {
			throw new \InvalidArgumentException( 'The continuation state payload is malformed.' );
		}

		$payload = json_decode( $json, true );
		if ( ! is_array( $payload ) ) {
			throw new \InvalidArgumentException( 'The continuation state payload is invalid.' );
		}

		return $payload;
	}

	/**
	 * Stable digest of the original operation, independent of retry-only fields.
	 *
	 * @param array<string, mixed> $params Request params.
	 */
	private function origin_digest( string $method, array $params, ?\stdClass $request_identity ): string {
		$identity_params = null;
		if ( null !== $request_identity && isset( $request_identity->params ) && $request_identity->params instanceof \stdClass ) {
			$identity_properties = get_object_vars( $request_identity->params );
			unset( $identity_properties['_meta'], $identity_properties['inputResponses'], $identity_properties['requestState'] );
			$identity_params = (object) $identity_properties;
		}

		if ( null === $identity_params ) {
			unset( $params['_meta'], $params['inputResponses'], $params['requestState'] );
			$identity_params = (object) $params;
		}

		$canonical = $this->canonicalize(
			(object) array(
				'method' => $method,
				'params' => $identity_params,
			)
		);

		$json = wp_json_encode( $canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION );
		if ( false === $json ) {
			throw new \InvalidArgumentException( 'The request identity could not be canonicalized.' );
		}

		return hash( 'sha256', $json );
	}

	/**
	 * Recursively order JSON object keys while preserving list order.
	 *
	 * @param mixed $value JSON-compatible value.
	 * @return mixed
	 */
	private function canonicalize( $value ) {
		if ( $value instanceof \stdClass ) {
			$properties = get_object_vars( $value );
			ksort( $properties, SORT_STRING );
			$result = new \stdClass();
			foreach ( $properties as $key => $property ) {
				$result->{$key} = $this->canonicalize( $property );
			}

			return $result;
		}

		if ( ! is_array( $value ) ) {
			return $value;
		}

		if ( $this->is_list( $value ) ) {
			return array_map( fn( $item ) => $this->canonicalize( $item ), $value );
		}

		ksort( $value, SORT_STRING );
		$result = new \stdClass();
		foreach ( $value as $key => $item ) {
			$result->{ (string) $key} = $this->canonicalize( $item );
		}

		return $result;
	}

	/**
	 * Exact component identity for a continuation-capable method.
	 *
	 * @param array<string, mixed> $params Request params.
	 */
	private function component_identity( string $method, array $params ): string {
		$key   = 'resources/read' === $method ? 'uri' : 'name';
		$value = $params[ $key ] ?? null;

		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			throw new \InvalidArgumentException( 'The continuation request has no component identity.' );
		}

		return trim( $value );
	}

	/**
	 * Resolve the short continuation lifetime.
	 *
	 * @param array<string, mixed> $params Request params.
	 */
	private function ttl( string $method, array $params ): int {
		$ttl = apply_filters(
			'mcp_adapter_continuation_ttl',
			self::DEFAULT_TTL_SECONDS,
			$this->server_id,
			$method,
			$this->component_identity( $method, $params )
		);

		return is_int( $ttl ) && $ttl > 0 ? $ttl : self::DEFAULT_TTL_SECONDS;
	}

	/**
	 * Require a WordPress-authenticated principal for cross-request state.
	 */
	private function authenticated_user_id(): int {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			throw new \InvalidArgumentException( 'Continuation requires an authenticated WordPress user.' );
		}

		return $user_id;
	}

	private function now(): int {
		return (int) call_user_func( $this->clock );
	}

	private function base64url_encode( string $value ): string {
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	private function base64url_decode( string $value ): ?string {
		$padding = strlen( $value ) % 4;
		if ( 0 !== $padding ) {
			$value .= str_repeat( '=', 4 - $padding );
		}

		$decoded = base64_decode( strtr( $value, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		return false === $decoded ? null : $decoded;
	}

	/** @param mixed $value */
	private function object_to_array( $value ): ?array {
		if ( $value instanceof \stdClass ) {
			return get_object_vars( $value );
		}

		return is_array( $value ) ? $value : null;
	}

	/** @param array<mixed> $value */
	private function is_list( array $value ): bool {
		return array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	/** @param array<mixed> $value */
	private function is_string_list( array $value ): bool {
		if ( ! $this->is_list( $value ) ) {
			return false;
		}

		foreach ( $value as $item ) {
			if ( ! is_string( $item ) ) {
				return false;
			}
		}

		return true;
	}
}
