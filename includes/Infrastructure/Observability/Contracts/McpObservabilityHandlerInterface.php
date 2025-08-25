<?php
/**
 * Interface for MCP observability handlers.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Infrastructure\Observability\Contracts;

/**
 * Interface for handling MCP observability metrics and tracking.
 *
 * This interface defines the contract for observability handlers that can
 * track metrics like request counts, timing, and error rates in the MCP adapter.
 * Concrete implementations can integrate with various observability systems.
 */
interface McpObservabilityHandlerInterface {

	/**
	 * Emit a countable event for tracking.
	 *
	 * @param string $event The event name to record.
	 * @param array  $tags Optional tags to attach to the event.
	 *
	 * @return void
	 */
	public static function record_event( string $event, array $tags = array() ): void;

	/**
	 * Record a timing measurement.
	 *
	 * @param string $metric The metric name for timing.
	 * @param float  $duration_ms The duration in milliseconds.
	 * @param array  $tags Optional tags to attach to the timing.
	 *
	 * @return void
	 */
	public static function record_timing( string $metric, float $duration_ms, array $tags = array() ): void;
}
