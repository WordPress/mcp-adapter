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
use WP\McpSchema\Server\Tools\DTO\Tool as ToolDto;
use WP_Error;

/**
 * RegisterAbilityAsMcpTool class.
 *
 * This class registers a WordPress ability as an MCP tool.
 *
 * @internal
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
	 * Constructor.
	 *
	 * @param \WP_Ability $ability The ability.
	 */
	private function __construct( \WP_Ability $ability ) {
		$this->ability = $ability;
	}

	/**
	 * Build a clean Tool DTO and adapter metadata for internal wiring.
	 *
	 * This method returns a protocol-only Tool DTO and provides the adapter metadata
	 * separately. This keeps the DTO stable across MCP spec changes and avoids coupling internal execution
	 * wiring to protocol surfaces.
	 *
	 * @param \WP_Ability $ability The ability.
	 *
	 * @return array{tool: \WP\McpSchema\Server\Tools\DTO\Tool, adapter_meta: array<string, mixed>}|\WP_Error
	 * @since n.e.x.t
	 *
	 */
	public static function build( \WP_Ability $ability ) {
		$tool = new self( $ability );
		$data = $tool->build_tool_data();

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		try {
			$tool_dto = ToolDto::fromArray( $data['tool_data'] );
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'mcp_tool_dto_creation_failed',
				sprintf(
				/* translators: %s: error message */
					__( 'Failed to create Tool DTO for ability %1$s: %2$s', 'mcp-adapter' ),
					$ability->get_name(),
					$e->getMessage()
				),
				array( 'exception' => $e )
			);
		}

		// Optional deep validation if enabled.
		$mcp_validation_enabled = apply_filters( 'mcp_adapter_validation_enabled', false );
		if ( $mcp_validation_enabled ) {
			$validation_result = McpToolValidator::validate_tool_dto( $tool_dto );
			if ( is_wp_error( $validation_result ) ) {
				return $validation_result;
			}
		}

		return array(
			'tool'         => $tool_dto,
			'adapter_meta' => $data['adapter_meta'],
		);
	}

	/**
	 * Build Tool DTO data and adapter metadata.
	 *
	 * @return array{tool_data: array<string, mixed>, adapter_meta: array<string, mixed>}|\WP_Error
	 * @since n.e.x.t
	 *
	 */
	private function build_tool_data() {
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
		// Only record keys when semantically meaningful to keep metadata minimal and accurate.
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

		// Map icons from ability.meta.mcp.icons if present.
		$mcp_meta = $ability_meta['mcp'] ?? array();
		if ( ! empty( $mcp_meta['icons'] ) && is_array( $mcp_meta['icons'] ) ) {
			$icons_result = McpValidator::validate_icons_array( $mcp_meta['icons'] );
			if ( ! empty( $icons_result['valid'] ) ) {
				$tool_data['icons'] = $icons_result['valid'];
			}
		}

		// Build Tool `_meta`:
		// - Preserve user-provided `_meta` from ability.meta.mcp._meta.
		// - Adapter metadata is NEVER included in protocol DTO meta; it is returned separately in adapter_meta.
		$tool_meta = array();
		if ( ! empty( $mcp_meta['_meta'] ) && is_array( $mcp_meta['_meta'] ) ) {
			$tool_meta = $mcp_meta['_meta'];
		}
		if ( ! empty( $tool_meta ) ) {
			$tool_data['_meta'] = $tool_meta;
		}

		return array(
			'tool_data'    => $tool_data,
			'adapter_meta' => $adapter_meta,
		);
	}

	/**
	 * Resolve the MCP tool name from ability.
	 *
	 * Sanitizes the ability name to MCP-valid format, applies filter, and validates result.
	 *
	 * @return string|\WP_Error Valid tool name or error.
	 * @since n.e.x.t
	 *
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
		 * @param string $name The sanitized tool name.
		 * @param \WP_Ability $ability The source ability instance.
		 */
		$filtered_name = apply_filters( 'mcp_adapter_tool_name', $sanitized_name, $this->ability );

		// Validate post-filter (in case filter broke it).
		if ( ! is_string( $filtered_name ) || ! McpValidator::validate_name( $filtered_name ) ) {
			return new WP_Error(
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
}
