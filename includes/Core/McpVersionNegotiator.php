<?php
/**
 * MCP protocol version negotiation.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Core;

use WP\McpSchema\Generated\V20251125Constants;
use WP\McpSchema\Generated\V20260728Constants;

/**
 * Negotiates the MCP protocol version between client and server.
 *
 * Legacy initialize negotiation and modern per-request version selection are
 * deliberately separate. Capabilities never select a revision.
 *
 * This is a Core layer class — no WordPress function calls.
 *
 * @since 0.5.0
 */
final class McpVersionNegotiator {

	/** Modern stateless revision. */
	public const MODERN_PROTOCOL_VERSION = V20260728Constants::LATEST_PROTOCOL_VERSION;

	/** Legacy initialize/session revision. */
	public const LEGACY_PROTOCOL_VERSION = V20251125Constants::LATEST_PROTOCOL_VERSION;

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
	 * Revisions available through the initialize lifecycle.
	 *
	 * @var list<string>
	 */
	// phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- False positive for array constants.
	public const SUPPORTED_LEGACY_PROTOCOL_VERSIONS = array(
		self::LEGACY_PROTOCOL_VERSION,
	);

	/**
	 * Negotiate the protocol version to use for a session.
	 *
	 * Modern `2026-07-28` has no initialize method. If the client proposes a
	 * legacy revision the server supports, it is echoed; every other proposal
	 * receives the newest supported legacy counter-proposal.
	 *
	 * @since 0.5.0
	 *
	 * @param string $client_version The protocol version requested by the client.
	 *
	 * @return string The negotiated protocol version.
	 */
	public static function negotiate( string $client_version ): string {
		if ( in_array( $client_version, self::SUPPORTED_LEGACY_PROTOCOL_VERSIONS, true ) ) {
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
	 * Whether a revision uses modern per-request metadata.
	 */
	public static function is_modern( string $version ): bool {
		return self::MODERN_PROTOCOL_VERSION === $version;
	}

	/**
	 * Whether a revision uses the legacy initialize/session lifecycle.
	 */
	public static function is_legacy( string $version ): bool {
		return in_array( $version, self::SUPPORTED_LEGACY_PROTOCOL_VERSIONS, true );
	}

	/**
	 * Read a string protocol revision from modern request metadata.
	 *
	 * Missing or malformed values return null; the modern schema validator owns
	 * the corresponding invalid-params detail.
	 *
	 * @param array<string, mixed> $params Request parameters.
	 */
	public static function request_protocol_version( array $params ): ?string {
		$meta = $params['_meta'] ?? null;
		if ( ! is_array( $meta ) ) {
			return null;
		}

		$version = $meta['io.modelcontextprotocol/protocolVersion'] ?? null;

		return is_string( $version ) ? $version : null;
	}
}
