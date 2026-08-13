<?php
/**
 * MCP protocol version negotiation.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Core;

/**
 * Negotiates the MCP protocol version between client and server.
 *
 * Legacy initialization negotiates the session-based 2025 revision. Modern
 * requests select the 2026 revision independently through request metadata.
 *
 * This is a Core layer class — no WordPress function calls.
 *
 * @since 0.5.0
 */
final class McpVersionNegotiator {

	public const LEGACY_PROTOCOL_VERSION = '2025-11-25';
	public const MODERN_PROTOCOL_VERSION = '2026-07-28';

	/**
	 * Protocol versions supported by this server, ordered newest-first.
	 *
	 * @var array<int, string>
	 */
	// phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- False positive: sniff mistakes array() commas for multi-const commas (only handles short syntax).
	public const SUPPORTED_PROTOCOL_VERSIONS = array(
		self::MODERN_PROTOCOL_VERSION,
		self::LEGACY_PROTOCOL_VERSION,
	);

	/**
	 * Negotiate the protocol version to use for a session.
	 *
	 * The legacy lifecycle cannot negotiate into the stateless modern protocol.
	 * Unsupported initialize versions therefore fall back to the supported
	 * legacy revision as required by the 2025 lifecycle.
	 *
	 * @since 0.5.0
	 *
	 * @param string $client_version The protocol version requested by the client.
	 *
	 * @return string The negotiated protocol version.
	 */
	public static function negotiate( string $client_version ): string {
		if ( self::LEGACY_PROTOCOL_VERSION === $client_version ) {
			return $client_version;
		}

		return self::LEGACY_PROTOCOL_VERSION;
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

	/**
	 * Map a supported MCP protocol revision to its exact schema revision.
	 *
	 * @throws \InvalidArgumentException For an unsupported revision.
	 */
	public static function schema_revision( string $protocol_version ): string {
		if ( self::is_supported( $protocol_version ) ) {
			return $protocol_version;
		}

		throw new \InvalidArgumentException( sprintf( 'Unsupported MCP protocol version: %s', $protocol_version ) );
	}
}
