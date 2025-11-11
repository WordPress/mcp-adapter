<?php
/**
 * MCP Resource Validator class for validating MCP resources according to the specification.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Domain\Resources;

use WP\MCP\Domain\Utils\McpValidator;

/**
 * Validates MCP resources against the Model Context Protocol specification.
 *
 * Provides minimal, resource-efficient validation to ensure resources conform
 * to the MCP schema requirements without heavy processing overhead.
 *
 * @link https://modelcontextprotocol.io/specification/2025-06-18/server/resources
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
			return new \WP_Error( 'resource_validation_failed', esc_html( $error_message ) );
		}

		return true;
	}

	/**
	 * Validate an McpResource instance against the MCP schema.
	 *
	 * @param \WP\MCP\Domain\Resources\McpResource $the_resource The resource instance to validate.
	 * @param string      $context Optional context for error messages.
	 *
	 * @return bool|\WP_Error True if valid, WP_Error if validation fails.
	 */
	public static function validate_resource_instance( McpResource $the_resource, string $context = '' ) {
		$uniqueness_result = self::validate_resource_uniqueness( $the_resource, $context );
		if ( is_wp_error( $uniqueness_result ) ) {
			return $uniqueness_result;
		}

		return self::validate_resource_data( $the_resource->to_array(), $context );
	}

	/**
	 * Get validation error details for debugging purposes.
	 * This is the core validation method - all other validation methods use this.
	 *
	 * @param array $resource_data The resource data to validate.
	 *
	 * @return array Array of validation errors, empty if valid.
	 */
	public static function get_validation_errors( array $resource_data ): array {
		$errors = array();

		// Sanitize string inputs.
		if ( isset( $resource_data['uri'] ) && is_string( $resource_data['uri'] ) ) {
			$resource_data['uri'] = trim( $resource_data['uri'] );
		}
		if ( isset( $resource_data['name'] ) && is_string( $resource_data['name'] ) ) {
			$resource_data['name'] = trim( $resource_data['name'] );
		}
		if ( isset( $resource_data['description'] ) && is_string( $resource_data['description'] ) ) {
			$resource_data['description'] = trim( $resource_data['description'] );
		}
		if ( isset( $resource_data['mimeType'] ) && is_string( $resource_data['mimeType'] ) ) {
			$resource_data['mimeType'] = trim( $resource_data['mimeType'] );
		}

		// Validate the required URI field.
		if ( empty( $resource_data['uri'] ) || ! is_string( $resource_data['uri'] ) ) {
			$errors[] = __( 'Resource URI is required and must be a non-empty string', 'mcp-adapter' );
		} elseif ( ! McpValidator::validate_resource_uri( $resource_data['uri'] ) ) {
			$errors[] = __( 'Resource URI must be a valid URI format', 'mcp-adapter' );
		}

		// Validate content - must have either text OR blob (but not both).
		$has_text = ! empty( $resource_data['text'] );
		$has_blob = ! empty( $resource_data['blob'] );

		if ( ! $has_text && ! $has_blob ) {
			$errors[] = __( 'Resource must have either text or blob content', 'mcp-adapter' );
		} elseif ( $has_text && $has_blob ) {
			$errors[] = __( 'Resource cannot have both text and blob content - only one is allowed', 'mcp-adapter' );
		}

		// Validate text content if present.
		if ( $has_text && ! is_string( $resource_data['text'] ) ) {
			$errors[] = __( 'Resource text content must be a string', 'mcp-adapter' );
		}

		// Validate blob content if present.
		if ( $has_blob && ! is_string( $resource_data['blob'] ) ) {
			$errors[] = __( 'Resource blob content must be a string (base64-encoded)', 'mcp-adapter' );
		}

		// Validate optional fields if present.
		if ( isset( $resource_data['name'] ) && ! is_string( $resource_data['name'] ) ) {
			$errors[] = __( 'Resource name must be a string if provided', 'mcp-adapter' );
		}

		if ( isset( $resource_data['description'] ) && ! is_string( $resource_data['description'] ) ) {
			$errors[] = __( 'Resource description must be a string if provided', 'mcp-adapter' );
		}

		if ( isset( $resource_data['mimeType'] ) ) {
			if ( ! is_string( $resource_data['mimeType'] ) ) {
				$errors[] = __( 'Resource mimeType must be a string if provided', 'mcp-adapter' );
			} elseif ( ! McpValidator::validate_mime_type( $resource_data['mimeType'] ) ) {
				$errors[] = __( 'Resource mimeType must be a valid MIME type format', 'mcp-adapter' );
			}
		}

		// Validate annotations structure if present.
		if ( isset( $resource_data['annotations'] ) ) {
			if ( ! is_array( $resource_data['annotations'] ) ) {
				$errors[] = __( 'Resource annotations must be an array if provided', 'mcp-adapter' );
			} else {
				$annotation_errors = McpValidator::get_annotation_validation_errors( $resource_data['annotations'] );
				if ( ! empty( $annotation_errors ) ) {
					$errors = array_merge( $errors, $annotation_errors );
				}
			}
		}

		return $errors;
	}

	/**
	 * Validate that the resource is unique within the MCP server.
	 *
	 * @param \WP\MCP\Domain\Resources\McpResource $the_resource The resource instance to validate.
	 * @param string      $context Optional context for error messages.
	 *
	 * @return bool|\WP_Error True if unique, WP_Error if the resource URI is not unique.
	 */
	public static function validate_resource_uniqueness( McpResource $the_resource, string $context = '' ) {
		$this_resource_uri = $the_resource->get_uri();
		$existing_resource = $the_resource->get_mcp_server()->get_resource( $this_resource_uri );
		if ( $existing_resource ) {
			$error_message  = $context ? "[{$context}] " : '';
			$error_message .= sprintf(
			/* translators: %s: resource URI */
				__( 'Resource URI \'%s\' is not unique. It already exists in the MCP server.', 'mcp-adapter' ),
				$this_resource_uri
			);
			return new \WP_Error( 'resource_not_unique', esc_html( $error_message ) );
		}

		return true;
	}
}
