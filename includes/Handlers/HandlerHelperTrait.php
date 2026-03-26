<?php
/**
 * Helper trait for MCP handlers.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Handlers;

use WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface;

/**
 * Provides common helper methods for MCP handlers.
 */
trait HandlerHelperTrait {
	/**
	 * Extracts parameters from a request message.
	 *
	 * Handles both direct params and nested params structure for backward compatibility.
	 * This normalizes the dual parameter patterns found throughout handlers.
	 *
	 * @param array $data Request data that may have params at root or nested.
	 *
	 * @return array Extracted parameters.
	 */
	protected function extract_params( array $data ): array {
		return $data['params'] ?? $data;
	}

	/**
	 * Validate that a filtered list value is still an array.
	 *
	 * If a filter callback returns a non-array value, logs a warning
	 * and falls back to the original unfiltered array to prevent
	 * downstream type errors.
	 *
	 * @since n.e.x.t
	 *
	 * @param mixed                    $filtered    The value returned by apply_filters.
	 * @param array                    $original    The original unfiltered array.
	 * @param string                   $filter_name The filter hook name (for logging).
	 * @param \WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface $error_handler The error handler for logging.
	 *
	 * @return array The validated array (filtered if valid, original if not).
	 */
	protected function validate_filtered_list( $filtered, array $original, string $filter_name, McpErrorHandlerInterface $error_handler ): array {
		if ( is_array( $filtered ) ) {
			return $filtered;
		}

		$error_handler->log(
			'Filter returned non-array value, falling back to original list',
			array(
				'filter'        => $filter_name,
				'returned_type' => gettype( $filtered ),
			),
			'warning'
		);

		return $original;
	}
}
