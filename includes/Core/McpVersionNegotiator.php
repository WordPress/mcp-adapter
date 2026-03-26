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
 * If the client requests a supported version, the server echoes it back.
 * Otherwise the server falls back to the latest supported version.
 *
 * This is a Core layer class — no WordPress function calls.
 *
 * @since n.e.x.t
 */
final class McpVersionNegotiator {

	/**
	 * Protocol versions supported by this server, ordered newest-first.
	 *
	 * @var array<int, string>
	 */
	// phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- False positive: sniff mistakes array() commas for multi-const commas (only handles short syntax).
	public const SUPPORTED_PROTOCOL_VERSIONS = array(
		'2025-11-25',
		'2025-06-18',
		'2024-11-05',
	);

	/**
	 * Negotiate the protocol version to use for a session.
	 *
	 * If the client-requested version is in the supported list it is echoed
	 * back verbatim. Otherwise the latest supported version is returned.
	 *
	 * @since n.e.x.t
	 *
	 * @param string $client_version The protocol version requested by the client.
	 *
	 * @return string The negotiated protocol version.
	 */
	public static function negotiate( string $client_version ): string {
		if ( in_array( $client_version, self::SUPPORTED_PROTOCOL_VERSIONS, true ) ) {
			return $client_version;
		}

		return self::SUPPORTED_PROTOCOL_VERSIONS[0];
	}

	/**
	 * Check whether a given version string is supported.
	 *
	 * @since n.e.x.t
	 *
	 * @param string $version The protocol version to check.
	 *
	 * @return bool True when the version is in the supported list, false otherwise.
	 */
	public static function is_supported( string $version ): bool {
		return in_array( $version, self::SUPPORTED_PROTOCOL_VERSIONS, true );
	}
}
