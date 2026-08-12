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
 * @since 0.5.0
 */
final class McpVersionNegotiator {

	/**
	 * Negotiate the protocol version to use for a session.
	 *
	 * If the client-requested version is in the supported list it is echoed
	 * back verbatim. Otherwise the latest supported version is returned.
	 *
	 * @since 0.5.0
	 *
	 * @param string $client_version The protocol version requested by the client.
	 *
	 * @return string The negotiated protocol version.
	 */
	public static function negotiate( string $client_version ): string {
		$initialize_versions = McpProtocolContext::get_initialize_protocol_versions();

		if ( in_array( $client_version, $initialize_versions, true ) ) {
			return $client_version;
		}

		return $initialize_versions[0];
	}

	/**
	 * Check whether a version participates in initialize negotiation.
	 *
	 * @since 0.5.0
	 *
	 * @param string $version The protocol version to check.
	 *
	 * @return bool True for initialize-lifecycle versions, false otherwise.
	 */
	public static function is_supported_for_initialize( string $version ): bool {
		return in_array( $version, McpProtocolContext::get_initialize_protocol_versions(), true );
	}
}
