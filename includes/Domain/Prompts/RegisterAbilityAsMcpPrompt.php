<?php

/**
 * RegisterAbilityAsMcpPrompt class for converting WordPress abilities to MCP prompts.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Domain\Prompts;

use WP\MCP\Domain\Utils\McpNameSanitizer;
use WP\MCP\Domain\Utils\McpValidator;
use WP\MCP\Domain\Utils\SchemaTransformer;
use WP_Error;

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
 * @internal
 *
 * @since 0.5.0
 */
class RegisterAbilityAsMcpPrompt {

	/**
	 * The WordPress ability instance.
	 *
	 * @var \WP_Ability
	 */
	private \WP_Ability $ability;

	/**
	 * Constructor.
	 *
	 * @param \WP_Ability $ability The ability.
	 */
	private function __construct( \WP_Ability $ability ) {
		$this->ability = $ability;
	}

	/**
	 * Build neutral Prompt data and adapter metadata for internal wiring.
	 *
	 * This method returns protocol-only data and provides the adapter metadata
	 * separately. Exact validation happens independently for every schema projection.
	 *
	 * @param \WP_Ability $ability The ability.
	 *
	 * @return array{prompt_data: array<string, mixed>, adapter_meta: array<string, mixed>}|\WP_Error
	 * @since 0.5.0
	 */
	public static function build( \WP_Ability $ability ) {
		$prompt = new self( $ability );

		return $prompt->build_prompt_data();
	}

	/**
	 * Build prompt data and adapter metadata.
	 *
	 * Per MCP 2025-11-25 specification, Prompt objects do NOT support annotations at the
	 * template level. Annotations are only supported on content blocks inside prompt messages
	 * (messages[].content.annotations).
	 *
	 * Arguments Resolution:
	 * 1. If `ability.meta.mcp.arguments` is defined and non-empty, use it as given (explicit override)
	 * 2. Otherwise, auto-convert from `ability.input_schema`
	 *
	 * This follows the `mcp.*` override pattern used elsewhere (mcp.uri, mcp.icons, mcp.annotations).
	 * Explicit arguments are not inspected; the schema decides whether each entry fits.
	 *
	 * @return array{prompt_data: array<string, mixed>, adapter_meta: array<string, mixed>}|\WP_Error Prompt data and adapter metadata, or WP_Error if `mcp.arguments` is not an array.
	 * @since 0.5.0
	 */
	private function build_prompt_data() {
		$prompt_name = $this->resolve_prompt_name();
		if ( is_wp_error( $prompt_name ) ) {
			return $prompt_name;
		}

		$prompt_data = array(
			'name' => $prompt_name,
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

		// Check for explicit mcp.arguments override first; otherwise auto-convert
		// from input_schema. Track where the arguments came from and whether a
		// flattened schema was wrapped, for the adapter metadata below.
		$arguments_source = null;
		$transform        = null;

		$explicit_arguments = $this->get_explicit_arguments();
		if ( is_wp_error( $explicit_arguments ) ) {
			return $explicit_arguments;
		}

		if ( ! empty( $explicit_arguments ) ) {
			$prompt_data['arguments'] = $explicit_arguments;
			$arguments_source         = 'explicit';
		} else {
			$input_schema = $this->ability->get_input_schema();
			if ( ! empty( $input_schema ) ) {
				// Use SchemaTransformer to handle flattened schemas (consistent with tool behavior).
				$transform = SchemaTransformer::transform_to_object_schema( $input_schema );
				$arguments = $this->convert_input_schema_to_arguments( $transform['schema'] );
				if ( ! empty( $arguments ) ) {
					$prompt_data['arguments'] = $arguments;
					$arguments_source         = 'schema';
				}
			}
		}

		// Icons from ability.meta.mcp.icons are carried as given; the schema decides
		// whether they fit.
		$ability_meta = $this->ability->get_meta();
		$mcp_meta     = $ability_meta['mcp'] ?? array();
		if ( isset( $mcp_meta['icons'] ) ) {
			$prompt_data['icons'] = $mcp_meta['icons'];
		}

		// Adapter metadata is never included in protocol data.
		$adapter_meta = array(
			'ability' => $this->ability->get_name(),
		);

		if ( null !== $arguments_source ) {
			$adapter_meta['arguments_source'] = $arguments_source;
		}

		// Record transformation metadata when the schema was wrapped (matches tool behavior).
		if ( null !== $transform && $transform['was_transformed'] && 'schema' === $arguments_source ) {
			$adapter_meta['input_schema_transformed'] = true;
			$adapter_meta['input_schema_wrapper']     = $transform['wrapper_property'];
		}

		// User-provided _meta from ability.meta.mcp._meta is carried as given.
		if ( isset( $mcp_meta['_meta'] ) ) {
			$prompt_data['_meta'] = $mcp_meta['_meta'];
		}

		return array(
			'prompt_data'  => $prompt_data,
			'adapter_meta' => $adapter_meta,
		);
	}

	/**
	 * Get explicit arguments from ability meta.mcp.arguments.
	 *
	 * Entries are carried as given and only re-indexed so the list serializes as a
	 * JSON array. A value that is not an array is an error rather than a silent fall
	 * back to input_schema conversion. An explicit null counts as not defined.
	 *
	 * @return list<mixed>|\WP_Error|null Explicit arguments, WP_Error when set to a non-array, or null if not defined.
	 * @since 0.5.0
	 *
	 */
	private function get_explicit_arguments() {
		$meta = $this->ability->get_meta();
		if ( ! isset( $meta['mcp'] ) || ! is_array( $meta['mcp'] ) ) {
			return null;
		}

		$mcp = $meta['mcp'];
		if ( ! isset( $mcp['arguments'] ) ) {
			return null;
		}

		if ( ! is_array( $mcp['arguments'] ) ) {
			return new WP_Error(
				'mcp_prompt_invalid_arguments',
				sprintf(
				/* translators: %s: ability name */
					__( 'Ability meta "mcp.arguments" must be an array for ability "%s".', 'mcp-adapter' ),
					$this->ability->get_name()
				)
			);
		}

		return array_values( $mcp['arguments'] );
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
	 * @param array<string,mixed> $input_schema The JSON Schema from ability.
	 *
	 * @return list<array<string, mixed>> Argument data list.
	 * @since 0.5.0
	 *
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
			if ( ! empty( $property_schema['description'] ) && is_string( $property_schema['description'] ) ) {
				$argument_data['description'] = $property_schema['description'];
			}

			// Only emit required when true; omit for optional arguments.
			if ( $is_required ) {
				$argument_data['required'] = true;
			}

			$arguments[] = $argument_data;
		}

		return $arguments;
	}

	/**
	 * Resolve the MCP prompt name from ability.
	 *
	 * Sanitizes the ability name to MCP-valid format, applies filter, and validates result.
	 *
	 * @since 0.5.0
	 *
	 * @return string|\WP_Error Valid prompt name or error.
	 */
	private function resolve_prompt_name() {
		// Sanitize ability name to MCP-valid format.
		$sanitized_name = McpNameSanitizer::sanitize_name( $this->ability->get_name() );

		if ( is_wp_error( $sanitized_name ) ) {
			return $sanitized_name;
		}

		/**
		 * Filters the MCP prompt name derived from an ability.
		 *
		 * @since 0.5.0
		 *
		 * @param string      $name    The sanitized prompt name.
		 * @param \WP_Ability $ability The source ability instance.
		 */
		$filtered_name = apply_filters( 'mcp_adapter_prompt_name', $sanitized_name, $this->ability );

		// Validate post-filter (in case filter broke it).
		if ( ! is_string( $filtered_name ) || ! McpValidator::validate_name( $filtered_name ) ) {
			return new WP_Error(
				'mcp_prompt_name_filter_invalid',
				sprintf(
					/* translators: %s: invalid prompt name returned by filter */
					__( 'Filter returned invalid MCP prompt name: %s', 'mcp-adapter' ),
					is_string( $filtered_name ) ? $filtered_name : gettype( $filtered_name )
				)
			);
		}

		return $filtered_name;
	}
}
