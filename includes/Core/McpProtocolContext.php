<?php
/**
 * Request-scoped MCP protocol context.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Core;

/**
 * Carries the protocol revision selected for one request.
 *
 * The protocol version and schema revision are separate concepts: all
 * currently supported legacy protocol versions use the V20251125 DTO tree.
 *
 * @since n.e.x.t
 */
final class McpProtocolContext {

	/**
	 * Schema revision used for every legacy protocol version.
	 *
	 * @var string
	 */
	public const LEGACY_SCHEMA_REVISION = '2025-11-25';

	/**
	 * Modern schema revision used by the bounded dual-era codec slice.
	 *
	 * @var string
	 */
	public const MODERN_SCHEMA_REVISION = '2026-07-28';

	/**
	 * Selected protocol version.
	 *
	 * @var string
	 */
	private string $protocol_version;

	/**
	 * Create a request protocol context.
	 *
	 * @param string $protocol_version Selected protocol version.
	 *
	 * @throws \InvalidArgumentException If the protocol version is empty.
	 */
	public function __construct( string $protocol_version ) {
		if ( '' === $protocol_version ) {
			throw new \InvalidArgumentException( 'The MCP protocol version cannot be empty.' );
		}

		$this->protocol_version = $protocol_version;
	}

	/**
	 * Create the backward-compatible default context.
	 */
	public static function legacy_default(): self {
		return new self( self::LEGACY_SCHEMA_REVISION );
	}

	/**
	 * Get the selected protocol version.
	 */
	public function get_protocol_version(): string {
		return $this->protocol_version;
	}

	/**
	 * Get the exact php-mcp-schema revision used for this request.
	 */
	public function get_schema_revision(): string {
		return self::MODERN_SCHEMA_REVISION === $this->protocol_version
			? self::MODERN_SCHEMA_REVISION
			: self::LEGACY_SCHEMA_REVISION;
	}

	/**
	 * Whether this request selects the modern protocol era.
	 */
	public function is_modern(): bool {
		return self::MODERN_SCHEMA_REVISION === $this->get_schema_revision();
	}
}
