<?php
/**
 * NullMcpErrorHandler class for handling MCP errors without logging.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Infrastructure\ErrorHandling;

/**
 * Class NullMcpErrorHandler
 *
 * This class handles MCP errors by doing nothing. It is used when no error handling is desired.
 *
 * @package WP\MCP\ErrorHandlers
 */
class NullMcpErrorHandler implements Contracts\McpErrorHandlerInterface {

	/**
	 * Log with context.
	 *
	 * This method does nothing and is used when no error handling is desired.
	 *
	 * @param string $message The log message.
	 * @param array  $context Additional context data.
	 * @param string $type The type of log (e.g., 'error', 'info', etc.). Default is 'error'.
	 *
	 * @return void
	 */
	public function log( string $message, array $context = array(), string $type = 'error' ): void {
		// Do nothing.
	}
}
