<?php
/**
 * RegisterAbilityAsMcpPrompt class for converting WordPress abilities to MCP prompts.
 *
 * @package McpAdapter
 */

namespace WP\MCP\Domain\Prompts;

use WP\MCP\Core\McpServer;
use WP\MCP\Domain\Utils\McpAnnotationMapper;
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
	 * Get the MCP prompt data array.
	 *
	 * @return array<string,mixed>
	 */
	private function get_data(): array {
		$ability_name = trim( $this->ability->get_name() );
		$prompt_data  = array(
			'ability' => $ability_name,
			'name'    => str_replace( '/', '-', $ability_name ),
		);

		// Add optional title from ability label
		$label = trim( $this->ability->get_label() );
		if ( ! empty( $label ) ) {
			$prompt_data['title'] = $label;
		}

		// Add optional description
		$description = trim( $this->ability->get_description() );
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

		// Map annotations from ability meta to MCP format using unified mapper.
		$ability_meta = $this->ability->get_meta();
		if ( ! empty( $ability_meta['annotations'] ) && is_array( $ability_meta['annotations'] ) ) {
			$mcp_annotations = McpAnnotationMapper::map( $ability_meta['annotations'], 'prompt' );
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
