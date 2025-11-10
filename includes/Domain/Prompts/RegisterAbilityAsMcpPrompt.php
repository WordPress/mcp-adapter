<?php
/**
 * RegisterAbilityAsMcpPrompt class for converting WordPress abilities to MCP prompts.
 *
 * @package McpAdapter
 */

namespace WP\MCP\Domain\Prompts;

use WP\MCP\Core\McpServer;
use WP_Ability;

/**
 * Converts WordPress abilities to MCP prompts according to the specification.
 *
 * This class extracts prompt data from ability properties and converts the JSON Schema
 * input_schema to MCP prompt arguments format.
 *
 * The ability must have an input_schema defined using JSON Schema format, which will
 * be automatically converted to MCP prompt arguments.
 *
 * Example ability registration:
 * wp_register_ability(
 *     'prompts/code-review',
 *     array(
 *         'label' => 'Code Review Prompt',
 *         'description' => 'Generate code review prompt',
 *         'input_schema' => array(
 *             'type' => 'object',
 *             'properties' => array(
 *                 'code' => array('type' => 'string', 'description' => 'Code to review'),
 *             ),
 *             'required' => array('code'),
 *         ),
 *         'meta' => array(
 *             'mcp' => array('public' => true, 'type' => 'prompt'),
 *             'annotations' => array(...)
 *         )
 *     )
 * );
 */
class RegisterAbilityAsMcpPrompt {
	/**
	 * The WordPress ability instance.
	 *
	 * @var \WP_Ability
	 */
	private WP_Ability $ability;

	/**
	 * The MCP server.
	 *
	 * @var \WP\MCP\Core\McpServer
	 */
	private McpServer $mcp_server;

	/**
	 * Make a new instance of the class.
	 *
	 * @param \WP_Ability            $ability    The ability.
	 * @param \WP\MCP\Core\McpServer $mcp_server The MCP server.
	 *
	 * @return \WP\MCP\Domain\Prompts\McpPrompt|\WP_Error Returns prompt instance or WP_Error if validation fails.
	 */
	public static function make( WP_Ability $ability, McpServer $mcp_server ) {
		$prompt = new self( $ability, $mcp_server );

		return $prompt->get_prompt();
	}

	/**
	 * Constructor.
	 *
	 * @param \WP_Ability            $ability    The ability.
	 * @param \WP\MCP\Core\McpServer $mcp_server The MCP server.
	 */
	private function __construct( WP_Ability $ability, McpServer $mcp_server ) {
		$this->mcp_server = $mcp_server;
		$this->ability    = $ability;
	}

	/**
	 * Map WordPress ability annotations to MCP Annotations format.
	 *
	 * Converts annotation fields according to MCP specification:
	 * - audience: array of Role values (e.g., ["user", "assistant"])
	 * - lastModified: ISO 8601 formatted string
	 * - priority: number (1 = most important, 0 = least important)
	 *
	 * Filters out null values and invalid fields.
	 * Only returns MCP-compliant annotation fields.
	 *
	 * @param array $ability_annotations WordPress ability annotations.
	 *
	 * @return array MCP-compliant Annotations.
	 */
	private function map_annotations_to_mcp( array $ability_annotations ): array {
		$valid_mcp_fields = array(
			'audience'     => 'array',
			'lastModified' => 'string',
			'priority'     => 'number',
		);

		$mcp_annotations = array();

		foreach ( $valid_mcp_fields as $field => $field_type ) {
			if ( ! isset( $ability_annotations[ $field ] ) ) {
				continue;
			}

			$value = $ability_annotations[ $field ];

			// Validate and normalize audience field.
			if ( 'audience' === $field ) {
				if ( ! is_array( $value ) ) {
					continue;
				}
				// Filter valid roles and ensure they're strings.
				$valid_roles       = array( 'user', 'assistant' );
				$filtered_audience = array();
				foreach ( $value as $role ) {
					if ( ! is_string( $role ) || ! in_array( $role, $valid_roles, true ) ) {
						continue;
					}
					$filtered_audience[] = $role;
				}
				if ( ! empty( $filtered_audience ) ) {
					$mcp_annotations[ $field ] = $filtered_audience;
				}
				continue;
			}

			// Validate and normalize lastModified field (ISO 8601 string).
			if ( 'lastModified' === $field ) {
				if ( ! is_string( $value ) || empty( trim( $value ) ) ) {
					continue;
				}
				$trimmed_value = trim( $value );
				// Validate ISO 8601 format - filter out invalid dates.
				if ( ! self::is_valid_iso8601_timestamp( $trimmed_value ) ) {
					continue;
				}
				$mcp_annotations[ $field ] = $trimmed_value;
				continue;
			}

			// Validate and normalize priority field (number between 0 and 1).
			if ( 'priority' === $field ) {
				if ( ! is_numeric( $value ) ) {
					continue;
				}
				$priority = (float) $value;
				// Clamp priority between 0 and 1 per MCP spec.
				$priority                  = max( 0.0, min( 1.0, $priority ) );
				$mcp_annotations[ $field ] = $priority;
				continue;
			}
		}

		return $mcp_annotations;
	}

	/**
	 * Check if a string is a valid ISO 8601 timestamp.
	 *
	 * @param string $timestamp The timestamp to validate.
	 *
	 * @return bool True if valid ISO 8601 timestamp, false otherwise.
	 */
	private static function is_valid_iso8601_timestamp( string $timestamp ): bool {
		// Try to parse as DateTime with ISO 8601 format.
		$datetime = \DateTime::createFromFormat( \DateTime::ATOM, $timestamp );
		if ( $datetime && $datetime->format( \DateTime::ATOM ) === $timestamp ) {
			return true;
		}

		// Try alternative ISO 8601 formats.
		$formats = array(
			'Y-m-d\TH:i:s\Z',           // UTC format
			'Y-m-d\TH:i:sP',            // With timezone offset
			'Y-m-d\TH:i:s.u\Z',         // With microseconds UTC
			'Y-m-d\TH:i:s.uP',          // With microseconds and timezone
		);

		foreach ( $formats as $format ) {
			$datetime = \DateTime::createFromFormat( $format, $timestamp );
			if ( $datetime && $datetime->format( $format ) === $timestamp ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get the MCP prompt data array.
	 *
	 * @return array<string,mixed>
	 */
	private function get_data(): array {
		$prompt_data = array(
			'ability' => $this->ability->get_name(),
			'name'    => str_replace( '/', '-', $this->ability->get_name() ),
		);

		// Add optional title from ability label
		$label = $this->ability->get_label();
		if ( ! empty( $label ) ) {
			$prompt_data['title'] = $label;
		}

		// Add optional description
		$description = $this->ability->get_description();
		if ( ! empty( $description ) ) {
			$prompt_data['description'] = $description;
		}

		$input_schema = $this->ability->get_input_schema();
		if ( ! empty( $input_schema ) ) {
			$arguments = $this->convert_input_schema_to_arguments( $input_schema );
			if ( ! empty( $arguments ) ) {
				$prompt_data['arguments'] = $arguments;
			}
		}

		// Map annotations from ability meta to MCP format.
		$ability_meta = $this->ability->get_meta();
		if ( ! empty( $ability_meta['annotations'] ) && is_array( $ability_meta['annotations'] ) ) {
			$mcp_annotations = $this->map_annotations_to_mcp( $ability_meta['annotations'] );
			if ( ! empty( $mcp_annotations ) ) {
				$prompt_data['annotations'] = $mcp_annotations;
			}
		}

		return $prompt_data;
	}

	/**
	 * Convert JSON Schema input_schema to MCP prompt arguments format.
	 *
	 * Converts from WordPress Abilities JSON Schema format:
	 * {
	 *   "type": "object",
	 *   "properties": {
	 *     "topic": {"type": "string", "description": "..."},
	 *     "tone": {"type": "string", "description": "..."}
	 *   },
	 *   "required": ["topic"]
	 * }
	 *
	 * To MCP prompt arguments format:
	 * [
	 *   {"name": "topic", "description": "...", "required": true},
	 *   {"name": "tone", "description": "...", "required": false}
	 * ]
	 *
	 * @param array<string,mixed> $input_schema The JSON Schema from ability.
	 * @return array<int,array<string,mixed>> MCP-formatted arguments array.
	 */
	private function convert_input_schema_to_arguments( array $input_schema ): array {
		$arguments = array();

		// Ensure we have properties to convert
		if ( empty( $input_schema['properties'] ) || ! is_array( $input_schema['properties'] ) ) {
			return $arguments;
		}

		// Get the list of required properties
		$required_fields = array();
		if ( isset( $input_schema['required'] ) && is_array( $input_schema['required'] ) ) {
			$required_fields = $input_schema['required'];
		}

		// Convert each property to an MCP argument
		foreach ( $input_schema['properties'] as $property_name => $property_schema ) {
			if ( ! is_array( $property_schema ) ) {
				continue;
			}

			$argument = array(
				'name'     => $property_name,
				'required' => in_array( $property_name, $required_fields, true ),
			);

			// Add description if available
			if ( ! empty( $property_schema['description'] ) ) {
				$argument['description'] = $property_schema['description'];
			}

			$arguments[] = $argument;
		}

		return $arguments;
	}

	/**
	 * Get the MCP prompt instance.
	 *
	 * @return \WP\MCP\Domain\Prompts\McpPrompt|\WP_Error MCP prompt instance or WP_Error if validation fails.
	 */
	private function get_prompt() {
		return McpPrompt::from_array( $this->get_data(), $this->mcp_server );
	}
}
