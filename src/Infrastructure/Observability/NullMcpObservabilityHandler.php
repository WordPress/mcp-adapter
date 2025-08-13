<?php
/**
 * NullMcpObservabilityHandler class for handling MCP observability without tracking.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Infrastructure\Observability;

/**
 * Class NullMcpObservabilityHandler
 *
 * This class handles MCP observability by doing nothing. It is used when no
 * observability tracking is desired, providing zero overhead.
 *
 * @package WP\MCP\ObservabilityHandlers
 */
class NullMcpObservabilityHandler implements Contracts\McpObservabilityHandlerInterface {

	/**
	 * Emit a countable event for tracking.
	 *
	 * This method does nothing and is used when no observability tracking is desired.
	 *
	 * @param string $event The event name to record.
	 * @param array  $tags Optional tags to attach to the event.
	 *
	 * @return void
	 */
	public static function record_event( string $event, array $tags = array() ): void {
		// Do nothing.
	}

	/**
	 * Record a timing measurement.
	 *
	 * This method does nothing and is used when no observability tracking is desired.
	 *
	 * @param string $metric The metric name for timing.
	 * @param float  $duration_ms The duration in milliseconds.
	 * @param array  $tags Optional tags to attach to the timing.
	 *
	 * @return void
	 */
	public static function record_timing( string $metric, float $duration_ms, array $tags = array() ): void {
		// Do nothing.
	}
}
