<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Fixtures;

/**
 * Mock MCP server for testing client functionality.
 * Simulates various MCP server responses for testing.
 */
class MockMcpServer {

	/**
	 * Generate a mock initialize response.
	 *
	 * @return array Mock initialize response.
	 */
	public static function get_initialize_response(): array {
		return array(
			'protocolVersion' => '2024-11-05',
			'capabilities' => array(
				'tools' => array(),
				'prompts' => array(),
			),
			'serverInfo' => array(
				'name' => 'Mock Test Server',
				'version' => '1.0.0',
			),
		);
	}

	/**
	 * Generate a mock tools list response.
	 *
	 * @return array Mock tools response.
	 */
	public static function get_tools_response(): array {
		return array(
			'tools' => array(
				array(
					'name' => 'testTool',
					'description' => 'A test tool for unit testing',
					'inputSchema' => array(
						'type' => 'object',
						'properties' => array(
							'input' => array(
								'type' => 'string',
								'description' => 'Test input parameter',
							),
						),
						'required' => array( 'input' ),
					),
				),
				array(
					'name' => 'anotherTestTool',
					'description' => 'Another test tool with different schema',
					'inputSchema' => array(
						'type' => 'object',
						'properties' => array(
							'count' => array(
								'type' => 'integer',
								'default' => 5,
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Generate a mock resources list response.
	 *
	 * @return array Mock resources response.
	 */
	public static function get_resources_response(): array {
		return array(
			'resources' => array(
				array(
					'uri' => 'test://resource-1',
					'description' => 'First test resource',
					'mimeType' => 'application/json',
				),
				array(
					'uri' => 'test://resource-2', 
					'description' => 'Second test resource',
					'mimeType' => 'text/plain',
				),
			),
		);
	}

	/**
	 * Generate a mock prompts list response.
	 *
	 * @return array Mock prompts response.
	 */
	public static function get_prompts_response(): array {
		return array(
			'prompts' => array(
				array(
					'name' => 'test-prompt',
					'description' => 'A test prompt for unit testing',
					'arguments' => array(
						array(
							'name' => 'topic',
							'description' => 'Topic for the prompt',
							'required' => true,
						),
					),
				),
			),
		);
	}

	/**
	 * Generate mock tool call response.
	 *
	 * @param string $tool_name Tool that was called.
	 * @param array  $arguments Arguments passed to tool.
	 * @return array Mock tool response.
	 */
	public static function get_tool_call_response( string $tool_name, array $arguments ): array {
		return array(
			'content' => array(
				array(
					'type' => 'text',
					'text' => "Mock response for {$tool_name} with args: " . wp_json_encode( $arguments ),
				),
			),
		);
	}
}