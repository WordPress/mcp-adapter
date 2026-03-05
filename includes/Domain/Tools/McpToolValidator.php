<?php
/**
 * MCP Tool Validator class for validating MCP tools according to the specification.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Domain\Tools;

use WP\MCP\Domain\Utils\McpValidator;
use WP\McpSchema\Server\Tools\DTO\Tool as ToolDto;
use WP_Error;

/**
 * Validates MCP tools against the Model Context Protocol specification.
 *
 * Provides minimal, resource-efficient validation to ensure tools conform
 * to the MCP schema requirements without heavy processing overhead.
 *
 * @link https://modelcontextprotocol.io/specification/2025-11-25/server/tools
 */
class McpToolValidator {

	/**
	 * Valid task support values for tool execution.
	 *
	 * @since n.e.x.t
	 *
	 * @var array<string>
	 */
	private static array $valid_task_support_values = array(
		'forbidden',
		'optional',
		'required',
	);

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
			$error_message  = $context ? "[$context] " : '';
			$error_message .= sprintf(
			/* translators: %s: comma-separated list of validation errors */
				__( 'Tool validation failed: %s', 'mcp-adapter' ),
				implode( ', ', $validation_errors )
			);
			return new WP_Error( 'mcp_tool_validation_failed', esc_html( $error_message ) );
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
		return self::validate_tool_data( $tool->get_protocol_dto()->toArray(), $context );
	}

	/**
	 * Validate a Tool DTO against the MCP schema.
	 *
	 * @param \WP\McpSchema\Server\Tools\DTO\Tool $tool The tool DTO to validate.
	 *
	 * @return bool|\WP_Error True if valid, WP_Error otherwise.
	 */
	public static function validate_tool_dto( ToolDto $tool ) {
		$errors = array();

		// Validate name (required, 1-128 chars, alphanumeric + _.-).
		if ( ! McpValidator::validate_name( $tool->getName() ) ) {
			$errors[] = __( 'Tool name must be 1-128 characters and contain only [A-Za-z0-9_.-]', 'mcp-adapter' );
		}

		// Validate icons if present.
		$icons = $tool->getIcons();
		if ( ! empty( $icons ) ) {
			// Convert DTO icons to arrays for validation.
			$icons_array  = array_map( static fn( $icon ) => $icon->toArray(), $icons );
			$icons_result = McpValidator::validate_icons_array( $icons_array );
			$icons_errors = self::format_icon_validation_errors( $icons_result );
			$errors       = array_merge( $errors, $icons_errors );
		}

		// Validate annotations if present (tool-specific only).
		$annotations = $tool->getAnnotations();
		if ( $annotations ) {
			$annotations_array = $annotations->toArray();
			$annotation_errors = self::get_tool_annotation_validation_errors( $annotations_array );
			$errors            = array_merge( $errors, $annotation_errors );
		}

		// Validate execution if present.
		$execution = $tool->getExecution();
		if ( $execution ) {
			$execution_array  = $execution->toArray();
			$execution_errors = self::get_execution_validation_errors( $execution_array );
			$errors           = array_merge( $errors, $execution_errors );
		}

		// Validate schemas (inputSchema and outputSchema).
		$tool_array = $tool->toArray();

		// Validate inputSchema (required field).
		$input_schema_errors = self::get_schema_validation_errors(
			$tool_array['inputSchema'] ?? null,
			'inputSchema'
		);
		$errors              = array_merge( $errors, $input_schema_errors );

		// Validate outputSchema if present (optional field).
		if ( isset( $tool_array['outputSchema'] ) ) {
			$output_schema_errors = self::get_schema_validation_errors(
				$tool_array['outputSchema'],
				'outputSchema'
			);
			$errors               = array_merge( $errors, $output_schema_errors );
		}

		if ( ! empty( $errors ) ) {
			return new WP_Error(
				'mcp_tool_validation_failed',
				sprintf(
				/* translators: %s: list of validation errors */
					__( 'Tool validation failed: %s', 'mcp-adapter' ),
					implode( '; ', $errors )
				)
			);
		}

		return true;
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

		// Check the required field: name.
		if ( empty( $tool_data['name'] ) || ! is_string( $tool_data['name'] ) || ! McpValidator::validate_name( $tool_data['name'] ) ) {
			$errors[] = __( 'Tool name is required and must only contain letters, numbers, hyphens (-), underscores (_), and dots (.), and be 128 characters or less', 'mcp-adapter' );
		}

		// Description is optional per MCP 2025-11-25 spec, but validate if present.
		if ( isset( $tool_data['description'] ) && ! is_string( $tool_data['description'] ) ) {
			$errors[] = __( 'Tool description must be a string if provided', 'mcp-adapter' );
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

		// Validate icons (optional field, new in 2025-11-25).
		if ( isset( $tool_data['icons'] ) ) {
			$icons_errors = self::get_icons_validation_errors( $tool_data['icons'] );
			if ( ! empty( $icons_errors ) ) {
				$errors = array_merge( $errors, $icons_errors );
			}
		}

		// Validate execution (optional field, new in 2025-11-25).
		if ( isset( $tool_data['execution'] ) ) {
			$execution_errors = self::get_execution_validation_errors( $tool_data['execution'] );
			if ( ! empty( $execution_errors ) ) {
				$errors = array_merge( $errors, $execution_errors );
			}
		}

		// Validate annotations structure if present (tool-specific annotations only).
		if ( isset( $tool_data['annotations'] ) ) {
			if ( ! is_array( $tool_data['annotations'] ) ) {
				$errors[] = __( 'Tool annotations must be an array if provided', 'mcp-adapter' );
			} else {
				// Validate tool-specific annotations (readOnlyHint, destructiveHint, etc.).
				$tool_annotation_errors = self::get_tool_annotation_validation_errors( $tool_data['annotations'] );
				if ( ! empty( $tool_annotation_errors ) ) {
					$errors = array_merge( $errors, $tool_annotation_errors );
				}
			}
		}

		// Validate _meta (optional field).
		if ( isset( $tool_data['_meta'] ) && ! is_array( $tool_data['_meta'] ) ) {
			$errors[] = __( 'Tool _meta must be an object/array if provided', 'mcp-adapter' );
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
		// Normalize stdClass to array for validation and reject scalars/null.
		if ( $schema instanceof \stdClass ) {
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

		// MCP Tool inputSchema and outputSchema are currently restricted to a root type of "object".
		if ( ! isset( $schema['type'] ) ) {
			$errors[] = sprintf(
			/* translators: %s: field name */
				__( 'Tool %s must specify a root type of \'object\'', 'mcp-adapter' ),
				$field_name
			);
		} elseif ( ! is_string( $schema['type'] ) || 'object' !== $schema['type'] ) {
			$errors[] = sprintf(
			/* translators: %s: field name */
				__( 'Tool %s root type must be \'object\'', 'mcp-adapter' ),
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
				// Normalize stdClass to array for property validation.
				if ( $property instanceof \stdClass ) {
					$property = (array) $property;
				}

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

				// If the type is neither string nor array, it's invalid.
				$errors[] = sprintf(
				/* translators: %1$s: field name, %2$s: property name */
					__( 'Tool %1$s property \'%2$s\' type must be a string or array of strings (union type)', 'mcp-adapter' ),
					$field_name,
					$property_name
				);
			}
		}

		// If the required array is provided, validate its structure.
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
	 * Get validation errors for tool icons array.
	 *
	 * @param mixed $icons The icons data to validate.
	 *
	 * @return array Array of validation errors, empty if valid.
	 */
	private static function get_icons_validation_errors( $icons ): array {
		if ( ! is_array( $icons ) ) {
			return array( __( 'Tool icons must be an array if provided', 'mcp-adapter' ) );
		}

		$icons_result = McpValidator::validate_icons_array( $icons, false );

		return self::format_icon_validation_errors( $icons_result );
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

	/**
	 * Get validation errors for tool execution properties.
	 *
	 * Validates execution-related properties per MCP 2025-11-25 specification:
	 * - taskSupport must be one of: "forbidden", "optional", "required"
	 *
	 * @param mixed $execution The execution data to validate.
	 *
	 * @return array Array of validation errors, empty if valid.
	 */
	public static function get_execution_validation_errors( $execution ): array {
		if ( ! is_array( $execution ) ) {
			return array( __( 'Tool execution must be an object/array if provided', 'mcp-adapter' ) );
		}

		$errors = array();

		// Validate taskSupport if present.
		if ( isset( $execution['taskSupport'] ) ) {
			if ( ! is_string( $execution['taskSupport'] ) ) {
				$errors[] = __( 'Tool execution taskSupport must be a string', 'mcp-adapter' );
			} elseif ( ! in_array( $execution['taskSupport'], self::$valid_task_support_values, true ) ) {
				$errors[] = sprintf(
				/* translators: %s: comma-separated list of valid values */
					__( 'Tool execution taskSupport must be one of: %s', 'mcp-adapter' ),
					implode( ', ', self::$valid_task_support_values )
				);
			}
		}

		return $errors;
	}

	/**
	 * Get validation errors for tool-specific MCP annotations.
	 *
	 * Validates tool annotation fields per MCP 2025-11-25 specification:
	 * - readOnlyHint, destructiveHint, idempotentHint, openWorldHint must be booleans
	 * - title must be a non-empty string
	 *
	 * Note: Tools use ToolAnnotations which is different from the shared Annotations class.
	 * ToolAnnotations does NOT include audience, lastModified, or priority fields.
	 *
	 * @param array $annotations The annotations to validate.
	 *
	 * @return array Array of validation errors, empty if valid.
	 */
	public static function get_tool_annotation_validation_errors( array $annotations ): array {
		$errors = array();

		foreach ( $annotations as $field => $value ) {
			switch ( $field ) {
				case 'readOnlyHint':
				case 'destructiveHint':
				case 'idempotentHint':
				case 'openWorldHint':
					if ( ! is_bool( $value ) ) {
						$errors[] = sprintf(
						/* translators: %s: annotation field name */
							__( 'Tool annotation field %s must be a boolean', 'mcp-adapter' ),
							$field
						);
					}
					break;

				case 'title':
					if ( ! is_string( $value ) ) {
						$errors[] = sprintf(
						/* translators: %s: annotation field name */
							__( 'Tool annotation field %s must be a string', 'mcp-adapter' ),
							$field
						);
						break;
					}
					if ( empty( trim( $value ) ) ) {
						$errors[] = sprintf(
						/* translators: %s: annotation field name */
							__( 'Tool annotation field %s must be a non-empty string', 'mcp-adapter' ),
							$field
						);
					}
					break;

				default:
					// Unknown fields are ignored to allow forward compatibility.
					break;
			}
		}

		return $errors;
	}
}
