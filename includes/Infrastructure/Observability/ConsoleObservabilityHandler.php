<?php
/**
 * Console Observability Handler for testing and debugging.
 *
 * Outputs formatted observability events to stdout for easy testing.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Infrastructure\Observability;

use WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface;

/**
 * Console-based observability handler for testing.
 *
 * Outputs nicely formatted events to stdout/error_log with JSON formatting.
 */
class ConsoleObservabilityHandler implements McpObservabilityHandlerInterface {

	use McpObservabilityHelperTrait;

	/**
	 * Record an event with formatted output.
	 *
	 * @param string     $event The event name.
	 * @param array      $tags Tags to attach.
	 * @param float|null $duration_ms Duration in milliseconds.
	 *
	 * @return void
	 */
	public function record_event( string $event, array $tags = array(), ?float $duration_ms = null ): void {
		$formatted_event = self::format_metric_name( $event );
		$merged_tags     = self::merge_tags( $tags );

		// Create a structured output
		$output = array(
			'event'       => $formatted_event,
			'duration_ms' => $duration_ms,
			'tags'        => $merged_tags,
			'timestamp'   => gmdate( 'Y-m-d H:i:s' ),
		);

		// Pretty print JSON for readability
		$json = wp_json_encode( $output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		// Output with visual separator
		$separator = str_repeat( '=', 80 );
		$message   = "\n{$separator}\n[MCP OBSERVABILITY EVENT]\n{$separator}\n{$json}\n{$separator}\n";

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( $message );
	}
}
