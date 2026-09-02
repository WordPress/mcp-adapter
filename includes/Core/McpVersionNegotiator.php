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
 * Initialization is a 2025-only flow. Per-request 2026 selection is exact.
 *
 * This is a Core layer class — no WordPress function calls.
 *
 * @since 0.5.0
 */
final class McpVersionNegotiator {

	/**
	 * Protocol versions supported by this server, ordered newest-first.
	 *
	 * @var array<int, string>
	 */
	// phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- False positive: sniff mistakes array() commas for multi-const commas (only handles short syntax).
	public const SUPPORTED_PROTOCOL_VERSIONS = array(
		Schemas::V2026_07_28,
		Schemas::V2025_11_25,
	);

	/**
	 * Negotiate the protocol version to use for a session.
	 *
	 * The 2025 initialization lifecycle can only select the exact supported
	 * 2025 revision. Unknown or modern identifiers receive that counter-proposal.
	 *
	 * @since 0.5.0
	 *
	 * @param string $client_version The protocol version requested by the client.
	 *
	 * @return string The negotiated protocol version.
	 */
	public static function negotiate( string $client_version ): string {
		return Schemas::V2025_11_25 === $client_version ? $client_version : Schemas::V2025_11_25;
	}

	/**
	 * Check whether a given version string is supported.
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
}
