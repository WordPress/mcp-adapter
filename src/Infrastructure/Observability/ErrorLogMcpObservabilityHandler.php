<?php
/**
 * ErrorLogMcpObservabilityHandler class for logging MCP observability metrics to PHP error log.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Infrastructure\Observability;

/**
 * Class ErrorLogMcpObservabilityHandler
 *
 * This class handles observability tracking by writing metrics to the PHP error log.
 * This provides a simple way to track MCP metrics without external dependencies.
 *
 * @package WP\MCP\ObservabilityHandlers
 */
class ErrorLogMcpObservabilityHandler implements Contracts\McpObservabilityHandlerInterface {

	use McpObservabilityHelperTrait;

	/**
	 * Emit a countable event for tracking.
	 *
	 * @param string $event The event name to record.
	 * @param array  $tags Optional tags to attach to the event.
	 *
	 * @return void
	 */
	public static function record_event( string $event, array $tags = array() ): void {
		$formatted_event = self::format_metric_name( $event );
		$merged_tags     = self::merge_tags( $tags );
		$formatted_tags  = self::format_tags( $merged_tags );

		$log_message = sprintf(
			'[MCP Observability] EVENT %s %s',
			$formatted_event,
			$formatted_tags
		);

		error_log( $log_message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	/**
	 * Record a timing measurement.
	 *
	 * @param string $metric The metric name for timing.
	 * @param float  $duration_ms The duration in milliseconds.
	 * @param array  $tags Optional tags to attach to the timing.
	 *
	 * @return void
	 */
	public static function record_timing( string $metric, float $duration_ms, array $tags = array() ): void {
		$formatted_metric = self::format_metric_name( $metric );
		$merged_tags      = self::merge_tags( $tags );
		$formatted_tags   = self::format_tags( $merged_tags );

		$log_message = sprintf(
			'[MCP Observability] TIMING %s %.2fms %s',
			$formatted_metric,
			$duration_ms,
			$formatted_tags
		);

		error_log( $log_message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}



	/**
	 * Format tags array into a readable string for logging.
	 *
	 * @param array $tags The tags to format.
	 *
	 * @return string
	 */
	private static function format_tags( array $tags ): string {
		if ( empty( $tags ) ) {
			return '';
		}

		$formatted = array_map(
			function ( $key, $value ) {
				return sprintf( '%s=%s', $key, $value );
			},
			array_keys( $tags ),
			array_values( $tags )
		);

		return '[' . implode( ',', $formatted ) . ']';
	}
}
