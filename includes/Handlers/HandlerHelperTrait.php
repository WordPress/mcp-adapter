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
	 * Content blocks carry the shared annotations vocabulary (`audience`, `priority`,
	 * `lastModified`), not the tool-hint vocabulary that belongs on a tool descriptor.
	 * Anything a conforming client would reject is dropped here, because annotations are
	 * validated as part of the block that carries them, so a bad annotation costs the
	 * whole block rather than only itself. Two shapes have to be kept off the wire: a
	 * value outside what the schema allows, and an annotations object with nothing left
	 * in it, which PHP serializes as a JSON array where MCP declares an object.
	 *
	 * Dropping is logged rather than raised. Annotations are a rendering hint, and
	 * failing the whole response over one would be a worse outcome for a payload that is
	 * otherwise valid.
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
			return $annotations;
		}

		if ( ! is_array( $annotations ) ) {
			return null;
		}

		// The mapper is the single seam where raw annotation input becomes DTO-ready: it
		// keeps only the fields the shared Annotations type models, and coerces each to the
		// type that type asserts. Both matter here. Vocabulary it drops - most likely the
		// tool hints, which belong on the descriptor - would otherwise leave an all-null DTO
		// that serializes to `[]`. And a loosely typed but valid value, such as the string
		// "0.5" WordPress hands back from post meta, would otherwise be rejected by the
		// DTO's strict float assertion.
		$mapped = McpAnnotationMapper::map( $annotations, 'resource' );
		if ( empty( $mapped ) ) {
			return null;
		}

		// Handler output is untrusted, and a value the schema rejects costs the whole
		// content block rather than just the annotation. Validate before building the DTO.
		$errors = McpValidator::get_annotation_validation_errors( $mapped );
		if ( ! empty( $errors ) ) {
			$error_handler->log(
				$log_message,
				array_merge( $log_context, array( 'errors' => $errors ) ),
				'warning'
			);

			return null;
		}

		// Mapping and validation between them leave nothing for fromArray() to reject.
		return Annotations::fromArray( $mapped );
	}

	/**
	 * Normalize a `_meta` value from handler output, logging when one is dropped.
	 *
	 * {@see McpValidator::normalize_meta()} answers null both for a `_meta` that was never
	 * written and for one that could not serialize as a JSON object. The two deserve
	 * different treatment: the first is the ordinary case, while the second means an author
	 * wrote metadata that will not reach the client. Passing the raw value separates them
	 * without a guard at each call site, because an absent key arrives here as null.
	 *
	 * Dropping is logged rather than raised, matching {@see self::build_content_annotations()}:
	 * `_meta` travels alongside a payload, and withholding the payload over its metadata
	 * would be the worse outcome. A client gives no signal either, since a conforming one
	 * strips metadata it does not recognize, so this log is the only place the mistake
	 * surfaces.
	 *
	 * @since n.e.x.t
	 *
	 * @param mixed                                                                  $meta          The raw `_meta` value.
	 * @param \WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface $error_handler The error handler for logging.
	 * @param string                                                                 $log_message   Message to log when `_meta` is dropped.
	 * @param array                                                                  $log_context   Context to log alongside the message.
	 *
	 * @return array<array-key, mixed>|null The normalized `_meta`, or null when there is nothing conformant to emit.
	 */
	protected function normalize_content_meta(
		$meta,
		McpErrorHandlerInterface $error_handler,
		string $log_message,
		array $log_context = array()
	): ?array {
		$normalized = McpValidator::normalize_meta( $meta );

		if ( null === $normalized && null !== $meta ) {
			$error_handler->log( $log_message, $log_context, 'warning' );
		}

		return $normalized;
	}
}
