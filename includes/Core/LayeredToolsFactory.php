<?php

/**
 * Factory for creating layered MCP tools for discovery, planning, and execution.
 *
 * @package McpAdapter
 */

declare(strict_types=1);

namespace WP\MCP\Core;

use WP\MCP\Domain\Tools\McpTool;

/**
 * Factory for creating layered MCP tools.
 *
 * This factory creates the three core layered tools that provide
 * discovery, planning, and execution capabilities for WordPress abilities.
 */
class LayeredToolsFactory {

	/**
	 * Create the discovery layer tool - lists available abilities by category.
	 *
	 * @param \WP\MCP\Core\McpServer $server The MCP server instance.
	 *
	 * @return \WP\MCP\Domain\Tools\McpTool
	 */
	public static function create_discovery_tool( McpServer $server ): McpTool {
		$filterable_data = array(
			'title'        => 'Discover Abilities',
			'description'  => 'Discover all available WordPress abilities in the system. Returns a list of all registered abilities with their basic information.',
			'inputSchema'  => array(
				'type'                 => 'object',
				'additionalProperties' => false,
			),
			'outputSchema' => array(
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
			),
		);

		/**
		 * Filters the discover_abilities tool data before creating the McpTool instance.
		 *
		 * Allows modification of the tool's schema, description, and other properties.
		 * The tool name is not included and cannot be changed.
		 *
		 * @since 1.0.0
		 *
		 * @param array     $filterable_data Tool data array containing title, description, inputSchema, outputSchema, etc. (name is not included).
		 * @param \WP\MCP\Core\McpServer $server          The MCP server instance.
		 */
		$filterable_data = apply_filters( 'mcp_layered_discover_abilities_tool_data', $filterable_data, $server );

		// Add the protected name to the final tool data
		$tool_data = array_merge( array( 'name' => 'discover_abilities' ), $filterable_data );

		return McpTool::from_array( $tool_data, $server );
	}

	/**
	 * Create the planning layer tool - gets detailed info about a specific ability.
	 *
	 * @param \WP\MCP\Core\McpServer $server The MCP server instance.
	 *
	 * @return \WP\MCP\Domain\Tools\McpTool
	 */
	public static function create_planning_tool( McpServer $server ): McpTool {
		$filterable_data = array(
			'title'        => 'Get Ability Information',
			'description'  => 'Get detailed information about a specific WordPress ability including its input/output schema, description, and usage examples.',
			'inputSchema'  => array(
				'type'                 => 'object',
				'properties'           => array(
					'ability_name' => array(
						'type'        => 'string',
						'description' => 'The full name of the ability (e.g., "wordpress/get_posts")',
					),
				),
				'required'             => array( 'ability_name' ),
				'additionalProperties' => false,
			),
			'outputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'name'          => array( 'type' => 'string' ),
					'label'         => array( 'type' => 'string' ),
					'description'   => array( 'type' => 'string' ),
					'input_schema'  => array( 'type' => 'object' ),
					'output_schema' => array( 'type' => 'object' ),
					'meta'          => array( 'type' => 'object' ),
				),
				'required'   => array( 'name', 'description', 'input_schema' ),
			),
		);

		/**
		 * Filters the get_ability_info tool data before creating the McpTool instance.
		 *
		 * Allows modification of the tool's schema, description, and other properties.
		 * The tool name is not included and cannot be changed.
		 *
		 * @since 1.0.0
		 *
		 * @param array     $filterable_data Tool data array containing title, description, inputSchema, outputSchema, etc. (name is not included).
		 * @param \WP\MCP\Core\McpServer $server          The MCP server instance.
		 */
		$filterable_data = apply_filters( 'mcp_layered_get_ability_info_tool_data', $filterable_data, $server );

		// Add the protected name to the final tool data
		$tool_data = array_merge( array( 'name' => 'get_ability_info' ), $filterable_data );

		return McpTool::from_array( $tool_data, $server );
	}

	/**
	 * Create the execution layer tool - executes any WordPress ability.
	 *
	 * @param \WP\MCP\Core\McpServer $server The MCP server instance.
	 *
	 * @return \WP\MCP\Domain\Tools\McpTool
	 */
	public static function create_execution_tool( McpServer $server ): McpTool {
		$filterable_data = array(
			'title'        => 'Execute Ability',
			'description'  => 'Execute a WordPress ability with the provided parameters. This is the primary execution layer that can run any registered ability.',
			'inputSchema'  => array(
				'type'                 => 'object',
				'properties'           => array(
					'ability_name' => array(
						'type'        => 'string',
						'description' => 'The full name of the ability to execute',
					),
					'parameters'   => array(
						'type'        => 'object',
						'description' => 'Parameters to pass to the ability',
					),
				),
				'required'             => array( 'ability_name', 'parameters' ),
				'additionalProperties' => false,
			),
			'outputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'data'    => array(
						'description' => 'The result data from the ability execution',
					),
					'error'   => array(
						'type'        => 'string',
						'description' => 'Error message if execution failed',
					),
				),
				'required'   => array( 'success' ),
			),
		);

		/**
		 * Filters the execute_ability tool data before creating the McpTool instance.
		 *
		 * Allows modification of the tool's schema, description, and other properties.
		 * The tool name is not included and cannot be changed.
		 *
		 * @since 1.0.0
		 *
		 * @param array     $filterable_data Tool data array containing title, description, inputSchema, outputSchema, etc. (name is not included).
		 * @param \WP\MCP\Core\McpServer $server          The MCP server instance.
		 */
		$filterable_data = apply_filters( 'mcp_layered_execute_ability_tool_data', $filterable_data, $server );

		// Add the protected name to the final tool data
		$tool_data = array_merge( array( 'name' => 'execute_ability' ), $filterable_data );

		return McpTool::from_array( $tool_data, $server );
	}
}
