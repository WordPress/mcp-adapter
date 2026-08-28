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
	 * Convert validated JSON arguments at the WordPress callback boundary.
	 *
	 * @param \stdClass|null $arguments Validated argument object.
	 * @return array<string, mixed> Associative callback parameters.
	 */
	protected function callback_arguments( ?\stdClass $arguments ): array {
		$value = $this->callback_value( $arguments ?? new \stdClass() );

		return is_array( $value ) ? $value : array();
	}

	/**
	 * Convert one validated JSON value immediately before a WordPress callback.
	 *
	 * @param mixed $value Validated JSON-compatible value.
	 * @return mixed
	 */
	protected function callback_value( $value ) {
		if ( $value instanceof \JsonSerializable ) {
			return $this->callback_value( $value->jsonSerialize() );
		}
		if ( $value instanceof \stdClass ) {
			$result = array();
			foreach ( get_object_vars( $value ) as $key => $item ) {
				$result[ $key ] = $this->callback_value( $item );
			}

			return $result;
		}
		if ( is_array( $value ) ) {
			return array_map( array( $this, 'callback_value' ), $value );
		}

		return $value;
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
}
