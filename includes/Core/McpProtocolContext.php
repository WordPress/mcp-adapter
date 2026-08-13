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
 * The protocol version and schema revision are separate concepts. Every
 * supported protocol version maps explicitly to one exact DTO tree.
 *
 * @since n.e.x.t
 */
final class McpProtocolContext {

	/**
	 * Protocol version 2024-11-05.
	 *
	 * @var string
	 */
	public const PROTOCOL_VERSION_2024_11_05 = '2024-11-05';

	/**
	 * Protocol version 2025-06-18.
	 *
	 * @var string
	 */
	public const PROTOCOL_VERSION_2025_06_18 = '2025-06-18';

	/**
	 * Protocol version 2025-11-25.
	 *
	 * @var string
	 */
	public const PROTOCOL_VERSION_2025_11_25 = '2025-11-25';

	/**
	 * Protocol version 2026-07-28.
	 *
	 * @var string
	 */
	public const PROTOCOL_VERSION_2026_07_28 = '2026-07-28';

	/**
	 * Schema revision 2025-11-25.
	 *
	 * @var string
	 */
	public const SCHEMA_REVISION_2025_11_25 = '2025-11-25';

	/**
	 * Schema revision 2026-07-28.
	 *
	 * @var string
	 */
	public const SCHEMA_REVISION_2026_07_28 = '2026-07-28';

	/**
	 * Request metadata key carrying the protocol revision.
	 *
	 * @var string
	 */
	public const REQUEST_PROTOCOL_VERSION_META_KEY = 'io.modelcontextprotocol/protocolVersion';

	/**
	 * Request metadata key carrying per-request client capabilities.
	 *
	 * @var string
	 */
	public const REQUEST_CLIENT_CAPABILITIES_META_KEY = 'io.modelcontextprotocol/clientCapabilities';

	/**
	 * Selected protocol version.
	 *
	 * @var string
	 */
	private string $protocol_version;

	/**
	 * Create a request protocol context.
	 *
	 * @since n.e.x.t
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
	 * Create a context for protocol version 2025-11-25.
	 *
	 * @since n.e.x.t
	 */
	public static function for_2025_11_25(): self {
		return new self( self::PROTOCOL_VERSION_2025_11_25 );
	}

	/**
	 * Get the selected protocol version.
	 *
	 * @since n.e.x.t
	 */
	public function get_protocol_version(): string {
		return $this->protocol_version;
	}

	/**
	 * Get the exact php-mcp-schema revision used for this request.
	 *
	 * @since n.e.x.t
	 *
	 * @throws \InvalidArgumentException If the protocol version has no schema mapping.
	 */
	public function get_schema_revision(): string {
		switch ( $this->protocol_version ) {
			case self::PROTOCOL_VERSION_2024_11_05:
			case self::PROTOCOL_VERSION_2025_06_18:
			case self::PROTOCOL_VERSION_2025_11_25:
				return self::SCHEMA_REVISION_2025_11_25;

			case self::PROTOCOL_VERSION_2026_07_28:
				return self::SCHEMA_REVISION_2026_07_28;
		}

		throw new \InvalidArgumentException(
			sprintf( 'Unsupported MCP protocol version: %s', $this->protocol_version )
		);
	}
}
