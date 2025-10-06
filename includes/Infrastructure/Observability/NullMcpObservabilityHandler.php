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
	 * Emit a countable event for tracking with optional timing data.
	 *
	 * This method does nothing and is used when no observability tracking is desired.
	 *
	 * @param string     $event The event name to record.
	 * @param array      $tags Optional tags to attach to the event.
	 * @param float|null $duration_ms Optional duration in milliseconds for timing measurements.
	 *
	 * @return void
	 */
	public function record_event( string $event, array $tags = array(), ?float $duration_ms = null ): void {
		// Do nothing.
	}
}
