<?php
/**
 * HTTP Origin validation for the MCP Streamable HTTP transport.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Transport\Infrastructure;

use WP\MCP\Core\McpServer;
use WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Validates a present browser Origin against this WordPress installation.
 *
 * Both supported MCP revisions require servers to validate the Origin header
 * on every Streamable HTTP request to prevent DNS rebinding, and to answer a
 * present but invalid Origin with HTTP 403. Requests without an Origin header,
 * such as those from command-line and server-side clients, are accepted.
 *
 * @since n.e.x.t
 * @internal
 */
final class HttpOriginValidator {

	/**
	 * Check whether a request Origin may reach the MCP endpoint.
	 *
	 * A missing or empty Origin is accepted. A present Origin must be a bare
	 * origin (scheme, host, and optional port) whose scheme, host, and effective
	 * port match the home URL, the site URL, or an origin added through the
	 * `mcp_adapter_allowed_http_origins` filter.
	 *
	 * @since n.e.x.t
	 *
	 * @param string|null                                                                  $origin        Origin header value, or null when absent.
	 * @param \WP\MCP\Core\McpServer                                                       $server        MCP server receiving the request.
	 * @param \WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface|null $error_handler Receives a notice when the filter returns an invalid value.
	 *
	 * @return bool True when the request may proceed, false when it must be rejected with HTTP 403.
	 */
	public static function is_allowed( ?string $origin, McpServer $server, ?McpErrorHandlerInterface $error_handler = null ): bool {
		if ( null === $origin || '' === $origin ) {
			return true;
		}

		$normalized = self::normalize_origin( $origin, true );
		if ( null === $normalized ) {
			return false;
		}

		/**
		 * Filters the exact HTTP origins allowed to call MCP endpoints.
		 *
		 * The Origin header is only validated when a client sends one, which
		 * browsers do. Add an origin here when a browser-based MCP client runs on
		 * a host other than the WordPress installation, such as a headless front
		 * end. Each entry is compared by scheme, host, and effective port; paths
		 * are ignored. A return value that is not an array of strings rejects
		 * every request that carries an Origin header.
		 *
		 * @since n.e.x.t
		 *
		 * @param string[]               $origins Allowed origins. Default the origins of home_url() and site_url().
		 * @param \WP\MCP\Core\McpServer $server  MCP server receiving the request.
		 */
		$allowed = apply_filters( 'mcp_adapter_allowed_http_origins', array( home_url(), site_url() ), $server );

		if ( ! is_array( $allowed ) ) {
			self::report_invalid_filter_value( $error_handler );
			return false;
		}

		$allowed_origins = array();
		foreach ( $allowed as $candidate ) {
			if ( ! is_string( $candidate ) ) {
				self::report_invalid_filter_value( $error_handler );
				return false;
			}

			$candidate_origin = self::normalize_origin( $candidate, false );
			if ( null === $candidate_origin ) {
				continue;
			}

			$allowed_origins[] = $candidate_origin;
		}

		return in_array( $normalized, $allowed_origins, true );
	}

	/**
	 * Reduce a URL to its scheme, host, and effective port.
	 *
	 * Scheme and host are compared case-insensitively. The default ports of
	 * http (80) and https (443) are made explicit so that an origin with and
	 * without its default port compare equal.
	 *
	 * @since n.e.x.t
	 *
	 * @param string $url    Origin header value or configured URL.
	 * @param bool   $strict Whether the value must be a bare origin. Request Origin headers are strict; configured URLs may carry a path.
	 *
	 * @return string|null Normalized origin, or null when the value is not a usable origin.
	 */
	private static function normalize_origin( string $url, bool $strict ): ?string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) || '' === $parts['host'] ) {
			return null;
		}

		if (
			$strict
			&& (
				isset( $parts['user'] )
				|| isset( $parts['pass'] )
				|| isset( $parts['query'] )
				|| isset( $parts['fragment'] )
				// A serialized origin has no path at all, not even "/" (RFC 6454).
				|| ( isset( $parts['path'] ) && '' !== $parts['path'] )
			)
		) {
			return null;
		}

		$scheme = strtolower( $parts['scheme'] );
		$host   = strtolower( $parts['host'] );
		$port   = $parts['port'] ?? self::default_port( $scheme );

		return null === $port
			? sprintf( '%s://%s', $scheme, $host )
			: sprintf( '%s://%s:%d', $scheme, $host, $port );
	}

	/**
	 * Return the default port for a scheme with a well-known one.
	 *
	 * @since n.e.x.t
	 *
	 * @param string $scheme Lowercase URL scheme.
	 *
	 * @return int|null Default port, or null when the scheme has none.
	 */
	private static function default_port( string $scheme ): ?int {
		if ( 'https' === $scheme ) {
			return 443;
		}

		return 'http' === $scheme ? 80 : null;
	}

	/**
	 * Log a filter value that cannot be used as an origin list.
	 *
	 * @since n.e.x.t
	 *
	 * @param \WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface|null $error_handler Error handler, if available.
	 */
	private static function report_invalid_filter_value( ?McpErrorHandlerInterface $error_handler ): void {
		if ( null === $error_handler ) {
			return;
		}

		$error_handler->log(
			'The mcp_adapter_allowed_http_origins filter must return an array of strings. Requests with an Origin header are rejected until it does.',
			array( 'HttpOriginValidator::is_allowed' ),
			'warning'
		);
	}
}
