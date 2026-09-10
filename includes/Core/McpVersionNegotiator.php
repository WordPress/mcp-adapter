<?php
/**
 * MCP protocol version negotiation.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Core;

use WP\McpSchema\Schemas;

/**
 * Negotiates the MCP protocol version between client and server.
 *
 * Two identifier sets exist. Supported revisions have their own schema in
 * `php-mcp-schema`. Legacy revisions have no schema of their own: they are
 * negotiated by identifier and served through the 2025-11-25 schema.
 *
 * Initialization is a 2025-only flow. Per-request 2026 selection is exact.
 *
 * This is a Core layer class — no WordPress function calls.
 *
 * @since 0.5.0
 */
final class McpVersionNegotiator {

	/**
	 * Protocol versions with their own schema, ordered newest-first.
	 *
	 * @var array<int, string>
	 */
	// phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- False positive: sniff mistakes array() commas for multi-const commas (only handles short syntax).
	public const SUPPORTED_PROTOCOL_VERSIONS = array(
		Schemas::V2026_07_28,
		Schemas::V2025_11_25,
	);

	/**
	 * Legacy protocol versions served through the 2025-11-25 schema, ordered newest-first.
	 *
	 * Every server-emitted change between each of these revisions and 2025-11-25
	 * is an optional additive field (icons, `serverInfo.description`, tasks,
	 * `title`, `_meta`, `structuredContent`). The 2025-11-25 projection is
	 * therefore a valid response for each listed identifier, and the official
	 * clients that only speak these revisions parse it unchanged. Extending this
	 * list requires re-checking that invariant against the official changelog of
	 * every revision between the new entry and 2025-11-25.
	 *
	 * 2024-11-05 and 2025-03-26 predate the `MCP-Protocol-Version` header, so
	 * sessions negotiated under them may omit it. Real 2024-11-05 clients use the
	 * HTTP+SSE transport, which this Adapter does not implement; that identifier
	 * is only reachable over STDIO or from newer clients that still send it.
	 *
	 * @since n.e.x.t
	 *
	 * @var array<int, string>
	 */
	// phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- False positive: sniff mistakes array() commas for multi-const commas (only handles short syntax).
	public const LEGACY_PROTOCOL_VERSIONS = array(
		'2025-06-18',
		'2025-03-26',
		'2024-11-05',
	);

	/**
	 * First revision that requires the `MCP-Protocol-Version` header on HTTP requests after initialization.
	 *
	 * @since n.e.x.t
	 *
	 * @var string
	 */
	public const PROTOCOL_VERSION_HEADER_SINCE = '2025-06-18';

	/**
	 * Negotiate the protocol version to use for a session.
	 *
	 * The 2025 initialization lifecycle echoes the exact 2025-11-25 revision or
	 * any legacy identifier served through it. Unknown or modern identifiers
	 * receive 2025-11-25 as the counter-proposal.
	 *
	 * @since 0.5.0
	 * @since n.e.x.t Echoes legacy identifiers served through the 2025-11-25 schema.
	 *
	 * @param string $client_version The protocol version requested by the client.
	 *
	 * @return string The negotiated protocol version.
	 */
	public static function negotiate( string $client_version ): string {
		return self::is_negotiable( $client_version ) ? $client_version : Schemas::V2025_11_25;
	}

	/**
	 * Check whether a given version string has its own schema.
	 *
	 * @since 0.5.0
	 *
	 * @param string $version The protocol version to check.
	 *
	 * @return bool True when the version is in the supported list, false otherwise.
	 */
	public static function is_supported( string $version ): bool {
		return in_array( $version, self::SUPPORTED_PROTOCOL_VERSIONS, true );
	}

	/**
	 * Check whether a given version string can be negotiated through `initialize`.
	 *
	 * @since n.e.x.t
	 *
	 * @param string $version The protocol version to check.
	 *
	 * @return bool True for 2025-11-25 and every legacy identifier, false otherwise.
	 */
	public static function is_negotiable( string $version ): bool {
		return Schemas::V2025_11_25 === $version || in_array( $version, self::LEGACY_PROTOCOL_VERSIONS, true );
	}

	/**
	 * Resolve the schema revision that serves a negotiated protocol version.
	 *
	 * @since n.e.x.t
	 *
	 * @param string $negotiated_version A negotiated protocol version.
	 *
	 * @return string The revision whose schema serves it.
	 */
	public static function schema_version_for( string $negotiated_version ): string {
		return in_array( $negotiated_version, self::LEGACY_PROTOCOL_VERSIONS, true )
			? Schemas::V2025_11_25
			: $negotiated_version;
	}

	/**
	 * Whether a negotiated version requires the `MCP-Protocol-Version` header after initialization.
	 *
	 * @since n.e.x.t
	 *
	 * @param string $negotiated_version A negotiated protocol version.
	 *
	 * @return bool True from 2025-06-18 on, false for older identifiers.
	 */
	public static function requires_protocol_version_header( string $negotiated_version ): bool {
		return strcmp( $negotiated_version, self::PROTOCOL_VERSION_HEADER_SINCE ) >= 0;
	}

	/**
	 * Every protocol version this server accepts, supported first, then legacy, newest-first.
	 *
	 * @since n.e.x.t
	 *
	 * @return array<int, string>
	 */
	public static function advertised_protocol_versions(): array {
		return array_merge( self::SUPPORTED_PROTOCOL_VERSIONS, self::LEGACY_PROTOCOL_VERSIONS );
	}
}
