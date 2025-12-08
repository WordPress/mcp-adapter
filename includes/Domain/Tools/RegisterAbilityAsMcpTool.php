<?php
/**
 * RegisterAbilityAsMcpTool class for converting WordPress abilities to MCP tools.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Domain\Tools;

use WP\MCP\Core\McpServer;
use WP\MCP\Domain\Utils\McpAnnotationMapper;
use WP\MCP\Domain\Utils\SchemaTransformer;
use WP_Ability;

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
	 * @return \WP\MCP\Domain\Tools\McpTool|\WP_Error Returns a new instance of McpTool or WP_Error if validation fails.
	 */
	public static function make( WP_Ability $ability, McpServer $mcp_server ) {
		$tool = new self( $ability, $mcp_server );

		return $tool->get_tool();
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
	 * Get the MCP tool data array.
	 *
	 * @return array<string,mixed>
	 */
	private function get_data(): array {
		// Transform input schema to MCP-compatible object format
		$input_transform = SchemaTransformer::transform_to_object_schema(
			$this->ability->get_input_schema()
		);

		$tool_data = array(
			'ability'     => $this->ability->get_name(),
			'name'        => str_replace( '/', '-', trim( $this->ability->get_name() ) ),
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
		if ( $input_transform['was_transformed'] || ( $output_transform && $output_transform['was_transformed'] ) ) {
			$tool_data['_metadata'] = array();

			if ( $input_transform['was_transformed'] ) {
				$tool_data['_metadata']['_input_schema_transformed'] = true;
				$tool_data['_metadata']['_input_schema_wrapper']     = $input_transform['wrapper_property'] ?? 'input';
			}

			if ( $output_transform && $output_transform['was_transformed'] ) {
				$tool_data['_metadata']['_output_schema_transformed'] = true;
				$tool_data['_metadata']['_output_schema_wrapper']     = $output_transform['wrapper_property'] ?? 'result';
			}
		}

		return $tool_data;
	}

	/**
	 * Get the MCP tool instance.
	 *
	 * @return \WP\MCP\Domain\Tools\McpTool|\WP_Error The validated MCP tool instance or WP_Error if validation fails.
	 */
	private function get_tool() {
		return McpTool::from_array( $this->get_data(), $this->mcp_server );
	}
}
