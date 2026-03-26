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
use WP\McpSchema\Server\Prompts\DTO\Prompt as PromptDto;
use WP\McpSchema\Server\Prompts\DTO\PromptArgument;
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
 * @since n.e.x.t
 */
class RegisterAbilityAsMcpPrompt {

	/**
	 * The WordPress ability instance.
	 *
	 * @var \WP_Ability
	 */
	private \WP_Ability $ability;

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
	 * Constructor.
	 *
	 * @param \WP_Ability $ability The ability.
	 */
	private function __construct( \WP_Ability $ability ) {
		$this->ability = $ability;
	}

	/**
	 * Make a new instance of the class.
	 *
	 * @param \WP_Ability $ability The ability.
	 *
	 * @return \WP\McpSchema\Server\Prompts\DTO\Prompt|\WP_Error Returns Prompt DTO or WP_Error if validation fails.
	 */
	public static function make( \WP_Ability $ability ) {
		$prompt = new self( $ability );

		return $prompt->get_prompt();
	}

	/**
	 * Get the MCP prompt instance.
	 *
	 * @return \WP\McpSchema\Server\Prompts\DTO\Prompt|\WP_Error Prompt DTO or WP_Error if validation fails.
	 * @since n.e.x.t
	 *
	 */
	private function get_prompt() {
		$built = $this->build_prompt_data();

		// Propagate WP_Error from argument validation.
		if ( is_wp_error( $built ) ) {
			return $built;
		}

		try {
			return PromptDto::fromArray( $built['prompt_data'] );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'mcp_prompt_schema_invalid', $e->getMessage() );
		}
	}

	/**
	 * Build Prompt DTO data and adapter metadata.
	 *
	 * @return array{prompt_data: array<string, mixed>, adapter_meta: array<string, mixed>}|\WP_Error
	 * @since n.e.x.t
	 *
	 */
	private function build_prompt_data() {
		$data = $this->get_data();

		// Propagate WP_Error from argument validation.
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		// Get ability meta for icons and user _meta extraction.
		$ability_meta = $this->ability->get_meta();
		$mcp_meta     = $ability_meta['mcp'] ?? array();

		// Map icons from ability.meta.mcp.icons if present.
		// Uses same pattern as tools/resources for consistency.
		if ( ! empty( $mcp_meta['icons'] ) && is_array( $mcp_meta['icons'] ) ) {
			$icons_result = McpValidator::validate_icons_array( $mcp_meta['icons'] );
			if ( ! empty( $icons_result['valid'] ) ) {
				$data['icons'] = $icons_result['valid'];
			}
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

		// Preserve user-provided _meta from ability.meta.mcp._meta.
		$prompt_meta = array();
		if ( ! empty( $mcp_meta['_meta'] ) && is_array( $mcp_meta['_meta'] ) ) {
			$prompt_meta = $mcp_meta['_meta'];
		}
		if ( ! empty( $prompt_meta ) ) {
			$data['_meta'] = $prompt_meta;
		}

		return array(
			'prompt_data'  => $data,
			'adapter_meta' => $adapter_meta,
		);
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
	 * @return array<string,mixed>|\WP_Error Prompt data array, or WP_Error if explicit arguments are invalid.
	 * @since n.e.x.t
	 *
	 */
	private function get_data() {
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
	 * @return list<array<string,mixed>>|null Explicit arguments array or null if not defined.
	 * @since n.e.x.t
	 *
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

		return array_values( $mcp['arguments'] );
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
	 * @param list<array<string,mixed>> $explicit_arguments User-defined arguments array.
	 *
	 * @return list<\WP\McpSchema\Server\Prompts\DTO\PromptArgument>|\WP_Error PromptArgument DTOs or WP_Error.
	 * @since n.e.x.t
	 *
	 */
	private function convert_explicit_arguments( array $explicit_arguments ) {
		$arguments = array();

		foreach ( $explicit_arguments as $index => $arg ) {
			if ( ! is_array( $arg ) ) {
				return new WP_Error(
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
				return new WP_Error(
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
	 * @param array<string,mixed> $input_schema The JSON Schema from ability.
	 *
	 * @return list<\WP\McpSchema\Server\Prompts\DTO\PromptArgument> Argument DTO list.
	 * @since n.e.x.t
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

			$arguments[] = PromptArgument::fromArray( $argument_data );
		}

		return $arguments;
	}

	/**
	 * Resolve the MCP prompt name from ability.
	 *
	 * Sanitizes the ability name to MCP-valid format, applies filter, and validates result.
	 *
	 * @since n.e.x.t
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
		 * @since n.e.x.t
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

	/**
	 * Build a clean Prompt DTO and adapter metadata for internal wiring.
	 *
	 * This method returns a protocol-only Prompt DTO and provides the adapter metadata
	 * separately. This keeps the DTO stable across MCP spec changes and avoids coupling internal execution
	 * wiring to protocol surfaces.
	 *
	 * @param \WP_Ability $ability The ability.
	 *
	 * @return array{prompt: \WP\McpSchema\Server\Prompts\DTO\Prompt, adapter_meta: array<string, mixed>}|\WP_Error
	 * @since n.e.x.t
	 *
	 */
	public static function build( \WP_Ability $ability ) {
		$prompt = new self( $ability );
		$data   = $prompt->build_prompt_data();

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		try {
			$prompt_dto = PromptDto::fromArray( $data['prompt_data'] );
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'mcp_prompt_dto_creation_failed',
				sprintf(
				/* translators: %s: error message */
					__( 'Failed to create Prompt DTO for ability %1$s: %2$s', 'mcp-adapter' ),
					$ability->get_name(),
					$e->getMessage()
				),
				array( 'exception' => $e )
			);
		}

		// Optional deep validation if enabled.
		$mcp_validation_enabled = apply_filters( 'mcp_adapter_validation_enabled', false );
		if ( $mcp_validation_enabled ) {
			$validation_result = McpPromptValidator::validate_prompt_dto( $prompt_dto );
			if ( is_wp_error( $validation_result ) ) {
				return $validation_result;
			}
		}

		return array(
			'prompt'       => $prompt_dto,
			'adapter_meta' => $data['adapter_meta'],
		);
	}
}
