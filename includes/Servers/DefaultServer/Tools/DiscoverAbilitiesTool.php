<?php
/**
 * System tool for discovering available WordPress abilities.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Servers\DefaultServer\Tools;

use WP\MCP\Core\McpServer;
use WP\MCP\Domain\Tools\Contracts\McpSystemToolInterface;
use WP\MCP\Domain\Tools\McpTool;

/**
 * Discover Abilities Tool - Lists all available WordPress abilities in the system.
 *
 * This is a system tool (not backed by an ability) that provides discovery
 * functionality for the MCP protocol. It only discovers ability-based tools,
 * never system tools, preventing infinite loops.
 */
class DiscoverAbilitiesTool implements McpSystemToolInterface {

	/**
	 * Build the MCP tool instance.
	 *
	 * @param \WP\MCP\Core\McpServer $server The MCP server instance.
	 *
	 * @return \WP\MCP\Domain\Tools\McpTool The constructed MCP tool.
	 */
	public function build( McpServer $server ): McpTool {
		return new McpTool(
			'', // No ability backing - pure system tool
			'discover_abilities',
			'Discover all available WordPress abilities in the system. Returns a list of all registered abilities with their basic information.',
			$this->get_input_schema(),
			'Discover Abilities',
			$this->get_output_schema(),
			array()
		);
	}

	/**
	 * Get the input schema for the tool.
	 *
	 * @return array JSON Schema for input parameters.
	 */
	private function get_input_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
		);
	}

	/**
	 * Get the output schema for the tool.
	 *
	 * @return array JSON Schema for output structure.
	 */
	private function get_output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'abilities' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'name'        => array( 'type' => 'string' ),
							'label'       => array( 'type' => 'string' ),
							'description' => array( 'type' => 'string' ),
						),
						'required'   => array( 'name', 'label', 'description' ),
					),
				),
			),
			'required'   => array( 'abilities' ),
		);
	}

	/**
	 * Execute the discover abilities functionality.
	 *
	 * This method is called via the tool execution system and discovers
	 * only ability-based tools, never system tools.
	 *
	 * @param array           $args   Tool execution arguments.
	 * @param \WP\MCP\Core\McpServer $server The MCP server instance.
	 *
	 * @return array Array containing discoverable abilities.
	 */
	public function execute( array $args, McpServer $server ): array {
		return array(
			'abilities' => $server->get_component_registry()->get_discoverable_abilities(),
		);
	}
}
