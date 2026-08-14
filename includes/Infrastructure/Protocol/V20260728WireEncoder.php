<?php
/**
 * MCP 2026-07-28 descriptor-backed wire encoder.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Infrastructure\Protocol;

use WP\MCP\Core\McpProtocolContext;
use WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface;
use WP\McpSchema\Generated\V20260728Constants;
use WP\McpSchema\Generated\V20260728Schema;

/**
 * Encodes revision-neutral Adapter payloads for MCP 2026-07-28.
 *
 * Modern required result fields are added here, immediately before schema
 * hydration, so handlers and public WordPress callbacks remain revision-neutral.
 *
 * @since n.e.x.t
 */
final class V20260728WireEncoder extends AbstractWireEncoder {

	/** Reserved modern result metadata key. */
	private const SERVER_INFO_KEY = 'io.modelcontextprotocol/serverInfo';

	/** @var \WP\McpSchema\Generated\V20260728Schema */
	private V20260728Schema $catalog;

	/** @var array{name: string, version: string} */
	private array $server_info;

	/**
	 * @param \WP\MCP\Core\McpProtocolContext $context Modern protocol context.
	 * @param \WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface $error_handler Encode-failure reporter.
	 * @param array{name: string, version: string} $server_info Server identity advertised in result metadata.
	 */
	public function __construct( McpProtocolContext $context, McpErrorHandlerInterface $error_handler, array $server_info ) {
		$catalog = $context->catalog();

		if ( ! $catalog instanceof V20260728Schema ) {
			throw new \InvalidArgumentException(
				sprintf( 'The %s encoder cannot emit protocol revision %s.', self::class, $context->revision() )
			);
		}

		parent::__construct( $context, $error_handler );
		$this->catalog     = $catalog;
		$this->server_info = $server_info;
	}

	/**
	 * Encode the required modern server discovery result.
	 *
	 * @param array<string, mixed> $data Discovery payload.
	 *
	 * @return array<string, mixed>
	 */
	public function discover_result( array $data ): array {
		return $this->encode( $this->catalog->discoverResult(), $this->enrich_result( $data, true ) );
	}

	/** @inheritDoc */
	public function list_tools_result( array $data ): array {
		return $this->encode( $this->catalog->listToolsResult(), $this->enrich_result( $data, true ) );
	}

	/** @inheritDoc */
	public function call_tool_result( array $data ): array {
		return $this->encode( $this->catalog->callToolResult(), $this->enrich_result( $data, false ) );
	}

	/** @inheritDoc */
	public function list_resources_result( array $data ): array {
		return $this->encode( $this->catalog->listResourcesResult(), $this->enrich_result( $data, true ) );
	}

	/** @inheritDoc */
	public function list_resource_templates_result( array $data ): array {
		return $this->encode( $this->catalog->listResourceTemplatesResult(), $this->enrich_result( $data, true ) );
	}

	/** @inheritDoc */
	public function read_resource_result( array $data ): array {
		return $this->encode( $this->catalog->readResourceResult(), $this->enrich_result( $data, true ) );
	}

	/** @inheritDoc */
	public function list_prompts_result( array $data ): array {
		return $this->encode( $this->catalog->listPromptsResult(), $this->enrich_result( $data, true ) );
	}

	/** @inheritDoc */
	public function get_prompt_result( array $data ): array {
		return $this->encode( $this->catalog->getPromptResult(), $this->enrich_result( $data, false ) );
	}

	/** @inheritDoc */
	public function try_tool( array $data, string $subject = '' ): ?array {
		return $this->try_encode( $this->catalog->tool(), $data, 'Tool', $subject );
	}

	/** @inheritDoc */
	public function try_resource( array $data, string $subject = '' ): ?array {
		return $this->try_encode( $this->catalog->resource(), $data, 'Resource', $subject );
	}

	/** @inheritDoc */
	public function try_prompt( array $data, string $subject = '' ): ?array {
		return $this->try_encode( $this->catalog->prompt(), $data, 'Prompt', $subject );
	}

	/**
	 * Validate the required per-request modern metadata object.
	 *
	 * @param array<string, mixed> $params Request parameters.
	 *
	 * @throws \WP\McpSchema\Runtime\ValidationException When `_meta` is missing or malformed.
	 */
	public function validate_request_metadata( array $params ): void {
		$this->catalog->requestMetaObject()->fromValue( $params['_meta'] ?? null );
	}

	/**
	 * Create a schema-validated modern header-mismatch envelope.
	 *
	 * @param string|int|null $id Request id.
	 * @param string          $message Concise error message.
	 *
	 * @return array<string, mixed>
	 */
	public function header_mismatch_error( $id, string $message = 'Request headers do not match request metadata' ): array {
		return $this->encode(
			$this->catalog->headerMismatchError(),
			$this->error_response( $id, V20260728Constants::HEADER_MISMATCH, $message )
		);
	}

	/**
	 * Create a schema-validated unsupported-version envelope.
	 *
	 * @param string|int|null $id Request id.
	 * @param string          $requested Requested revision.
	 * @param list<string>    $supported Supported revisions.
	 *
	 * @return array<string, mixed>
	 */
	public function unsupported_protocol_version_error( $id, string $requested, array $supported ): array {
		return $this->encode(
			$this->catalog->unsupportedProtocolVersionError(),
			$this->error_response(
				$id,
				V20260728Constants::UNSUPPORTED_PROTOCOL_VERSION,
				'Unsupported protocol version',
				array(
					'supported' => $supported,
					'requested' => $requested,
				)
			)
		);
	}

	/**
	 * Create a schema-validated missing-client-capability envelope.
	 *
	 * @param string|int|null    $id Request id.
	 * @param array<string, mixed> $required_capabilities Required capability shape.
	 *
	 * @return array<string, mixed>
	 */
	public function missing_required_client_capability_error( $id, array $required_capabilities ): array {
		return $this->encode(
			$this->catalog->missingRequiredClientCapabilityError(),
			$this->error_response(
				$id,
				V20260728Constants::MISSING_REQUIRED_CLIENT_CAPABILITY,
				'Missing required client capability',
				array( 'requiredCapabilities' => $required_capabilities )
			)
		);
	}

	/**
	 * Add the fields required on completed modern results.
	 *
	 * @param array<string, mixed> $data Result payload.
	 * @param bool                 $cacheable Whether the exact result type extends CacheableResult.
	 *
	 * @return array<string, mixed>
	 */
	private function enrich_result( array $data, bool $cacheable ): array {
		$result_type = $data['resultType'] ?? 'complete';
		$meta        = isset( $data['_meta'] ) && is_array( $data['_meta'] ) ? $data['_meta'] : array();

		unset( $data['resultType'], $data['_meta'], $data['ttlMs'], $data['cacheScope'] );

		$result = array( 'resultType' => $result_type );
		foreach ( $data as $key => $value ) {
			$result[ $key ] = $value;
		}

		$meta[ self::SERVER_INFO_KEY ] = $this->server_info;
		$result['_meta']               = $meta;

		if ( $cacheable ) {
			$result['ttlMs']      = 0;
			$result['cacheScope'] = 'private';
		}

		return $result;
	}

	/**
	 * Build the neutral envelope passed to one exact modern error descriptor.
	 *
	 * @param string|int|null $id Request id.
	 * @param int             $code Error code.
	 * @param string          $message Error message.
	 * @param mixed|null      $data Optional error data.
	 *
	 * @return array<string, mixed>
	 */
	private function error_response( $id, int $code, string $message, $data = null ): array {
		$error = array(
			'code'    => $code,
			'message' => $message,
		);

		if ( null !== $data ) {
			$error['data'] = $data;
		}

		$response = array(
			'jsonrpc' => V20260728Constants::JSONRPC_VERSION,
			'error'   => $error,
		);

		if ( null !== $id ) {
			$response['id'] = $id;
		}

		return $response;
	}
}
