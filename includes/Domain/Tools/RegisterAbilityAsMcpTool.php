<?php
/**
 * RegisterAbilityAsMcpTool class for converting WordPress abilities to MCP tools.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Domain\Tools;

use WP\MCP\Core\McpServer;
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
	 * Map WordPress ability annotations to MCP ToolAnnotations format.
	 *
	 * Converts annotation field names from WordPress format to MCP specification:
	 * - readonly → readOnlyHint
	 * - destructive → destructiveHint
	 * - idempotent → idempotentHint
	 *
	 * Filters out null values, WordPress-format fields, and the deprecated 'instructions' field.
	 * Only returns MCP-compliant annotation fields.
	 *
	 * @param array $ability_annotations WordPress ability annotations.
	 *
	 * @return array MCP-compliant ToolAnnotations.
	 */
	private function map_annotations_to_mcp( array $ability_annotations ): array {
		$field_mapping = array(
			'readonly'    => 'readOnlyHint',
			'destructive' => 'destructiveHint',
			'idempotent'  => 'idempotentHint',
		);

		$valid_mcp_fields = array(
			'readOnlyHint'    => 'boolean',
			'destructiveHint' => 'boolean',
			'idempotentHint'  => 'boolean',
			'openWorldHint'   => 'boolean',
			'title'           => 'string',
		);

		$mcp_annotations = $ability_annotations;

		// Convert WordPress-format fields to MCP format and remove old fields.
		foreach ( $field_mapping as $wp_field => $mcp_field ) {
			// If WordPress-format field exists convert it.
			if ( ! isset( $mcp_annotations[ $wp_field ] ) ) {
				continue;
			}
			$mcp_annotations[ $mcp_field ] = (bool) $mcp_annotations[ $wp_field ];
			// Remove the WordPress-format field from the result.
			unset( $mcp_annotations[ $wp_field ] );
		}

		// Filter and normalize valid MCP annotation fields.
		$filtered_annotations = array();
		foreach ( $mcp_annotations as $field => $value ) {
			// Skip if not a valid MCP field.
			if ( ! isset( $valid_mcp_fields[ $field ] ) ) {
				continue;
			}

			$field_type = $valid_mcp_fields[ $field ];

			// Handle boolean hint fields: always include (cast to bool, even if false).
			if ( 'boolean' === $field_type ) {
				$filtered_annotations[ $field ] = (bool) $value;
				continue;
			}

			// Handle string fields: only include if not empty.
			if ( 'string' !== $field_type || empty( $value ) ) {
				continue;
			}
			$filtered_annotations[ $field ] = (string) $value;
		}

		return $filtered_annotations;
	}

	/**
	 * Get the MCP tool data array.
	 *
	 * @return array<string,mixed>
	 */
	private function get_data(): array {
		$input_schema = $this->ability->get_input_schema();

		// If ability has no input schema, use an empty object schema for MCP
		if ( empty( $input_schema ) ) {
			$input_schema = array(
				'type'                 => 'object',
				'additionalProperties' => false,
			);
		}

		$tool_data = array(
			'ability'     => $this->ability->get_name(),
			'name'        => str_replace( '/', '-', $this->ability->get_name() ),
			'description' => $this->ability->get_description(),
			'inputSchema' => $input_schema,
		);

		// Add optional title from ability label.
		$label = $this->ability->get_label();
		if ( ! empty( $label ) ) {
			$tool_data['title'] = $label;
		}

		// Add optional output schema.
		$output_schema = $this->ability->get_output_schema();
		if ( ! empty( $output_schema ) ) {
			$tool_data['outputSchema'] = $output_schema;
		}

		// Map annotations from ability meta to MCP format.
		$ability_meta = $this->ability->get_meta();
		if ( ! empty( $ability_meta['annotations'] ) && is_array( $ability_meta['annotations'] ) ) {
			$mcp_annotations = $this->map_annotations_to_mcp( $ability_meta['annotations'] );
			if ( ! empty( $mcp_annotations ) ) {
				$tool_data['annotations'] = $mcp_annotations;
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
