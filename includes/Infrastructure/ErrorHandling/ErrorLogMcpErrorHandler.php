<?php
/**
 * ErrorLogMcpErrorHandler class for logging MCP errors to PHP error log.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Infrastructure\ErrorHandling;

/**
 * Class ErrorLogMcpErrorHandler
 *
 * This class handles error logging by writing logs to the PHP error log.
 *
 * @package WP\MCP\ErrorHandlers
 */
class ErrorLogMcpErrorHandler implements Contracts\McpErrorHandlerInterface {

	/**
	 * Log with context.
	 *
	 * @param string $message The log message.
	 * @param array  $context Additional context data.
	 * @param string $type The type of log (e.g., 'error', 'info', etc.). Default is 'error'.
	 *
	 * @return void
	 */
	public function log( string $message, array $context = array(), string $type = 'error' ): void {
		$user_id     = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;
		$log_message = sprintf(
			'[%s] %s | Context: %s | User ID: %d',
			strtoupper( $type ),
			$message,
			wp_json_encode( $context ),
			$user_id
		);
		error_log( $log_message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}
