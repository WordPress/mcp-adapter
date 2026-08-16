<?php
/**
 * MCP Resource Validator class for validating MCP resources according to the specification.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Domain\Resources;

use WP\MCP\Domain\Utils\McpValidator;
use WP_Error;

/**
 * Validates MCP resources against the Model Context Protocol specification.
 *
 * Provides minimal, resource-efficient validation to ensure resources conform
 * to the MCP schema requirements without heavy processing overhead.
 *
 * @link https://modelcontextprotocol.io/specification/2025-11-25/server/resources
 */
class McpResourceValidator {

	/**
	 * Validate the MCP resource data array against the MCP schema.
	 *
	 * @param array  $resource_data The resource data to validate.
	 * @param string $context Optional context for error messages.
	 *
	 * @return bool|\WP_Error True if valid, WP_Error if validation fails.
	 */
	public static function validate_resource_data( array $resource_data, string $context = '' ) {
		$validation_errors = self::get_validation_errors( $resource_data );

		if ( ! empty( $validation_errors ) ) {
			$error_message  = $context ? "[{$context}] " : '';
			$error_message .= sprintf(
			/* translators: %s: comma-separated list of validation errors */
				__( 'Resource validation failed: %s', 'mcp-adapter' ),
				implode( ', ', $validation_errors )
			);
			return new WP_Error( 'mcp_resource_validation_failed', esc_html( $error_message ) );
		}

		return true;
	}

	/**
	 * Validate resource metadata (the `resources/list` Resource shape) against the MCP schema.
	 *
	 * Validates the metadata object advertised by `resources/list` (uri, icons,
	 * annotations). For resource CONTENTS validation (`resources/read` results),
	 * use get_validation_errors() instead.
	 *
	 * @param array<string, mixed> $resource_data The resource metadata to validate.
	 *
	 * @return bool|\WP_Error True if valid, WP_Error otherwise.
	 * @since n.e.x.t Replaces the DTO-typed validate_resource_dto().
	 */
	public static function validate_resource_metadata( array $resource_data ) {
		$errors = array();

		// Validate URI.
		$uri = $resource_data['uri'] ?? '';
		if ( ! is_string( $uri ) || ! McpValidator::validate_resource_uri( $uri ) ) {
			$errors[] = __( 'Resource URI must be a valid URI string', 'mcp-adapter' );
		}

		// Validate icons if present.
		if ( ! empty( $resource_data['icons'] ) && is_array( $resource_data['icons'] ) ) {
			$icons_result = McpValidator::validate_icons_array( $resource_data['icons'] );
			$icons_errors = self::format_icon_validation_errors( $icons_result );
			$errors       = array_merge( $errors, $icons_errors );
		}

		// Validate annotations if present.
		if ( ! empty( $resource_data['annotations'] ) && is_array( $resource_data['annotations'] ) ) {
			$annotation_errors = McpValidator::get_annotation_validation_errors( $resource_data['annotations'] );
			$errors            = array_merge( $errors, $annotation_errors );
		}

		if ( ! empty( $errors ) ) {
			return new WP_Error(
				'mcp_resource_validation_failed',
				sprintf(
				/* translators: %s: list of validation errors */
					__( 'Resource validation failed: %s', 'mcp-adapter' ),
					implode( '; ', $errors )
				)
			);
		}

		return true;
	}

	/**
	 * Validate an McpResource instance against the MCP schema.
	 *
	 * @param \WP\MCP\Domain\Resources\McpResource $the_resource The resource instance to validate.
	 *
	 * @return bool|\WP_Error True if valid, WP_Error if validation fails.
	 */
	public static function validate_resource_instance( McpResource $the_resource ) {
		return self::validate_resource_metadata( $the_resource->get_protocol_dto() );
	}

	/**
	 * Get validation errors for MCP resource contents.
	 *
	 * NOTE: This validates the `resource` object used by:
	 * - `resources/read` results (`TextResourceContents` / `BlobResourceContents`)
	 * - `content` blocks of type `resource` (`EmbeddedResource.resource`)
	 *
	 * It does NOT validate the `Resource` metadata object returned by `resources/list`.
	 * For resource metadata validation (resources/list), use validate_resource_metadata() instead.
	 *
	 * This validator focuses on the MCP-required fields and ignores unknown fields to remain
	 * forward-compatible with future schema versions.
	 *
	 * @param array $resource_data The resource contents object to validate.
	 *
	 * @return array Array of validation errors, empty if valid.
	 */
	public static function get_validation_errors( array $resource_data ): array {
		$errors = array();

		// Validate the required URI field.
		if ( empty( $resource_data['uri'] ) || ! is_string( $resource_data['uri'] ) ) {
			$errors[] = __( 'Resource URI is required and must be a non-empty string', 'mcp-adapter' );
		} elseif ( ! McpValidator::validate_resource_uri( $resource_data['uri'] ) ) {
			$errors[] = __( 'Resource URI must be a valid URI format', 'mcp-adapter' );
		}

		// Validate content: at least one of text/blob must be present and correctly typed.
		// Use array_key_exists to allow empty strings as valid content.
		$has_text_key = array_key_exists( 'text', $resource_data );
		$has_blob_key = array_key_exists( 'blob', $resource_data );

		$has_text = $has_text_key && is_string( $resource_data['text'] );
		$has_blob = $has_blob_key && is_string( $resource_data['blob'] );

		if ( ! $has_text && ! $has_blob ) {
			$errors[] = __( 'Resource contents must include at least one of: text (string) or blob (base64 string)', 'mcp-adapter' );
		}

		if ( $has_text_key && ! is_string( $resource_data['text'] ) ) {
			$errors[] = __( 'Resource text content must be a string when provided', 'mcp-adapter' );
		}

		if ( $has_blob_key && ! is_string( $resource_data['blob'] ) ) {
			$errors[] = __( 'Resource blob content must be a string when provided', 'mcp-adapter' );
		}

		// Validate blob content if present and typed.
		if ( $has_blob && ! McpValidator::validate_base64( $resource_data['blob'] ) ) {
			$errors[] = __( 'Resource blob content must be valid base64-encoded data', 'mcp-adapter' );
		}

		// mimeType is optional. Only its type is checked.
		if ( isset( $resource_data['mimeType'] ) && ! is_string( $resource_data['mimeType'] ) ) {
			$errors[] = __( 'Resource mimeType must be a string if provided', 'mcp-adapter' );
		}

		return $errors;
	}

	/**
	 * Format icon validation errors from the validation result.
	 *
	 * @param array{valid: array, errors: array} $icons_result The result from validate_icons_array.
	 *
	 * @return array Array of formatted error messages.
	 */
	private static function format_icon_validation_errors( array $icons_result ): array {
		$errors = array();

		if ( ! empty( $icons_result['errors'] ) ) {
			foreach ( $icons_result['errors'] as $error_group ) {
				foreach ( $error_group['errors'] as $error ) {
					$errors[] = sprintf(
					/* translators: 1: icon index, 2: error message */
						__( 'Icon at index %1$d: %2$s', 'mcp-adapter' ),
						$error_group['index'],
						$error
					);
				}
			}
		}

		return $errors;
	}
}
