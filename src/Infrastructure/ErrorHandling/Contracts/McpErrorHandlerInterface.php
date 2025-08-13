<?php
/**
 * Interface for MCP error handlers.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Infrastructure\ErrorHandling\Contracts;

/**
 * Interface for handling MCP error logging.
 *
 * This interface defines the contract for error handlers that can log
 * error messages with context information and different severity types.
 */
interface McpErrorHandlerInterface {

	/**
	 * Log an error message with optional context and type.
	 *
	 * @param string $message The log message.
	 * @param array  $context Additional context data.
	 * @param string $type The log type (e.g., 'error', 'info', 'debug').
	 *
	 * @return void
	 */
	public function log( string $message, array $context = array(), string $type = 'error' ): void;
}
