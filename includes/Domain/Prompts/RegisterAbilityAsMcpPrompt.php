<?php
/**
 * RegisterAbilityAsMcpPrompt class for converting WordPress abilities to MCP prompts.
 *
 * @package McpAdapter
 */

namespace WP\MCP\Domain\Prompts;

use WP\McpSchema\Server\Prompts\Prompt;
use WP\McpSchema\Server\Prompts\PromptArgument;
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
	 * Make a new instance of the class.
	 *
	 * @param \WP_Ability $ability The ability.
	 *
	 * @return \WP\McpSchema\Server\Prompts\Prompt|\WP_Error Returns Prompt DTO or WP_Error if validation fails.
	 */
	public static function make( WP_Ability $ability ) {
		$prompt = new self( $ability );

		return $prompt->get_prompt();
	}

	/**
	 * Constructor.
	 *
	 * @param \WP_Ability $ability The ability.
	 */
	private function __construct( WP_Ability $ability ) {
		$this->ability = $ability;
	}

	/**
	 * Get the MCP prompt data array.
	 *
	 * Per MCP 2025-11-25 specification, Prompt objects do NOT support annotations at the
	 * template level. Annotations are only supported on content blocks inside prompt messages
	 * (messages[].content.annotations).
	 *
	 * @return array<string,mixed>
	 */
	private function get_data(): array {
		$ability_name = trim( $this->ability->get_name() );
		$prompt_data  = array(
			'name' => str_replace( '/', '-', $ability_name ),
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
	 * @return array<int, \WP\McpSchema\Server\Prompts\PromptArgument> Argument DTO list.
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

		// Convert each property to an MCP argument.
		foreach ( $input_schema['properties'] as $property_name => $property_schema ) {
			if ( ! is_array( $property_schema ) ) {
				continue;
			}

			$arguments[] = PromptArgument::fromArray(
				array(
					'name'        => $property_name,
					'title'       => null,
					'description' => ! empty( $property_schema['description'] ) ? (string) $property_schema['description'] : null,
					'required'    => in_array( $property_name, $required_fields, true ),
				)
			);
		}

		return $arguments;
	}

	/**
	 * Get the MCP prompt instance.
	 *
	 * @return \WP\McpSchema\Server\Prompts\Prompt|\WP_Error Prompt DTO or WP_Error if validation fails.
	 */
	private function get_prompt() {
		$data          = $this->get_data();
		$data['_meta'] = array(
			'mcp_adapter' => array(
				'ability' => $this->ability->get_name(),
			),
		);

		try {
			return Prompt::fromArray( $data );
		} catch ( \Throwable $e ) {
			return new \WP_Error(
				'mcp_prompt_schema_invalid',
				$e->getMessage()
			);
		}
	}
}
