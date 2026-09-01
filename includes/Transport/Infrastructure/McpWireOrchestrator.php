<?php
/**
 * Exact-revision MCP wire orchestrator.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Transport\Infrastructure;

use WP\MCP\Core\McpRequestContext;
use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;
use WP\McpSchema\Record;
use WP\McpSchema\Record\CallToolRequest;
use WP\McpSchema\Record\CallToolResult;
use WP\McpSchema\Record\CallToolResultResponse;
use WP\McpSchema\Record\DiscoverRequest;
use WP\McpSchema\Record\DiscoverResult;
use WP\McpSchema\Record\DiscoverResultResponse;
use WP\McpSchema\Record\EmptyResult;
use WP\McpSchema\Record\GetPromptRequest;
use WP\McpSchema\Record\GetPromptResult;
use WP\McpSchema\Record\GetPromptResultResponse;
use WP\McpSchema\Record\HeaderMismatchError;
use WP\McpSchema\Record\InitializeRequest;
use WP\McpSchema\Record\InitializeResult;
use WP\McpSchema\Record\InitializedNotification;
use WP\McpSchema\Record\JSONRPCErrorResponse;
use WP\McpSchema\Record\JSONRPCResultResponse;
use WP\McpSchema\Record\ListPromptsRequest;
use WP\McpSchema\Record\ListPromptsResult;
use WP\McpSchema\Record\ListPromptsResultResponse;
use WP\McpSchema\Record\ListResourceTemplatesRequest;
use WP\McpSchema\Record\ListResourceTemplatesResult;
use WP\McpSchema\Record\ListResourceTemplatesResultResponse;
use WP\McpSchema\Record\ListResourcesRequest;
use WP\McpSchema\Record\ListResourcesResult;
use WP\McpSchema\Record\ListResourcesResultResponse;
use WP\McpSchema\Record\ListToolsRequest;
use WP\McpSchema\Record\ListToolsResult;
use WP\McpSchema\Record\ListToolsResultResponse;
use WP\McpSchema\Record\PingRequest;
use WP\McpSchema\Record\ReadResourceRequest;
use WP\McpSchema\Record\ReadResourceResult;
use WP\McpSchema\Record\ReadResourceResultResponse;
use WP\McpSchema\Record\UnsupportedProtocolVersionError;
use WP\McpSchema\Schema;
use WP\McpSchema\Schemas;

/**
 * Selects one exact profile before schema hydration and dispatch.
 *
 * Profiles are arrays of functions keyed by exact revision. There are no
 * behavioral labels or inheritance-based encoders.
 *
 * @phpstan-type WireProfile array{
 *   context: \Closure(array<string, mixed>, string, array<string, mixed>, array<string, mixed>|null): McpRequestContext,
 *   allows_request: \Closure(string, Schema): bool,
 *   allows_notification: \Closure(string, Schema): bool,
 *   hydrate: \Closure(string, bool, Schema, \stdClass): Record,
 *   pre_headers: \Closure(array<string, mixed>, string, array<string, mixed>): (array<string, mixed>|null),
 *   headers: \Closure(array<string, mixed>, McpRequestContext, array<string, mixed>): (array<string, mixed>|null),
 *   project_result: \Closure(string, mixed, Schema): Record,
 *   encode: \Closure(string, string|int, Record, Schema): Record,
 *   session_params: \Closure(string, Record): (array<string, mixed>|null),
 *   http_method: \Closure(string): string,
 *   requires_http_session: \Closure(string): bool,
 *   http_status: \Closure(int): int
 * }
 *
 * @since n.e.x.t
 */
final class McpWireOrchestrator {

	/** @var \WP\MCP\Transport\Infrastructure\McpTransportContext */
	private McpTransportContext $transport_context;

	/** @var \WP\MCP\Transport\Infrastructure\JsonRpcRequestDecoder */
	private JsonRpcRequestDecoder $decoder;

	/** @var array<string, WireProfile> */
	private array $profiles;

	/**
	 * Constructor.
	 *
	 * @since n.e.x.t
	 */
	public function __construct( McpTransportContext $transport_context ) {
		$this->transport_context = $transport_context;
		$this->decoder           = new JsonRpcRequestDecoder();
		$this->profiles          = $this->build_profiles();
	}

	/**
	 * Decode exactly one raw JSON object.
	 *
	 * @since n.e.x.t
	 */
	public function decode( string $raw_json ): \stdClass {
		return $this->decoder->decode( $raw_json );
	}

	/**
	 * Return the profile-owned action for one HTTP method.
	 *
	 * @since n.e.x.t
	 */
	public function http_method_action( string $method, ?string $header_revision ): string {
		$revision = Schemas::V2025_11_25 === $header_revision || null === $header_revision
			? Schemas::V2025_11_25
			: Schemas::V2026_07_28;
		$policy   = $this->profiles[ $revision ]['http_method'];

		return $policy( $method );
	}

	/**
	 * Whether the 2025-11-25 profile requires an established HTTP session.
	 *
	 * @since n.e.x.t
	 */
	public function requires_2025_11_25_http_session( \stdClass $message, ?string $header_revision ): bool {
		$generic = $this->decoder->to_associative( $message );
		if ( ! is_array( $generic ) || ! isset( $generic['method'] ) || ! is_string( $generic['method'] ) ) {
			return false;
		}

		$selection = $this->select_profile_revision(
			$generic,
			array( 'protocol_version' => $header_revision ),
			null,
			false
		);
		if ( ! is_string( $selection ) ) {
			return false;
		}

		$policy = $this->profiles[ $selection ]['requires_http_session'];

		return $policy( $generic['method'] );
	}

	/**
	 * Map a processed response through the selected profile's HTTP policy.
	 *
	 * @param \WP\McpSchema\Record|array<string, mixed> $response Processed response.
	 * @since n.e.x.t
	 */
	public function http_response_status( $response, ?McpRequestContext $context, \stdClass $message, ?string $header_revision ): int {
		$wire = is_array( $response ) ? $response : json_decode( (string) wp_json_encode( $response ), true );
		$code = is_array( $wire ) && isset( $wire['error']['code'] ) ? (int) $wire['error']['code'] : 0;
		if ( 0 === $code ) {
			return 200;
		}

		$revision = null === $context ? null : $context->revision();
		if ( null === $revision ) {
			$generic   = $this->decoder->to_associative( $message );
			$selection = is_array( $generic )
				? $this->select_profile_revision( $generic, array( 'protocol_version' => $header_revision ), null, false )
				: null;
			$revision  = is_string( $selection ) ? $selection : null;
		}
		if ( null === $revision ) {
			return McpErrorFactory::mcp_error_to_http_status( $code );
		}

		$policy = $this->profiles[ $revision ]['http_status'];

		return $policy( $code );
	}

	/**
	 * Process one decoded request or supported notification.
	 *
	 * @param \stdClass $message Decoded JSON object.
	 * @param string $transport Transport name.
	 * @param array<string, mixed> $transport_metadata Transport metadata and headers.
	 * @param array<string, mixed>|null $client_params_2025_11_25 Initialize params for an established 2025 connection.
	 * @return array{
	 *   context: \WP\MCP\Core\McpRequestContext|null,
	 *   method: string|null,
	 *   id: mixed,
	 *   notification: bool,
	 *   response: \WP\McpSchema\Record|array<string, mixed>|null,
	 *   initializeParams: array<string, mixed>|null
	 * }
	 * @since n.e.x.t
	 */
	public function process( \stdClass $message, string $transport, array $transport_metadata = array(), ?array $client_params_2025_11_25 = null ): array {
		$generic = $this->decoder->to_associative( $message );
		if ( ! is_array( $generic ) ) {
			return $this->failure( McpErrorFactory::invalid_request( null, 'Message must be an object' ) );
		}

		$id           = $generic['id'] ?? null;
		$safe_id      = is_string( $id ) || is_int( $id ) ? $id : null;
		$method       = isset( $generic['method'] ) && is_string( $generic['method'] ) ? $generic['method'] : null;
		$notification = null !== $method && ! array_key_exists( 'id', $generic );
		if ( '2.0' !== ( $generic['jsonrpc'] ?? null ) || null === $method ) {
			return $this->failure( McpErrorFactory::invalid_request( $safe_id, 'Invalid JSON-RPC request envelope' ), $method, $safe_id, $notification );
		}
		if ( ! $notification && ! is_string( $id ) && ! is_int( $id ) ) {
			return $this->failure( McpErrorFactory::invalid_request( null, 'Request id must be a string or integer' ), $method, null, false );
		}

		$selection = $this->select_profile_revision( $generic, $transport_metadata, $client_params_2025_11_25 );
		if ( is_array( $selection ) ) {
			$schema   = McpErrorFactory::UNSUPPORTED_VERSION === ( $selection['error']['code'] ?? null )
				? $this->transport_context->mcp_server->get_schema_provider()->for_revision( Schemas::V2026_07_28 )
				: null;
			$response = null === $schema ? $selection : $this->hydrate_error( $selection, $schema );

			return $this->failure( $response, $method, $id, $notification );
		}

		$profile      = $this->profiles[ $selection ];
		$schema       = $this->transport_context->mcp_server->get_schema_provider()->for_revision( $selection );
		$pre_headers  = $profile['pre_headers'];
		$header_error = $pre_headers( $generic, $transport, $transport_metadata );
		if ( null !== $header_error ) {
			return $this->failure( $this->hydrate_error( $header_error, $schema ), $method, $id, $notification );
		}

		try {
			$context_factory = $profile['context'];
			$request_context = $context_factory( $generic, $transport, $transport_metadata, $client_params_2025_11_25 );
		} catch ( \Throwable $throwable ) {
			$error = McpErrorFactory::invalid_params( $id, $throwable->getMessage() );

			return $this->failure( $this->hydrate_error( $error, $schema ), $method, $id, $notification );
		}

		$post_headers = $profile['headers'];
		$header_error = $post_headers( $generic, $request_context, $transport_metadata );
		if ( null !== $header_error ) {
			return $this->failure(
				$this->hydrate_error( $header_error, $schema ),
				$method,
				$id,
				$notification,
				$request_context
			);
		}

		if ( $notification ) {
			$allows_notification = $profile['allows_notification'];
			if ( ! $allows_notification( $method, $schema ) ) {
				return array(
					'context'          => $request_context,
					'method'           => $method,
					'id'               => null,
					'notification'     => true,
					'response'         => null,
					'initializeParams' => null,
				);
			}

			try {
				$hydrate = $profile['hydrate'];
				$hydrate( $method, true, $schema, $message );
			} catch ( \Throwable $throwable ) {
				return array(
					'context'          => $request_context,
					'method'           => $method,
					'id'               => null,
					'notification'     => true,
					'response'         => null,
					'initializeParams' => null,
				);
			}

			return array(
				'context'          => $request_context,
				'method'           => $method,
				'id'               => null,
				'notification'     => true,
				'response'         => null,
				'initializeParams' => null,
			);
		}

		$allows_request = $profile['allows_request'];
		if ( ! $allows_request( $method, $schema ) ) {
			$error = McpErrorFactory::method_not_found( $id, $method );
			return $this->failure( $this->hydrate_error( $error, $schema ), $method, $id, false, $request_context );
		}

		try {
			$hydrate = $profile['hydrate'];
			$request = $hydrate( $method, false, $schema, $message );
		} catch ( \Throwable $throwable ) {
			$error = McpErrorFactory::invalid_params( $id, $throwable->getMessage() );
			return $this->failure( $this->hydrate_error( $error, $schema ), $method, $id, false, $request_context );
		}

		if ( 'server/discover' === $method ) {
			$result = $this->create_discover_data();
		} else {
			$result = $this->transport_context->request_router->route_request( $request, $request_context, $transport );
		}

		if ( is_array( $result ) && isset( $result['error'] ) ) {
			$response = $this->hydrate_error( $result, $schema );
		} else {
			try {
				$project_result = $profile['project_result'];
				$projected      = $project_result( $method, $result, $schema );
				$encode         = $profile['encode'];
				$response       = $encode( $method, $id, $projected, $schema );
			} catch ( \Throwable $throwable ) {
				$response = $this->hydrate_error( McpErrorFactory::internal_error( $id, 'Invalid handler result' ), $schema );
			}
		}

		$session_params    = $profile['session_params'];
		$initialize_params = null;
		if ( $this->is_success_response( $response ) ) {
			$initialize_params = $session_params( $method, $request );
		}

		return array(
			'context'          => $request_context,
			'method'           => $method,
			'id'               => $id,
			'notification'     => false,
			'response'         => $response,
			'initializeParams' => $initialize_params,
		);
	}

	/**
	 * Build the exact function-only profiles.
	 *
	 * @return array<string, WireProfile>
	 */
	private function build_profiles(): array {
		$shared_requests     = array(
			'tools/list',
			'tools/call',
			'resources/list',
			'resources/templates/list',
			'resources/read',
			'prompts/list',
			'prompts/get',
		);
		$requests_2025_11_25 = array_merge( array( 'initialize', 'ping' ), $shared_requests );
		$requests_2026_07_28 = array_merge( array( 'server/discover' ), $shared_requests );

		return array(
			Schemas::V2025_11_25 => array(
				'context'               => function ( array $generic, string $transport, array $metadata, ?array $client_params_2025_11_25 ): McpRequestContext {
					return $this->context_2025_11_25( $generic, $transport, $metadata, $client_params_2025_11_25 );
				},
				'allows_request'        => static function ( string $method, Schema $schema ) use ( $requests_2025_11_25 ): bool {
					return in_array( $method, $requests_2025_11_25, true ) && $schema->allowsClientRequest( $method );
				},
				'allows_notification'   => static function ( string $method, Schema $schema ): bool {
					return 'notifications/initialized' === $method && $schema->allowsClientNotification( $method );
				},
				'hydrate'               => function ( string $method, bool $notification, Schema $schema, \stdClass $message ): Record {
					return $this->hydrate_inbound( $method, $notification, $schema, $message );
				},
				'pre_headers'           => static function ( array $_generic, string $_transport, array $_metadata ): ?array {
					unset( $_generic, $_transport, $_metadata );
					return null;
				},
				'headers'               => static function ( array $_generic, McpRequestContext $_context, array $_metadata ): ?array {
					unset( $_generic, $_context, $_metadata );
					return null;
				},
				'project_result'        => function ( string $method, $result, Schema $schema ): Record {
					return $this->project_2025_11_25_result( $method, $result, $schema );
				},
				'encode'                => static function ( string $_method, $id, Record $result, Schema $schema ): Record {
					unset( $_method );
					return $schema->fromArray(
						JSONRPCResultResponse::class,
						array(
							'jsonrpc' => '2.0',
							'id'      => $id,
							'result'  => $result,
						)
					);
				},
				'session_params'        => function ( string $method, Record $request ): ?array {
					return $this->initialize_params( $method, $request );
				},
				'http_method'           => static function ( string $method ): string {
					if ( 'POST' === $method ) {
						return 'process';
					}
					return 'DELETE' === $method ? 'terminate-session' : 'reject';
				},
				'requires_http_session' => static function ( string $method ): bool {
					return 'initialize' !== $method;
				},
				'http_status'           => static function ( int $code ): int {
					return McpErrorFactory::mcp_error_to_http_status( $code );
				},
			),
			Schemas::V2026_07_28 => array(
				'context'               => function ( array $generic, string $transport, array $metadata, ?array $_client_params_2025_11_25 ): McpRequestContext {
					unset( $_client_params_2025_11_25 );
					return $this->context_2026_07_28( $generic, $transport, $metadata );
				},
				'allows_request'        => static function ( string $method, Schema $schema ) use ( $requests_2026_07_28 ): bool {
					return in_array( $method, $requests_2026_07_28, true ) && $schema->allowsClientRequest( $method );
				},
				'allows_notification'   => static function ( string $_method, Schema $_schema ): bool {
					unset( $_method, $_schema );
					return false;
				},
				'hydrate'               => function ( string $method, bool $notification, Schema $schema, \stdClass $message ): Record {
					return $this->hydrate_inbound( $method, $notification, $schema, $message );
				},
				'pre_headers'           => function ( array $generic, string $transport, array $metadata ): ?array {
					return $this->validate_2026_07_28_envelope_headers( $generic, $transport, $metadata );
				},
				'headers'               => function ( array $generic, McpRequestContext $context, array $metadata ): ?array {
					return $this->validate_2026_07_28_parameter_headers( $generic, $context, $metadata );
				},
				'project_result'        => function ( string $method, $result, Schema $schema ): Record {
					return $this->project_2026_07_28_result( $method, $result, $schema );
				},
				'encode'                => function ( string $method, $id, Record $result, Schema $schema ): Record {
					return $this->hydrate_2026_07_28_success( $method, $id, $result, $schema );
				},
				'session_params'        => static function ( string $_method, Record $_request ): ?array {
					unset( $_method, $_request );
					return null;
				},
				'http_method'           => static function ( string $method ): string {
					return 'POST' === $method ? 'process' : 'reject';
				},
				'requires_http_session' => static function ( string $_method ): bool {
					unset( $_method );
					return false;
				},
				'http_status'           => static function ( int $code ): int {
					return McpErrorFactory::INVALID_PARAMS === $code ? 400 : McpErrorFactory::mcp_error_to_http_status( $code );
				},
			),
		);
	}

	/**
	 * Select one exact profile before context construction or hydration.
	 *
	 * @return string|array<string, mixed>
	 */
	private function select_profile_revision( array $generic, array $metadata, ?array $client_params_2025_11_25, bool $enforce_lifecycle = true ) {
		$method          = $generic['method'];
		$params          = is_array( $generic['params'] ?? null ) ? $generic['params'] : array();
		$meta            = is_array( $params['_meta'] ?? null ) ? $params['_meta'] : array();
		$body_revision   = isset( $meta['io.modelcontextprotocol/protocolVersion'] ) && is_string( $meta['io.modelcontextprotocol/protocolVersion'] )
			? $meta['io.modelcontextprotocol/protocolVersion']
			: null;
		$header_revision = isset( $metadata['protocol_version'] ) && is_string( $metadata['protocol_version'] )
			? $metadata['protocol_version']
			: null;

		if ( Schemas::V2026_07_28 === $body_revision || Schemas::V2026_07_28 === $header_revision ) {
			return Schemas::V2026_07_28;
		}

		$requested = is_string( $body_revision ) ? $body_revision : $header_revision;
		if ( null !== $requested && ! in_array( $requested, Schemas::supportedVersions(), true ) ) {
			return McpErrorFactory::unsupported_protocol_version( $generic['id'] ?? null, $requested, Schemas::supportedVersions() );
		}

		if ( 'initialize' === $method ) {
			return Schemas::V2025_11_25;
		}

		if ( $enforce_lifecycle && null === $client_params_2025_11_25 ) {
			return McpErrorFactory::invalid_request( $generic['id'] ?? null, 'The 2025 lifecycle requires initialization before this request' );
		}

		return Schemas::V2025_11_25;
	}

	/** Construct the 2025 initialization/session context. */
	private function context_2025_11_25( array $generic, string $transport, array $metadata, ?array $client_params_2025_11_25 ): McpRequestContext {
		$params       = 'initialize' === $generic['method'] ? ( $generic['params'] ?? array() ) : ( $client_params_2025_11_25 ?? array() );
		$capabilities = $this->to_object( $params['capabilities'] ?? array() );
		$client_info  = isset( $params['clientInfo'] ) ? $this->to_object( $params['clientInfo'] ) : null;

		$schema = $this->transport_context->mcp_server->get_schema_provider()->for_revision( Schemas::V2025_11_25 );
		return new McpRequestContext( Schemas::V2025_11_25, $schema, $capabilities, $client_info, $transport, $metadata );
	}

	/** Construct the 2026 per-request context. */
	private function context_2026_07_28( array $generic, string $transport, array $metadata ): McpRequestContext {
		$params       = $generic['params'] ?? array();
		$meta         = is_array( $params['_meta'] ?? null ) ? $params['_meta'] : array();
		$revision     = $meta['io.modelcontextprotocol/protocolVersion'] ?? null;
		$capabilities = $meta['io.modelcontextprotocol/clientCapabilities'] ?? null;
		if ( Schemas::V2026_07_28 !== $revision || ! is_array( $capabilities ) ) {
			throw new \InvalidArgumentException( '2026 requests require exact protocolVersion and object clientCapabilities metadata.' );
		}

		$client_info = isset( $meta['io.modelcontextprotocol/clientInfo'] ) && is_array( $meta['io.modelcontextprotocol/clientInfo'] )
			? $this->to_object( $meta['io.modelcontextprotocol/clientInfo'] )
			: null;
		$schema      = $this->transport_context->mcp_server->get_schema_provider()->for_revision( Schemas::V2026_07_28 );

		return new McpRequestContext(
			Schemas::V2026_07_28,
			$schema,
			$this->to_object( $capabilities ),
			$client_info,
			$transport,
			$metadata
		);
	}

	/** @return array<string, mixed> Logical server/discover result data. */
	private function create_discover_data(): array {
		return array(
			'supportedVersions' => $this->transport_context->mcp_server->get_schema_provider()->supported_revisions(),
			'capabilities'      => array(
				'prompts'   => array( 'listChanged' => false ),
				'resources' => array( 'listChanged' => false ),
				'tools'     => array( 'listChanged' => false ),
			),
			'instructions'      => $this->transport_context->mcp_server->get_server_description(),
		);
	}

	/**
	 * Apply exact 2025-11-25 result projection and hydrate the result root.
	 *
	 * @param mixed $result Logical handler result.
	 */
	private function project_2025_11_25_result( string $method, $result, Schema $schema ): Record {
		if (
			is_array( $result )
			&& isset( $result['structuredContent'] )
			&& is_array( $result['structuredContent'] )
			&& self::is_list( $result['structuredContent'] )
		) {
			unset( $result['structuredContent'] );
		}

		return $this->hydrate_result( $method, $result, $schema );
	}

	/**
	 * Apply exact 2026-07-28 result projection and hydrate the result root.
	 *
	 * @param mixed $result Logical handler result.
	 */
	private function project_2026_07_28_result( string $method, $result, Schema $schema ): Record {
		if ( ! is_array( $result ) ) {
			return $this->hydrate_result( $method, $result, $schema );
		}

		$result['resultType'] = 'complete';
		if (
			in_array(
				$method,
				array( 'server/discover', 'tools/list', 'prompts/list', 'resources/list', 'resources/templates/list', 'resources/read' ),
				true
			)
		) {
			$result['ttlMs']      = 0;
			$result['cacheScope'] = 'private';
		}
		$meta                                       = is_array( $result['_meta'] ?? null ) ? $result['_meta'] : array();
		$meta['io.modelcontextprotocol/serverInfo'] = array(
			'name'    => $this->transport_context->mcp_server->get_server_name(),
			'version' => $this->transport_context->mcp_server->get_server_version(),
		);
		$result['_meta']                            = $meta;

		return $this->hydrate_result( $method, $result, $schema );
	}

	/**
	 * Hydrate logical handler output through one selected exact result root.
	 *
	 * @param mixed $result Logical handler result.
	 */
	private function hydrate_result( string $method, $result, Schema $schema ): Record {
		if ( $result instanceof Record ) {
			if ( 'initialize' === $method && $result instanceof InitializeResult ) {
				return $result;
			}

			throw new \UnexpectedValueException( 'Handler returned an unexpected protocol record.' );
		}
		if ( ! is_array( $result ) ) {
			throw new \UnexpectedValueException( 'Handler result must be logical array data.' );
		}

		$data = $result;

		switch ( $method ) {
			case 'ping':
				return $schema->fromArray( EmptyResult::class, $data );
			case 'server/discover':
				return $schema->fromArray( DiscoverResult::class, $data );
			case 'tools/list':
				return $schema->fromArray( ListToolsResult::class, $data );
			case 'tools/call':
				return $schema->fromArray( CallToolResult::class, $data );
			case 'resources/list':
				return $schema->fromArray( ListResourcesResult::class, $data );
			case 'resources/templates/list':
				return $schema->fromArray( ListResourceTemplatesResult::class, $data );
			case 'resources/read':
				return $schema->fromArray( ReadResourceResult::class, $data );
			case 'prompts/list':
				return $schema->fromArray( ListPromptsResult::class, $data );
			case 'prompts/get':
				return $schema->fromArray( GetPromptResult::class, $data );
			default:
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is internal protocol diagnostics, not HTML output.
				throw new \LogicException( sprintf( 'No result projector for %s under %s.', $method, $schema->version() ) );
		}
	}

	/** @return array<string, mixed>|null */
	private function initialize_params( string $method, Record $request ): ?array {
		if ( 'initialize' !== $method || ! $request instanceof InitializeRequest ) {
			return null;
		}

		$params = $this->decoder->to_associative( $request->getParams()->jsonSerialize() );

		return is_array( $params ) ? $params : null;
	}

	/**
	 * Hydrate one exact 2026-07-28 success envelope.
	 *
	 * @param string|int|null $id Request ID.
	 */
	private function hydrate_2026_07_28_success( string $method, $id, Record $result, Schema $schema ): Record {
		$data = array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		);

		switch ( $method ) {
			case 'server/discover':
				return $schema->fromArray( DiscoverResultResponse::class, $data );
			case 'tools/list':
				return $schema->fromArray( ListToolsResultResponse::class, $data );
			case 'tools/call':
				return $schema->fromArray( CallToolResultResponse::class, $data );
			case 'resources/list':
				return $schema->fromArray( ListResourcesResultResponse::class, $data );
			case 'resources/templates/list':
				return $schema->fromArray( ListResourceTemplatesResultResponse::class, $data );
			case 'resources/read':
				return $schema->fromArray( ReadResourceResultResponse::class, $data );
			case 'prompts/list':
				return $schema->fromArray( ListPromptsResultResponse::class, $data );
			case 'prompts/get':
				return $schema->fromArray( GetPromptResultResponse::class, $data );
			default:
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is internal protocol diagnostics, not HTML output.
				throw new \LogicException( sprintf( 'No response encoder for %s under %s.', $method, $schema->version() ) );
		}
	}

	/** Hydrate one implemented inbound root without dynamic class strings. */
	private function hydrate_inbound( string $method, bool $notification, Schema $schema, \stdClass $message ): Record {
		if ( $notification && 'notifications/initialized' === $method ) {
			return $schema->fromValue( InitializedNotification::class, $message );
		}

		switch ( $method ) {
			case 'initialize':
				return $schema->fromValue( InitializeRequest::class, $message );
			case 'ping':
				return $schema->fromValue( PingRequest::class, $message );
			case 'server/discover':
				return $schema->fromValue( DiscoverRequest::class, $message );
			case 'tools/list':
				return $schema->fromValue( ListToolsRequest::class, $message );
			case 'tools/call':
				return $schema->fromValue( CallToolRequest::class, $message );
			case 'resources/list':
				return $schema->fromValue( ListResourcesRequest::class, $message );
			case 'resources/templates/list':
				return $schema->fromValue( ListResourceTemplatesRequest::class, $message );
			case 'resources/read':
				return $schema->fromValue( ReadResourceRequest::class, $message );
			case 'prompts/list':
				return $schema->fromValue( ListPromptsRequest::class, $message );
			case 'prompts/get':
				return $schema->fromValue( GetPromptRequest::class, $message );
			default:
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is internal protocol diagnostics, not HTML output.
				throw new \LogicException( sprintf( 'No inbound root for %s under %s.', $method, $schema->version() ) );
		}
	}

	private function hydrate_error( array $error, Schema $schema ): Record {
		$code = $error['error']['code'] ?? McpErrorFactory::INTERNAL_ERROR;
		try {
			if ( Schemas::V2026_07_28 === $schema->version() && McpErrorFactory::HEADER_MISMATCH === $code ) {
				return $schema->fromArray( HeaderMismatchError::class, $error );
			}
			if ( Schemas::V2026_07_28 === $schema->version() && McpErrorFactory::UNSUPPORTED_VERSION === $code ) {
				return $schema->fromArray( UnsupportedProtocolVersionError::class, $error );
			}

			return $schema->fromArray( JSONRPCErrorResponse::class, $error );
		} catch ( \Throwable $throwable ) {
			$fallback = McpErrorFactory::internal_error( null, 'Unable to encode protocol error' );

			return $schema->fromArray( JSONRPCErrorResponse::class, $fallback );
		}
	}

	/** Validate 2026-07-28 HTTP envelope headers before context hydration. */
	private function validate_2026_07_28_envelope_headers( array $message, string $transport, array $metadata ): ?array {
		if ( 'HTTP' !== strtoupper( $transport ) ) {
			return null;
		}

		$id      = $message['id'] ?? null;
		$method  = $message['method'];
		$headers = is_array( $metadata['headers'] ?? null ) ? array_change_key_case( $metadata['headers'], CASE_LOWER ) : array();

		if ( Schemas::V2026_07_28 !== ( $headers['mcp-protocol-version'] ?? null ) ) {
			return McpErrorFactory::header_mismatch( $id, 'MCP-Protocol-Version is missing or does not match the request metadata' );
		}
		$params        = is_array( $message['params'] ?? null ) ? $message['params'] : array();
		$meta          = is_array( $params['_meta'] ?? null ) ? $params['_meta'] : array();
		$body_revision = $meta['io.modelcontextprotocol/protocolVersion'] ?? null;
		if ( is_string( $body_revision ) && ( $headers['mcp-protocol-version'] ?? null ) !== $body_revision ) {
			return McpErrorFactory::header_mismatch( $id, 'MCP-Protocol-Version does not match the request body' );
		}
		if ( $this->plain_header_value( $headers['mcp-method'] ?? null ) !== $method ) {
			return McpErrorFactory::header_mismatch( $id, 'Mcp-Method is missing or does not match the request body' );
		}

		$name_field = 'resources/read' === $method ? 'uri' : 'name';
		if ( in_array( $method, array( 'tools/call', 'resources/read', 'prompts/get' ), true ) ) {
			$header_name = $this->decode_header_value( $headers['mcp-name'] ?? null );
			if ( null === $header_name || ( $params[ $name_field ] ?? null ) !== $header_name ) {
				return McpErrorFactory::header_mismatch( $id, 'Mcp-Name is missing or does not match the request body' );
			}
		}

		return null;
	}

	/** Validate 2026-07-28 x-mcp-header argument mirrors after context selection. */
	private function validate_2026_07_28_parameter_headers( array $message, McpRequestContext $context, array $metadata ): ?array {
		if ( 'HTTP' !== strtoupper( $context->transport() ) || 'tools/call' !== ( $message['method'] ?? null ) ) {
			return null;
		}

		$params  = is_array( $message['params'] ?? null ) ? $message['params'] : array();
		$headers = is_array( $metadata['headers'] ?? null ) ? array_change_key_case( $metadata['headers'], CASE_LOWER ) : array();

		return $this->validate_tool_parameter_headers( $params, $context, $headers, $message['id'] ?? null );
	}

	/**
	 * Validate x-mcp-header argument mirrors for a selected tool.
	 *
	 * @param string|int|float|null $id Request ID.
	 */
	private function validate_tool_parameter_headers( array $params, McpRequestContext $context, array $headers, $id ): ?array {
		$name = $params['name'] ?? null;
		if ( ! is_string( $name ) ) {
			return null;
		}

		$tool = $this->transport_context->mcp_server->get_mcp_tool( $name );
		if ( ! $tool || ! $tool->is_available_for( $context->schema() ) ) {
			return null;
		}

		$tool_record = $tool->get_protocol_record( $context->schema() );
		$schema      = $tool_record->getInputSchema();
		$arguments   = is_array( $params['arguments'] ?? null ) ? $params['arguments'] : array();
		foreach ( $this->header_annotations( $schema ) as $annotation ) {
			$present    = false;
			$value      = $this->value_at_path( $arguments, $annotation['path'], $present );
			$header_key = strtolower( 'mcp-param-' . $annotation['name'] );
			if ( ! $present || null === $value ) {
				continue;
			}

			$raw_header   = $headers[ $header_key ] ?? $headers[ str_replace( '-', '_', $header_key ) ] ?? null;
			$header_value = $this->decode_header_value( $raw_header );
			if ( null === $header_value || ! $this->header_value_matches( $header_value, $value ) ) {
				return McpErrorFactory::header_mismatch( $id, sprintf( '%s is missing or does not match the request body', $header_key ) );
			}
		}

		return null;
	}

	/**
	 * @param list<string> $path Property path.
	 * @return array<int, array{name: string, path: list<string>}>
	 */
	private function header_annotations( \stdClass $schema, array $path = array() ): array {
		$annotations = array();
		$properties  = $schema->properties ?? null;
		if ( ! $properties instanceof \stdClass ) {
			return $annotations;
		}

		foreach ( get_object_vars( $properties ) as $property_name => $property_schema ) {
			if ( ! $property_schema instanceof \stdClass ) {
				continue;
			}
			$property_path   = $path;
			$property_path[] = $property_name;
			$header_name     = $property_schema->{'x-mcp-header'} ?? null;
			if ( is_string( $header_name ) && '' !== $header_name ) {
				$annotations[] = array(
					'name' => $header_name,
					'path' => $property_path,
				);
			}
			$annotations = array_merge( $annotations, $this->header_annotations( $property_schema, $property_path ) );
		}

		return $annotations;
	}

	/**
	 * Read one nested argument path.
	 *
	 * @return mixed
	 * @param-out bool $present Whether the path was present.
	 */
	private function value_at_path( array $arguments, array $path, bool &$present ) {
		$value   = $arguments;
		$present = true;
		foreach ( $path as $segment ) {
			if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
				$present = false;
				return null;
			}
			$value = $value[ $segment ];
		}

		return $value;
	}

	/** @param mixed $value Header value. Decode plain or MCP Base64-sentinel header values. */
	private function decode_header_value( $value ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}
		if ( 0 !== strpos( $value, '=?base64?' ) || '?=' !== substr( $value, -2 ) ) {
			return $this->plain_header_value( $value );
		}

		$decoded = base64_decode( substr( $value, 9, -2 ), true );
		return false === $decoded ? null : $decoded;
	}

	/** @param mixed $value Header value. Validate one non-encoded ASCII field value. */
	private function plain_header_value( $value ): ?string {
		return is_string( $value ) && 1 === preg_match( '/^[\x20-\x7E]*$/D', $value ) ? $value : null;
	}

	/** @param mixed $body_value Body value. Compare a decoded primitive header value to the body value. */
	private function header_value_matches( string $header_value, $body_value ): bool {
		if ( is_bool( $body_value ) ) {
			return ( $body_value ? 'true' : 'false' ) === $header_value;
		}
		if ( is_int( $body_value ) ) {
			if ( $body_value > 9007199254740991 || $body_value < -9007199254740991 ) {
				return false;
			}
			return is_numeric( $header_value ) && (float) $header_value === (float) $body_value;
		}

		return is_string( $body_value ) && $body_value === $header_value;
	}

	/** @param mixed $value Object input. Convert associative input into one JSON object. */
	private function to_object( $value ): \stdClass {
		$object     = new \stdClass();
		$properties = $value instanceof \stdClass ? get_object_vars( $value ) : ( is_array( $value ) ? $value : array() );
		foreach ( $properties as $key => $item ) {
			$object->{$key} = $this->copy_json_value( $item );
		}

		return $object;
	}

	/**
	 * Copy a decoded JSON value without collapsing lists into objects.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private function copy_json_value( $value ) {
		if ( $value instanceof \stdClass ) {
			return $this->to_object( $value );
		}
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 ) ) {
			return array_map( array( $this, 'copy_json_value' ), $value );
		}

		return $this->to_object( $value );
	}

	/** Whether an encoded record is a successful JSON-RPC result response. */
	private function is_success_response( Record $response ): bool {
		$value = $response->jsonSerialize();

		return property_exists( $value, 'result' ) && ! property_exists( $value, 'error' );
	}

	/** PHP 7.4-compatible list detection. */
	private static function is_list( array $value ): bool {
		return array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	/**
	 * Build a standard process failure result.
	 *
	 * @param \WP\McpSchema\Record|array<string, mixed> $response Response.
	 * @param string|int|float|null $id Request ID.
	 * @return array{
	 *   context: \WP\MCP\Core\McpRequestContext|null,
	 *   method: string|null,
	 *   id: mixed,
	 *   notification: bool,
	 *   response: \WP\McpSchema\Record|array<string, mixed>|null,
	 *   initializeParams: array<string, mixed>|null
	 * }
	 */
	private function failure( $response, ?string $method = null, $id = null, bool $notification = false, ?McpRequestContext $context = null ): array {
		return array(
			'context'          => $context,
			'method'           => $method,
			'id'               => $id,
			'notification'     => $notification,
			'response'         => $response,
			'initializeParams' => null,
		);
	}
}
