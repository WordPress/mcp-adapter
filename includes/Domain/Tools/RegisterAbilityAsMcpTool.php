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

		// get annotations from ability meta.
		$ability_meta = $this->ability->get_meta();
		if ( ! empty( $ability_meta['annotations'] ) ) {
			$tool_data['annotations'] = $ability_meta['annotations'];
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
