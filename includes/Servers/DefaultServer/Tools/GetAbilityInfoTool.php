<?php
/**
 * System tool for getting detailed information about a specific WordPress ability.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Servers\DefaultServer\Tools;

use WP\MCP\Core\McpServer;
use WP\MCP\Domain\Tools\Contracts\McpSystemToolInterface;
use WP\MCP\Domain\Tools\McpTool;

/**
 * Get Ability Info Tool - Provides detailed information about a specific WordPress ability.
 *
 * This is a system tool (not backed by an ability) that provides detailed
 * information about abilities including their input/output schema, description,
 * and usage examples.
 */
class GetAbilityInfoTool implements McpSystemToolInterface {

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
			'get_ability_info',
			'Get detailed information about a specific WordPress ability including its input/output schema, description, and usage examples.',
			$this->get_input_schema(),
			'Get Ability Information',
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
			'properties'           => array(
				'ability_name' => array(
					'type'        => 'string',
					'description' => 'The full name of the ability (e.g., "wordpress/get_posts")',
				),
			),
			'required'             => array( 'ability_name' ),
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
				'name'          => array( 'type' => 'string' ),
				'label'         => array( 'type' => 'string' ),
				'description'   => array( 'type' => 'string' ),
				'input_schema'  => array( 'type' => 'object' ),
				'output_schema' => array( 'type' => 'object' ),
				'meta'          => array( 'type' => 'object' ),
			),
			'required'   => array( 'name', 'description', 'input_schema' ),
		);
	}

	/**
	 * Execute the get ability info functionality.
	 *
	 * @param array           $args   Tool execution arguments.
	 * @param \WP\MCP\Core\McpServer $server The MCP server instance.
	 *
	 * @return array Array containing detailed ability information.
	 */
	public function execute( array $args, McpServer $server ): array {
		$ability_name = $args['ability_name'] ?? '';

		if ( empty( $ability_name ) ) {
			return array(
				'error' => array(
					'code'    => -32602,
					'message' => 'Invalid params: ability_name is required',
				),
			);
		}

		$ability = wp_get_ability( $ability_name );

		if ( ! $ability ) {
			return array(
				'error' => array(
					'code'    => -32001,
					'message' => "Ability '{$ability_name}' not found",
				),
			);
		}

		$ability_info = array(
			'name'        => $ability->get_name(),
			'label'       => $ability->get_label(),
			'description' => $ability->get_description(),
		);

		// Get input schema
		$input_schema = $ability->get_input_schema();
		if ( $input_schema ) {
			$ability_info['input_schema'] = $input_schema;
		}

		// Get output schema
		$output_schema = $ability->get_output_schema();
		if ( $output_schema ) {
			$ability_info['output_schema'] = $output_schema;
		}

		// Get meta information
		$meta = $ability->get_meta();
		if ( $meta ) {
			$ability_info['meta'] = $meta;
		}

		return $ability_info;
	}
}
