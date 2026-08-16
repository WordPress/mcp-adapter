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
	 * @since 0.5.0
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

	/**
	 * Encodes each component of a list, dropping the ones that do not validate.
	 *
	 * One malformed component must never cost a site its whole catalog, so a
	 * component that fails to encode is left out and the rest are returned. The
	 * omission is logged and reported through _doing_it_wrong by the encoder.
	 * MCP has no partial-result signal, so a client cannot be told that
	 * something was dropped; those records are the only trace.
	 *
	 * @param array<int, array<string, mixed>> $components The components to encode.
	 * @param string                           $id_key     Key holding the component identity, for logs.
	 * @param callable                         $encode_one Encoder method for one component.
	 *
	 * @return array<int, array<string, mixed>> The components that encoded successfully.
	 */
	protected function encode_components( array $components, string $id_key, callable $encode_one ): array {
		$encoded = array();

		foreach ( $components as $component ) {
			if ( ! is_array( $component ) ) {
				continue;
			}

			$subject = isset( $component[ $id_key ] ) && is_scalar( $component[ $id_key ] )
				? (string) $component[ $id_key ]
				: '';

			$wire = $encode_one( $component, $subject );
			if ( ! is_array( $wire ) ) {
				continue;
			}

			$encoded[] = $wire;
		}

		return $encoded;
	}
}
