<?php
/**
 * SSE Stream for the MCP HTTP Transport GET endpoint
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Transport\Infrastructure;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Streams a Server-Sent Events response for the Streamable HTTP GET endpoint.
 *
 * WordPress requests run on synchronous PHP-FPM/mod_php workers rather than a
 * long-running event loop, so a stream is only held open for a bounded
 * duration and then closed. Compliant EventSource clients reconnect
 * automatically, which is what keeps a single connection from tying up a
 * worker indefinitely.
 */
class SseStream {

	/**
	 * Stream keep-alive pings for a validated GET request.
	 *
	 * Sends the SSE headers, an initial comment so the client knows the
	 * stream opened successfully, then periodic keep-alive comments until
	 * the configured duration elapses or the client disconnects.
	 *
	 * @return void
	 */
	public function stream(): void {
		$this->prepare_environment();
		$this->send_headers();

		echo self::format_comment( 'stream-open' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw SSE frame, not HTML.
		self::flush_output();

		$duration  = self::get_stream_duration();
		$interval  = self::get_ping_interval();
		$start     = microtime( true );
		$next_tick = $start + $interval;

		while ( microtime( true ) - $start < $duration ) {
			if ( connection_aborted() ) {
				break;
			}

			if ( microtime( true ) >= $next_tick ) {
				echo self::format_comment( 'ping' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw SSE frame, not HTML.
				self::flush_output();
				$next_tick = microtime( true ) + $interval;
			}

			usleep( 200000 );
		}
	}

	/**
	 * Best-effort environment preparation so the stream can run past normal request limits.
	 *
	 * @return void
	 */
	private function prepare_environment(): void {
		// Some hosts disable set_time_limit() or restrict ini_set(), which
		// raises a warning rather than simply failing. Either call is purely
		// best-effort here, and a warning would otherwise land in the SSE
		// body and corrupt the stream, so failures are swallowed explicitly
		// instead of relying on the `@` operator.
		set_error_handler( '__return_true' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Not debug code; scoped below to swallow a possible host-restriction warning that would otherwise corrupt the SSE body.

		try {
			if ( function_exists( 'set_time_limit' ) ) {
				set_time_limit( 0 );
			}

			ini_set( 'zlib.output_compression', '0' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Best-effort; compression buffering would defeat streaming.
		} finally {
			restore_error_handler();
		}
	}

	/**
	 * Send the SSE response headers.
	 *
	 * @return void
	 */
	private function send_headers(): void {
		if ( headers_sent() ) {
			return;
		}

		header( 'Content-Type: text/event-stream; charset=utf-8' );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		header( 'Connection: keep-alive' );
		// Prevents common reverse proxies (e.g. nginx) from buffering the stream.
		header( 'X-Accel-Buffering: no' );
	}

	/**
	 * Flush the current output as far as PHP and the SAPI allow.
	 *
	 * @return void
	 */
	private static function flush_output(): void {
		if ( ob_get_level() > 0 ) {
			ob_flush();
		}

		flush();
	}

	/**
	 * Format an SSE comment line.
	 *
	 * Comment lines (leading colon) are ignored by EventSource's message
	 * parsing, so they are safe to use purely to keep the connection alive.
	 *
	 * @param string $text The comment text.
	 *
	 * @return string The formatted SSE frame.
	 */
	public static function format_comment( string $text ): string {
		return ': ' . $text . "\n\n";
	}

	/**
	 * Get the configured total stream duration in seconds.
	 *
	 * @return int Stream duration in seconds.
	 */
	public static function get_stream_duration(): int {
		/**
		 * Filters how long the MCP HTTP transport holds an SSE (GET) stream open.
		 *
		 * The stream is closed after this many seconds regardless of activity.
		 * Compliant EventSource clients reconnect automatically, so lowering
		 * this only changes how often a reconnect happens, not whether the
		 * feature works.
		 *
		 * @since 0.7.0
		 *
		 * @param int $duration Stream duration in seconds. Default 30.
		 */
		$duration = (int) apply_filters( 'mcp_adapter_sse_stream_duration', 30 );

		return max( 0, $duration );
	}

	/**
	 * Get the configured keep-alive ping interval in seconds.
	 *
	 * @return int Ping interval in seconds (at least 1).
	 */
	public static function get_ping_interval(): int {
		/**
		 * Filters how often the MCP HTTP transport sends an SSE keep-alive comment.
		 *
		 * @since 0.7.0
		 *
		 * @param int $interval Ping interval in seconds. Default 15.
		 */
		$interval = (int) apply_filters( 'mcp_adapter_sse_ping_interval', 15 );

		return max( 1, $interval );
	}
}
