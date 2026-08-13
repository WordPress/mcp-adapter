<?php
/**
 * Exact MCP protocol context for one request.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Core;

use WP\McpSchema\Contract\RevisionSchema;
use WP\McpSchema\Schemas;

/**
 * Retains the exact protocol revision, schema catalog, and modern request metadata.
 *
 * @internal
 * @phpstan-import-type SupportedRevisionSchema from Schemas
 */
final class McpProtocolContext {

	/** @var string */
	private string $protocol_version;

	/** @var SupportedRevisionSchema */
	private RevisionSchema $schema;

	/** @var array<string, mixed> */
	private array $client_capabilities;

	/**
	 * @param string                   $protocol_version MCP protocol revision.
	 * @param SupportedRevisionSchema $schema Exact schema catalog.
	 * @param array<string, mixed>     $client_capabilities Per-request modern client capabilities.
	 */
	private function __construct( string $protocol_version, RevisionSchema $schema, array $client_capabilities ) {
		$this->protocol_version    = $protocol_version;
		$this->schema              = $schema;
		$this->client_capabilities = $client_capabilities;
	}

	/**
	 * Create a context for an exact supported protocol revision.
	 *
	 * @param string               $protocol_version MCP protocol revision.
	 * @param array<string, mixed> $client_capabilities Per-request modern client capabilities.
	 */
	public static function for_version( string $protocol_version, array $client_capabilities = array() ): self {
		$schema_revision = McpVersionNegotiator::schema_revision( $protocol_version );

		return new self( $protocol_version, Schemas::revision( $schema_revision ), $client_capabilities );
	}

	public function get_protocol_version(): string {
		return $this->protocol_version;
	}

	/** @return SupportedRevisionSchema */
	public function get_schema(): RevisionSchema {
		return $this->schema;
	}

	/** @return array<string, mixed> */
	public function get_client_capabilities(): array {
		return $this->client_capabilities;
	}

	public function is_modern(): bool {
		return McpVersionNegotiator::MODERN_PROTOCOL_VERSION === $this->protocol_version;
	}
}
