<?php
/**
 * RegisterAbilityAsMcpPrompt class for converting WordPress abilities to MCP prompts.
 *
 * @package McpAdapter
 */

namespace WP\MCP\Domain\Prompts;

use WP\MCP\Domain\Utils\SchemaTransformer;
use WP\McpSchema\Server\Prompts\Prompt;
use WP\McpSchema\Server\Prompts\PromptArgument;
use WP_Ability;

/**
 * Converts WordPress abilities to MCP prompts according to the specification.
 *
 * This class extracts prompt data from ability properties and converts the JSON Schema
 * input_schema to MCP prompt arguments format.
 *
 * Schema Handling:
 * - Object schemas with properties: Each property becomes a PromptArgument
 * - Flattened schemas (type: string, number, etc.): Wrapped as single argument named "input"
 * - Empty/null schemas: No arguments
 * - Complex schemas (oneOf/anyOf): Treated as no arguments (documented limitation)
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
 *
 * @since n.e.x.t
 */
class RegisterAbilityAsMcpPrompt {
	/**
	 * The WordPress ability instance.
	 *
	 * @var \WP_Ability
	 */
	private WP_Ability $ability;

	/**
	 * Tracks whether input_schema was transformed from flattened to object format.
	 *
	 * @since n.e.x.t
	 *
	 * @var bool
	 */
	private bool $schema_was_transformed = false;

	/**
	 * The wrapper property name used when transforming flattened schemas.
	 *
	 * @since n.e.x.t
	 *
	 * @var string|null
	 */
	private ?string $schema_wrapper_property = null;

	/**
	 * Tracks the source of prompt arguments.
	 *
	 * Possible values:
	 * - 'explicit': Arguments came from ability.meta.mcp.arguments
	 * - 'schema': Arguments were auto-converted from ability.input_schema
	 * - null: No arguments present
	 *
	 * @since n.e.x.t
	 *
	 * @var string|null
	 */
	private ?string $arguments_source = null;

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
	 * Arguments Resolution:
	 * 1. If `ability.meta.mcp.arguments` is defined and non-empty, use it directly (explicit override)
	 * 2. Otherwise, auto-convert from `ability.input_schema`
	 *
	 * This follows the `mcp.*` override pattern used elsewhere (mcp.uri, mcp.icons, mcp.annotations).
	 *
	 * @since n.e.x.t
	 *
	 * @return array<string,mixed>|\WP_Error Prompt data array, or WP_Error if explicit arguments are invalid.
	 */
	private function get_data() {
		$ability_name = trim( $this->ability->get_name() );
		$prompt_data  = array(
			'name' => str_replace( '/', '-', $ability_name ),
		);

		// Add optional title from ability label.
		$label = trim( $this->ability->get_label() );
		if ( ! empty( $label ) ) {
			$prompt_data['title'] = $label;
		}

		// Add optional description.
		$description = trim( $this->ability->get_description() );
		if ( ! empty( $description ) ) {
			$prompt_data['description'] = $description;
		}

		// Check for explicit mcp.arguments override first.
		$explicit_arguments = $this->get_explicit_arguments();
		if ( is_array( $explicit_arguments ) && ! empty( $explicit_arguments ) ) {
			$arguments = $this->convert_explicit_arguments( $explicit_arguments );
			if ( is_wp_error( $arguments ) ) {
				return $arguments;
			}
			if ( ! empty( $arguments ) ) {
				$prompt_data['arguments'] = $arguments;
				$this->arguments_source   = 'explicit';
			}
			return $prompt_data;
		}

		// Fall back to auto-converting from input_schema.
		$input_schema = $this->ability->get_input_schema();
		if ( ! empty( $input_schema ) ) {
			// Use SchemaTransformer to handle flattened schemas (consistent with tool behavior).
			$transform = SchemaTransformer::transform_to_object_schema( $input_schema );

			// Track transformation state for _meta.
			$this->schema_was_transformed  = $transform['was_transformed'];
			$this->schema_wrapper_property = $transform['wrapper_property'];

			$arguments = $this->convert_input_schema_to_arguments( $transform['schema'] );
			if ( ! empty( $arguments ) ) {
				$prompt_data['arguments'] = $arguments;
				$this->arguments_source   = 'schema';
			}
		}

		return $prompt_data;
	}

	/**
	 * Get explicit arguments from ability meta.mcp.arguments.
	 *
	 * @since n.e.x.t
	 *
	 * @return array<int,array<string,mixed>>|null Explicit arguments array or null if not defined.
	 */
	private function get_explicit_arguments(): ?array {
		$meta = $this->ability->get_meta();
		if ( ! isset( $meta['mcp'] ) || ! is_array( $meta['mcp'] ) ) {
			return null;
		}

		$mcp = $meta['mcp'];
		if ( ! isset( $mcp['arguments'] ) || ! is_array( $mcp['arguments'] ) ) {
			return null;
		}

		return $mcp['arguments'];
	}

	/**
	 * Convert and validate explicit arguments from ability.meta.mcp.arguments.
	 *
	 * Per MCP 2025-11-25 specification, PromptArgument has:
	 * - name (string, required): Argument identifier
	 * - title (string, optional): Human-readable display name
	 * - description (string, optional): Human-readable description
	 * - required (boolean, optional): Whether the argument must be provided
	 *
	 * @since n.e.x.t
	 *
	 * @param array<int,array<string,mixed>> $explicit_arguments User-defined arguments array.
	 * @return array<int,\WP\McpSchema\Server\Prompts\PromptArgument>|\WP_Error PromptArgument DTOs or WP_Error.
	 */
	private function convert_explicit_arguments( array $explicit_arguments ) {
		$arguments = array();

		foreach ( $explicit_arguments as $index => $arg ) {
			if ( ! is_array( $arg ) ) {
				return new \WP_Error(
					'mcp_prompt_invalid_argument',
					sprintf(
						/* translators: 1: argument index, 2: ability name */
						__( 'Argument at index %1$d must be an array for ability "%2$s".', 'mcp-adapter' ),
						$index,
						$this->ability->get_name()
					)
				);
			}

			// Validate required 'name' field.
			if ( ! isset( $arg['name'] ) || ! is_string( $arg['name'] ) || '' === trim( $arg['name'] ) ) {
				return new \WP_Error(
					'mcp_prompt_argument_missing_name',
					sprintf(
						/* translators: 1: argument index, 2: ability name */
						__( 'Argument at index %1$d is missing required "name" field for ability "%2$s".', 'mcp-adapter' ),
						$index,
						$this->ability->get_name()
					)
				);
			}

			$argument_data = array(
				'name' => trim( $arg['name'] ),
			);

			// Map optional 'title' field.
			if ( isset( $arg['title'] ) && is_string( $arg['title'] ) && '' !== trim( $arg['title'] ) ) {
				$argument_data['title'] = trim( $arg['title'] );
			}

			// Map optional 'description' field.
			if ( isset( $arg['description'] ) && is_string( $arg['description'] ) && '' !== trim( $arg['description'] ) ) {
				$argument_data['description'] = trim( $arg['description'] );
			}

			// Map optional 'required' field (only emit when true, per existing pattern).
			if ( isset( $arg['required'] ) && true === $arg['required'] ) {
				$argument_data['required'] = true;
			}

			$arguments[] = PromptArgument::fromArray( $argument_data );
		}

		return $arguments;
	}

	/**
	 * Convert JSON Schema input_schema to MCP prompt arguments format.
	 *
	 * Converts from WordPress Abilities JSON Schema format:
	 * {
	 *   "type": "object",
	 *   "properties": {
	 *     "topic": {"type": "string", "title": "Topic", "description": "..."},
	 *     "tone": {"type": "string", "description": "..."}
	 *   },
	 *   "required": ["topic"]
	 * }
	 *
	 * To MCP prompt arguments format:
	 * [
	 *   {"name": "topic", "title": "Topic", "description": "...", "required": true},
	 *   {"name": "tone", "description": "..."}
	 * ]
	 *
	 * Note: `required` is only emitted when true; optional arguments omit the field entirely.
	 *
	 * @since n.e.x.t
	 *
	 * @param array<string,mixed> $input_schema The JSON Schema from ability.
	 * @return array<int, \WP\McpSchema\Server\Prompts\PromptArgument> Argument DTO list.
	 */
	private function convert_input_schema_to_arguments( array $input_schema ): array {
		$arguments = array();

		// Ensure we have properties to convert.
		if ( empty( $input_schema['properties'] ) || ! is_array( $input_schema['properties'] ) ) {
			return $arguments;
		}

		// Get the list of required properties.
		$required_fields = array();
		if ( isset( $input_schema['required'] ) && is_array( $input_schema['required'] ) ) {
			$required_fields = $input_schema['required'];
		}

		// Convert each property to an MCP argument.
		foreach ( $input_schema['properties'] as $property_name => $property_schema ) {
			if ( ! is_array( $property_schema ) ) {
				continue;
			}

			$is_required = in_array( $property_name, $required_fields, true );

			$argument_data = array(
				'name' => $property_name,
			);

			// Map JSON Schema title to PromptArgument.title when present.
			if ( ! empty( $property_schema['title'] ) && is_string( $property_schema['title'] ) ) {
				$argument_data['title'] = $property_schema['title'];
			}

			// Map JSON Schema description to PromptArgument.description when present.
			if ( ! empty( $property_schema['description'] ) ) {
				$argument_data['description'] = (string) $property_schema['description'];
			}

			// Only emit required when true; omit for optional arguments.
			if ( $is_required ) {
				$argument_data['required'] = true;
			}

			$arguments[] = PromptArgument::fromArray( $argument_data );
		}

		return $arguments;
	}

	/**
	 * Get the MCP prompt instance.
	 *
	 * @since n.e.x.t
	 *
	 * @return \WP\McpSchema\Server\Prompts\Prompt|\WP_Error Prompt DTO or WP_Error if validation fails.
	 */
	private function get_prompt() {
		$data = $this->get_data();

		// Propagate WP_Error from argument validation.
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		// Build adapter metadata, tracking transformation when it occurred.
		$adapter_meta = array(
			'ability' => $this->ability->get_name(),
		);

		// Track arguments source when arguments are present.
		if ( null !== $this->arguments_source ) {
			$adapter_meta['arguments_source'] = $this->arguments_source;
		}

		// Record transformation metadata when schema was wrapped (matches tool behavior).
		// Only relevant when arguments_source is 'schema'.
		if ( $this->schema_was_transformed && 'schema' === $this->arguments_source ) {
			$adapter_meta['input_schema_transformed'] = true;
			$adapter_meta['input_schema_wrapper']     = $this->schema_wrapper_property;
		}

		$data['_meta'] = array(
			'mcp_adapter' => $adapter_meta,
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
