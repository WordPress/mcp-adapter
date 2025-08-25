<?php
/**
 * MCP Resource Validator class for validating MCP resources according to the specification.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Domain\Resources;

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
	 * @return void
	 * @throws \InvalidArgumentException If validation fails.
	 */
	public static function validate_resource_data( array $resource_data, string $context = '' ): void {
		$validation_errors = self::get_validation_errors( $resource_data );

		if ( ! empty( $validation_errors ) ) {
			$error_message  = $context ? "[{$context}] " : '';
			$error_message .= sprintf(
			/* translators: %s: comma-separated list of validation errors */
				__( 'Resource validation failed: %s', 'mcp-adapter' ),
				implode( ', ', $validation_errors )
			);
			throw new \InvalidArgumentException( esc_html( $error_message ) );
		}
	}

	/**
	 * Validate an McpResource instance against the MCP schema.
	 *
	 * @param \WP\MCP\Domain\Resources\McpResource $the_resource The resource instance to validate.
	 * @param string      $context Optional context for error messages.
	 *
	 * @return void
	 * @throws \InvalidArgumentException If validation fails.
	 */
	public static function validate_resource_instance( McpResource $the_resource, string $context = '' ): void {
		self::validate_resource_uniqueness( $the_resource, $context );
		self::validate_resource_data( $the_resource->to_array(), $context );
	}

	/**
	 * Validate that the resource is unique within the MCP server.
	 *
	 * @param \WP\MCP\Domain\Resources\McpResource $the_resource The resource instance to validate.
	 * @param string      $context Optional context for error messages.
	 *
	 * @throws \InvalidArgumentException If the resource URI is not unique.
	 */
	public static function validate_resource_uniqueness( McpResource $the_resource, string $context = '' ): void {
		$this_resource_uri = $the_resource->get_uri();
		$existing_resource = $the_resource->get_mcp_server()->get_resource( $this_resource_uri );
		if ( $existing_resource ) {
			$error_message  = $context ? "[{$context}] " : '';
			$error_message .= sprintf(
			/* translators: %s: resource URI */
				__( 'Resource URI \'%s\' is not unique. It already exists in the MCP server.', 'mcp-adapter' ),
				$this_resource_uri
			);
			throw new \InvalidArgumentException( esc_html( $error_message ) );
		}
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
		} elseif ( ! self::validate_resource_uri( $resource_data['uri'] ) ) {
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
			} elseif ( ! self::validate_mime_type( $resource_data['mimeType'] ) ) {
				$errors[] = __( 'Resource mimeType must be a valid MIME type format', 'mcp-adapter' );
			}
		}

		if ( isset( $resource_data['annotations'] ) && ! is_array( $resource_data['annotations'] ) ) {
			$errors[] = __( 'Resource annotations must be an array if provided', 'mcp-adapter' );
		}

		return $errors;
	}

	/**
	 * Check if a resource URI follows valid format according to MCP specification.
	 *
	 * Per MCP spec: "The URI can use any protocol; it is up to the server how to interpret it."
	 * This validates basic URI structure per RFC 3986.
	 *
	 * @param string $uri The URI to validate.
	 *
	 * @return bool True if valid, false otherwise.
	 */
	public static function validate_resource_uri( string $uri ): bool {
		// URI should not be empty.
		if ( empty( $uri ) ) {
			return false;
		}

		// Check reasonable length constraints.
		if ( strlen( $uri ) > 2048 ) {
			return false;
		}

		// Basic URI validation: must have scheme followed by colon (RFC 3986)
		// This accepts any protocol as per MCP specification.
		return (bool) preg_match( '/^[a-zA-Z][a-zA-Z0-9+.-]*:.+/', $uri );
	}

	/**
	 * Validate MIME type format.
	 *
	 * @param string $mime_type The MIME type to validate.
	 *
	 * @return bool True if valid, false otherwise.
	 */
	public static function validate_mime_type( string $mime_type ): bool {
		return (bool) preg_match( '/^[a-zA-Z0-9][a-zA-Z0-9!#$&\-\^_]*\/[a-zA-Z0-9][a-zA-Z0-9!#$&\-\^_]*$/', $mime_type );
	}
}
