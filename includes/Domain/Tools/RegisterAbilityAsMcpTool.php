<?php
/**
 * RegisterAbilityAsMcpTool class for converting WordPress abilities to MCP tools.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Domain\Tools;

use WP\MCP\Domain\Utils\McpAnnotationMapper;
use WP\MCP\Domain\Utils\McpNameSanitizer;
use WP\MCP\Domain\Utils\McpValidator;
use WP\MCP\Domain\Utils\SchemaTransformer;
use WP\McpSchema\Server\Tools\Tool;

/**
 * RegisterAbilityAsMcpTool class.
 *
 * This class registers a WordPress ability as an MCP tool.
 *
 * @package McpAdapter
 */
class RegisterAbilityAsMcpTool {
	/**
	 * The WordPress ability instance.
	 *
	 * @var \WP_Ability
	 */
	private \WP_Ability $ability;

	/**
	 * Make a new instance of the class.
	 *
	 * @param \WP_Ability $ability The ability.
	 *
	 * @return \WP\McpSchema\Server\Tools\Tool|\WP_Error Returns a Tool DTO or WP_Error if validation fails.
	 */
	public static function make( \WP_Ability $ability ) {
		$tool = new self( $ability );

		return $tool->get_tool();
	}

	/**
	 * Constructor.
	 *
	 * @param \WP_Ability $ability The ability.
	 */
	private function __construct( \WP_Ability $ability ) {
		$this->ability = $ability;
	}

	/**
	 * Resolve the MCP tool name from ability.
	 *
	 * Sanitizes the ability name to MCP-valid format, applies filter, and validates result.
	 *
	 * @since n.e.x.t
	 *
	 * @return string|\WP_Error Valid tool name or error.
	 */
	private function resolve_tool_name() {
		// Sanitize ability name to MCP-valid format.
		$sanitized_name = McpNameSanitizer::sanitize_name( $this->ability->get_name() );

		if ( is_wp_error( $sanitized_name ) ) {
			return $sanitized_name;
		}

		/**
		 * Filters the MCP tool name derived from an ability.
		 *
		 * @since n.e.x.t
		 *
		 * @param string      $name    The sanitized tool name.
		 * @param \WP_Ability $ability The source ability instance.
		 */
		$filtered_name = apply_filters( 'mcp_adapter_tool_name', $sanitized_name, $this->ability );

		// Validate post-filter (in case filter broke it).
		if ( ! is_string( $filtered_name ) || ! McpValidator::validate_tool_or_prompt_name( $filtered_name ) ) {
			return new \WP_Error(
				'mcp_tool_name_filter_invalid',
				sprintf(
					/* translators: %s: invalid tool name returned by filter */
					__( 'Filter returned invalid MCP tool name: %s', 'mcp-adapter' ),
					is_string( $filtered_name ) ? $filtered_name : gettype( $filtered_name )
				)
			);
		}

		return $filtered_name;
	}

	/**
	 * Get the MCP tool data array.
	 *
	 * @since n.e.x.t
	 *
	 * @return array<string,mixed>|\WP_Error Tool data array or error if name resolution fails.
	 */
	private function get_data() {
		// Resolve tool name first (can fail).
		$tool_name = $this->resolve_tool_name();
		if ( is_wp_error( $tool_name ) ) {
			return $tool_name;
		}

		// Transform input schema to MCP-compatible object format.
		$input_transform = SchemaTransformer::transform_to_object_schema(
			$this->ability->get_input_schema()
		);

		$tool_data = array(
			'name'        => $tool_name,
			'description' => trim( $this->ability->get_description() ),
			'inputSchema' => $input_transform['schema'],
		);

		// Add optional title from ability label.
		$label = $this->ability->get_label();
		$label = trim( $label );
		if ( ! empty( $label ) ) {
			$tool_data['title'] = $label;
		}

		// Add optional output schema, transformed to object format if needed.
		$output_schema    = $this->ability->get_output_schema();
		$output_transform = null;
		if ( ! empty( $output_schema ) ) {
			$output_transform          = SchemaTransformer::transform_to_object_schema(
				$output_schema,
				'result'
			);
			$tool_data['outputSchema'] = $output_transform['schema'];
		}

		// Map annotations from ability meta to MCP format using unified mapper.
		$ability_meta = $this->ability->get_meta();
		if ( ! empty( $ability_meta['annotations'] ) && is_array( $ability_meta['annotations'] ) ) {
			$mcp_annotations = McpAnnotationMapper::map( $ability_meta['annotations'], 'tool' );
			if ( ! empty( $mcp_annotations ) ) {
				$tool_data['annotations'] = $mcp_annotations;
			}
		}

		// Set annotations.title from label if annotations exist but don't have a title.
		if ( ! empty( $label ) && isset( $tool_data['annotations'] ) && ! isset( $tool_data['annotations']['title'] ) ) {
			$tool_data['annotations']['title'] = $label;
		}

		// Store transformation metadata as internal metadata (stripped before responding to clients).
		// Only record keys when semantically meaningful to keep _meta minimal and accurate.
		$adapter_meta = array(
			'ability' => $this->ability->get_name(),
		);

		// Only record input transformation metadata when a wrapper was actually applied.
		if ( ! empty( $input_transform['was_transformed'] ) ) {
			$adapter_meta['input_schema_transformed'] = true;
			$adapter_meta['input_schema_wrapper']     = $input_transform['wrapper_property'];
		}

		// Only record output transformation metadata when outputSchema exists.
		// Record wrapper only when transformation actually occurred.
		if ( null !== $output_transform && ! empty( $output_transform['was_transformed'] ) ) {
			$adapter_meta['output_schema_transformed'] = true;
			$adapter_meta['output_schema_wrapper']     = $output_transform['wrapper_property'];
		}

		$tool_data['_meta'] = array( 'mcp_adapter' => $adapter_meta );

		// Map icons from ability.meta.mcp.icons if present.
		$mcp_meta = $ability_meta['mcp'] ?? array();
		if ( ! empty( $mcp_meta['icons'] ) && is_array( $mcp_meta['icons'] ) ) {
			$icons_result = McpValidator::validate_icons_array( $mcp_meta['icons'] );
			if ( ! empty( $icons_result['valid'] ) ) {
				$tool_data['icons'] = $icons_result['valid'];
			}
		}

		// Merge user-provided _meta from ability.meta.mcp._meta.
		// User _meta keys are preserved alongside adapter's internal 'mcp_adapter' key.
		// MetaStripper will strip 'mcp_adapter' but preserve user keys when responding to clients.
		if ( ! empty( $mcp_meta['_meta'] ) && is_array( $mcp_meta['_meta'] ) ) {
			$tool_data['_meta'] = array_merge( $mcp_meta['_meta'], $tool_data['_meta'] );
		}

		// Prepare schema arrays for php-mcp-schema DTO expectations (properties map values must be objects).
		$tool_data['inputSchema'] = $this->prepare_schema_for_dto( $tool_data['inputSchema'] );
		if ( isset( $tool_data['outputSchema'] ) && is_array( $tool_data['outputSchema'] ) ) {
			$tool_data['outputSchema'] = $this->prepare_schema_for_dto( $tool_data['outputSchema'] );
		}

		return $tool_data;
	}

	/**
	 * Get the MCP Tool DTO instance.
	 *
	 * @since n.e.x.t
	 *
	 * @return \WP\McpSchema\Server\Tools\Tool|\WP_Error The Tool DTO instance or WP_Error if validation fails.
	 */
	private function get_tool() {
		$data = $this->get_data();
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		try {
			return Tool::fromArray( $data );
		} catch ( \Throwable $e ) {
			return new \WP_Error(
				'mcp_tool_schema_invalid',
				$e->getMessage()
			);
		}
	}

	/**
	 * Prepare a JSON Schema array for DTO conversion.
	 *
	 * The php-mcp-schema library expects JSON Schema `properties` values to be objects, not arrays.
	 * This method recursively converts nested schema arrays into stdClass objects where needed.
	 *
	 * @param array $schema The JSON schema array.
	 *
	 * @return array The schema with properties values converted to objects.
	 */
	private function prepare_schema_for_dto( array $schema ): array {
		if ( isset( $schema['properties'] ) && is_array( $schema['properties'] ) ) {
			$converted_properties = array();
			foreach ( $schema['properties'] as $prop_name => $prop_schema ) {
				if ( is_array( $prop_schema ) ) {
					$prop_schema = $this->prepare_schema_for_dto( $prop_schema );
				}
				$converted_properties[ $prop_name ] = (object) $prop_schema;
			}
			$schema['properties'] = $converted_properties;
		}

		return $schema;
	}
}
