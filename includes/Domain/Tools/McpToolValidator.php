<?php
/**
 * MCP Tool Validator class for validating MCP tools according to the specification.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Domain\Tools;

use stdClass;

/**
 * Validates MCP tools against the Model Context Protocol specification.
 *
 * Provides minimal, resource-efficient validation to ensure tools conform
 * to the MCP schema requirements without heavy processing overhead.
 *
 * @link https://modelcontextprotocol.io/specification/2025-06-18/server/tools
 */
class McpToolValidator {

	/**
	 * Validate the MCP tool data array against the MCP schema.
	 *
	 * @param array  $tool_data The tool data to validate.
	 * @param string $context Optional context for error messages.
	 *
	 * @return bool|\WP_Error True if valid, WP_Error if validation fails.
	 */
	public static function validate_tool_data( array $tool_data, string $context = '' ) {
		$validation_errors = self::get_validation_errors( $tool_data );

		if ( ! empty( $validation_errors ) ) {
			$error_message  = $context ? "[{$context}] " : '';
			$error_message .= sprintf(
			/* translators: %s: comma-separated list of validation errors */
				__( 'Tool validation failed: %s', 'mcp-adapter' ),
				implode( ', ', $validation_errors )
			);
			return new \WP_Error( 'tool_validation_failed', esc_html( $error_message ) );
		}

		return true;
	}

	/**
	 * Validate an McpTool instance against the MCP schema.
	 *
	 * @param \WP\MCP\Domain\Tools\McpTool $tool The tool instance to validate.
	 * @param string  $context Optional context for error messages.
	 *
	 * @return bool|\WP_Error True if valid, WP_Error if validation fails.
	 */
	public static function validate_tool_instance( McpTool $tool, string $context = '' ) {
		$uniqueness_result = self::validate_tool_uniqueness( $tool, $context );
		if ( is_wp_error( $uniqueness_result ) ) {
			return $uniqueness_result;
		}

		return self::validate_tool_data( $tool->to_array(), $context );
	}

	/**
	 * Get validation error details for debugging purposes.
	 * This is the core validation method - all other validation methods use this.
	 *
	 * @param array $tool_data The tool data to validate.
	 *
	 * @return array Array of validation errors, empty if valid.
	 */
	public static function get_validation_errors( array $tool_data ): array {
		$errors = array();

		// Sanitize string inputs.
		if ( isset( $tool_data['name'] ) && is_string( $tool_data['name'] ) ) {
			$tool_data['name'] = trim( $tool_data['name'] );
		}
		if ( isset( $tool_data['description'] ) && is_string( $tool_data['description'] ) ) {
			$tool_data['description'] = trim( $tool_data['description'] );
		}
		if ( isset( $tool_data['title'] ) && is_string( $tool_data['title'] ) ) {
			$tool_data['title'] = trim( $tool_data['title'] );
		}

		// Check the required fields.
		if ( empty( $tool_data['name'] ) || ! is_string( $tool_data['name'] ) || ! self::validate_tool_name( $tool_data['name'] ) ) {
			$errors[] = __( 'Tool name is required and must only contain letters, numbers, hyphens (-), and underscores (_), and be 255 characters or less', 'mcp-adapter' );
		}

		if ( empty( $tool_data['description'] ) || ! is_string( $tool_data['description'] ) ) {
			$errors[] = __( 'Tool description is required and must be a non-empty string', 'mcp-adapter' );
		}

		// Validate inputSchema (required field).
		$input_schema_errors = self::get_schema_validation_errors( $tool_data['inputSchema'] ?? null, 'inputSchema' );
		if ( ! empty( $input_schema_errors ) ) {
			$errors = array_merge( $errors, $input_schema_errors );
		}

		// Check optional fields if present.
		if ( isset( $tool_data['title'] ) && ! is_string( $tool_data['title'] ) ) {
			$errors[] = __( 'Tool title must be a string if provided', 'mcp-adapter' );
		}

		// Validate outputSchema (optional field).
		if ( isset( $tool_data['outputSchema'] ) ) {
			$output_schema_errors = self::get_schema_validation_errors( $tool_data['outputSchema'], 'outputSchema' );
			if ( ! empty( $output_schema_errors ) ) {
				$errors = array_merge( $errors, $output_schema_errors );
			}
		}

		if ( isset( $tool_data['annotations'] ) && ! is_array( $tool_data['annotations'] ) ) {
			$errors[] = __( 'Tool annotations must be an array if provided', 'mcp-adapter' );
		}

		return $errors;
	}

	/**
	 * Get detailed validation errors for a schema object.
	 *
	 * @param array|mixed $schema The schema to validate.
	 * @param string      $field_name The name of the field being validated (for error messages).
	 *
	 * @return array Array of validation errors, empty if valid.
	 */
	private static function get_schema_validation_errors( $schema, string $field_name ): array {
		// Normalize stdClass to array for validation, and reject scalars/null.
		if ( $schema instanceof stdClass ) {
			$schema = (array) $schema;
		}

		// Schema must be an array/object - early return for performance.
		if ( ! is_array( $schema ) ) {
			return array(
				sprintf(
				/* translators: %s: field name (inputSchema or outputSchema) */
					__( 'Tool %s must be a valid JSON schema object', 'mcp-adapter' ),
					$field_name
				),
			);
		}

		$errors = array();

		// Input schemas commonly describe an object of arguments. Allow omitted type (empty schema) or type 'object'.
		// For output schemas, do not enforce a specific type; any valid JSON Schema is acceptable per MCP.
		if ( 'inputSchema' === $field_name && isset( $schema['type'] ) && 'object' !== $schema['type'] ) {
			$errors[] = sprintf(
			/* translators: %s: field name */
				__( 'Tool %s, if specifying a type, must use type \'object\'', 'mcp-adapter' ),
				$field_name
			);
		}

		// If properties exist, they must be an array/object.
		if ( isset( $schema['properties'] ) && ! is_array( $schema['properties'] ) ) {
			$errors[] = sprintf(
			/* translators: %s: field name */
				__( 'Tool %s properties must be an object/array', 'mcp-adapter' ),
				$field_name
			);
		}

		// If required exists, it must be an array.
		if ( isset( $schema['required'] ) && ! is_array( $schema['required'] ) ) {
			$errors[] = sprintf(
			/* translators: %s: field name */
				__( 'Tool %s required field must be an array', 'mcp-adapter' ),
				$field_name
			);
		}

		// If properties are provided, validate their basic structure.
		if ( isset( $schema['properties'] ) && is_array( $schema['properties'] ) ) {
			foreach ( $schema['properties'] as $property_name => $property ) {
				if ( ! is_array( $property ) ) {
					$errors[] = sprintf(
					/* translators: %1$s: field name, %2$s: property name */
						__( 'Tool %1$s property \'%2$s\' must be an object', 'mcp-adapter' ),
						$field_name,
						$property_name
					);
					continue;
				}

				// Each property should have a type (though not strictly required by JSON Schema).
				if ( ! isset( $property['type'] ) || is_string( $property['type'] ) || is_array( $property['type'] ) ) {
					continue;
				}

				// If type is neither string nor array, it's invalid.
				$errors[] = sprintf(
				/* translators: %1$s: field name, %2$s: property name */
					__( 'Tool %1$s property \'%2$s\' type must be a string or array of strings (union type)', 'mcp-adapter' ),
					$field_name,
					$property_name
				);
			}
		}

		// If required array is provided, validate its structure.
		if ( isset( $schema['required'] ) && is_array( $schema['required'] ) ) {
			foreach ( $schema['required'] as $required_field ) {
				if ( ! is_string( $required_field ) ) {
					$errors[] = sprintf(
					/* translators: %s: field name */
						__( 'Tool %s required field names must be strings', 'mcp-adapter' ),
						$field_name
					);
					continue;
				}

				// Check that required fields exist in properties (if properties are defined).
				if ( ! isset( $schema['properties'] ) || isset( $schema['properties'][ $required_field ] ) ) {
					continue;
				}

				$errors[] = sprintf(
				/* translators: %1$s: field name, %2$s: required field */
					__( 'Tool %1$s required field \'%2$s\' does not exist in properties', 'mcp-adapter' ),
					$field_name,
					$required_field
				);
			}
		}

		return $errors;
	}

	/**
	 * Check if tool data is valid without throwing exceptions.
	 *
	 * @param array $tool_data The tool data to validate.
	 *
	 * @return bool True if valid, false otherwise.
	 */
	public static function is_valid_tool_data( array $tool_data ): bool {
		return empty( self::get_validation_errors( $tool_data ) );
	}

	/**
	 * Check if a tool name follows MCP naming conventions.
	 *
	 * @param string $name The tool name to validate.
	 *
	 * @return bool True if valid, false otherwise.
	 */
	public static function validate_tool_name( string $name ): bool {
		// Tool names should not be empty.
		if ( empty( $name ) ) {
			return false;
		}

		// Check length constraints (reasonable limits).
		if ( strlen( $name ) > 255 ) {
			return false;
		}

		// Only allow letters, numbers, hyphens, and underscores.
		return (bool) preg_match( '/^[a-zA-Z0-9_-]+$/', $name );
	}

	/**
	 * Validate that the tool name is unique within the server.
	 *
	 * @param \WP\MCP\Domain\Tools\McpTool $tool The tool instance to validate.
	 * @param string  $context Optional context for error messages.
	 *
	 * @return bool|\WP_Error True if unique, WP_Error if the tool name is not unique.
	 */
	public static function validate_tool_uniqueness( McpTool $tool, string $context = '' ) {
		$this_tool_name = $tool->get_name();
		$server         = $tool->get_mcp_server();
		$existing_tool  = $server->get_tool( $this_tool_name );

		// Check if a tool with this name already exists.
		if ( $existing_tool ) {
			$error_message  = $context ? "[{$context}] " : '';
			$error_message .= sprintf(
			/* translators: %1$s: tool name, %2$s: server ID */
				__( 'Tool name \'%1$s\' is not unique. A tool with this name already exists on server \'%2$s\'.', 'mcp-adapter' ),
				$this_tool_name,
				$server->get_server_id()
			);
			return new \WP_Error( 'tool_not_unique', esc_html( $error_message ) );
		}

		return true;
	}
}
