<?php
/**
 * Helper trait for MCP handlers.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Handlers;

use WP\MCP\Domain\Utils\McpAnnotationMapper;
use WP\MCP\Domain\Utils\McpValidator;
use WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface;
use WP\McpSchema\Common\Protocol\DTO\Annotations;

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
	 * Build an Annotations DTO for a content block from raw handler output.
	 *
	 * Invalid optional annotations are logged and omitted so they do not cost the block.
	 *
	 * @since n.e.x.t
	 *
	 * @param mixed                    $annotations   The raw annotations value.
	 * @param \WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface $error_handler The error handler for logging.
	 * @param string                   $log_message   Message to log when annotations are dropped.
	 * @param array                    $log_context   Context to log alongside the validation errors.
	 *
	 * @return \WP\McpSchema\Common\Protocol\DTO\Annotations|null The DTO, or null when there is nothing conformant to emit.
	 */
	protected function build_content_annotations(
		$annotations,
		McpErrorHandlerInterface $error_handler,
		string $log_message,
		array $log_context = array()
	): ?Annotations {
		if ( $annotations instanceof Annotations ) {
			$annotations = $annotations->toArray();
		}

		if ( ! is_array( $annotations ) ) {
			if ( null !== $annotations ) {
				$error_handler->log(
					$log_message,
					array_merge( $log_context, array( 'reason' => 'annotations must be an array or Annotations DTO' ) ),
					'warning'
				);
			}

			return null;
		}

		$mapped = McpAnnotationMapper::map( $annotations, 'resource' );
		if ( empty( $mapped ) ) {
			if ( ! empty( $annotations ) ) {
				$error_handler->log(
					$log_message,
					array_merge( $log_context, array( 'reason' => 'no usable content annotation fields remained after mapping' ) ),
					'warning'
				);
			}

			return null;
		}

		$errors = McpValidator::get_annotation_validation_errors( $mapped );
		if ( ! empty( $errors ) ) {
			$error_handler->log(
				$log_message,
				array_merge( $log_context, array( 'errors' => $errors ) ),
				'warning'
			);

			return null;
		}

		return Annotations::fromArray( $mapped );
	}
}
