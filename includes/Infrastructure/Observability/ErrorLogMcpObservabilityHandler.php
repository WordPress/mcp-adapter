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
	 * Emit a countable event for tracking with optional timing data.
	 *
	 * @param string     $event The event name to record.
	 * @param array      $tags Optional tags to attach to the event.
	 * @param float|null $duration_ms Optional duration in milliseconds for timing measurements.
	 *
	 * @return void
	 */
	public function record_event( string $event, array $tags = array(), ?float $duration_ms = null ): void {
		$formatted_event = self::format_metric_name( $event );
		$merged_tags     = self::merge_tags( $tags );
		$formatted_tags  = self::format_tags( $merged_tags );

		// Include timing data if provided
		if ( null !== $duration_ms ) {
			$log_message = sprintf(
				'[MCP Observability] EVENT %s %.2fms %s',
				$formatted_event,
				$duration_ms,
				$formatted_tags
			);
		} else {
			$log_message = sprintf(
				'[MCP Observability] EVENT %s %s',
				$formatted_event,
				$formatted_tags
			);
		}

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
			static function ( $key, $value ) {
				return sprintf( '%s=%s', $key, $value );
			},
			array_keys( $tags ),
			array_values( $tags )
		);

		return '[' . implode( ',', $formatted ) . ']';
	}
}
