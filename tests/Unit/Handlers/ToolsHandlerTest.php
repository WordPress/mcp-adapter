<?php
/**
 * Tests for ToolsHandler class.
 *
 * @package WP\MCP\Tests
 */

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Handlers;

use WP\MCP\Handlers\Tools\ToolsHandler;
use WP\MCP\Tests\TestCase;
use WP\McpSchema\Common\JsonRpc\JSONRPCErrorResponse;
use WP\McpSchema\Server\Tools\CallToolResult;
use WP\McpSchema\Server\Tools\ListToolsResult;

/**
 * Test ToolsHandler functionality.
 */
final class ToolsHandlerTest extends TestCase {

	public function test_list_tools_returns_dto(): void {
		wp_set_current_user( 1 );
		$server  = $this->makeServer( array( 'test/always-allowed' ), array(), array() );
		$handler = new ToolsHandler( $server );
		$result  = $handler->list_tools();

		$this->assertInstanceOf( ListToolsResult::class, $result );
	}

	public function test_list_tools_returns_registered_tools(): void {
		wp_set_current_user( 1 );
		$server  = $this->makeServer( array( 'test/always-allowed' ), array(), array() );
		$handler = new ToolsHandler( $server );
		$result  = $handler->list_tools();
		$res     = $result->toArray();

		$this->assertArrayHasKey( 'tools', $res );
		$this->assertNotEmpty( $res['tools'] );
		// Note: _metadata is no longer returned by handler (DTOs don't have internal metadata)
	}

	public function test_list_tools_returns_empty_array_when_no_tools(): void {
		$server  = $this->makeServer( array(), array(), array() );
		$handler = new ToolsHandler( $server );
		$result  = $handler->list_tools();
		$res     = $result->toArray();

		$this->assertArrayHasKey( 'tools', $res );
		$this->assertEmpty( $res['tools'] );
	}

	public function test_list_all_tools_returns_dto(): void {
		wp_set_current_user( 1 );
		$server  = $this->makeServer( array( 'test/always-allowed' ), array(), array() );
		$handler = new ToolsHandler( $server );
		$result  = $handler->list_all_tools();

		$this->assertInstanceOf( ListToolsResult::class, $result );
		$res = $result->toArray();
		$this->assertArrayHasKey( 'tools', $res );
		$this->assertNotEmpty( $res['tools'] );
		// Note: The 'available' flag is no longer included (was non-standard extension)
	}

	public function test_call_tool_missing_name_returns_error(): void {
		$server  = $this->makeServer( array( 'test/always-allowed' ), array(), array() );
		$handler = new ToolsHandler( $server );
		$result  = $handler->call_tool( array( 'params' => array() ) );

		// Missing name is a protocol error - returns JSONRPCErrorResponse
		$this->assertInstanceOf( JSONRPCErrorResponse::class, $result );
		$res = $result->toArray();
		$this->assertArrayHasKey( 'error', $res );
	}

	public function test_call_tool_not_found_returns_error(): void {
		$server  = $this->makeServer( array( 'test/always-allowed' ), array(), array() );
		$handler = new ToolsHandler( $server );
		$result  = $handler->call_tool(
			array(
				'params' => array(
					'name' => 'nonexistent-tool',
				),
			)
		);

		// Tool not found is a protocol error - returns JSONRPCErrorResponse
		$this->assertInstanceOf( JSONRPCErrorResponse::class, $result );
		$res = $result->toArray();
		$this->assertArrayHasKey( 'error', $res );
	}

	public function test_call_tool_with_wp_error_from_get_ability(): void {
		wp_set_current_user( 1 );

		// Create a tool with a non-existent ability name
		$server = $this->makeServer( array(), array(), array() );
		$tool   = new \WP\MCP\Domain\Tools\McpTool(
			'nonexistent/ability',
			'test-nonexistent-tool',
			'Test Tool',
			array( 'type' => 'object' )
		);
		$tool->set_mcp_server( $server );
		$server->get_component_registry()->add_tool( $tool );

		$handler = new ToolsHandler( $server );

		$result = $handler->call_tool(
			array(
				'params' => array(
					'name' => 'test-nonexistent-tool',
				),
			)
		);

		// Ability retrieval failure is a protocol error - returns JSONRPCErrorResponse
		$this->assertInstanceOf( JSONRPCErrorResponse::class, $result );
		$res = $result->toArray();
		$this->assertArrayHasKey( 'error', $res );
	}

	public function test_call_tool_with_wp_error_from_execute(): void {
		wp_set_current_user( 1 );

		// Register an ability that returns WP_Error
		$this->register_ability_in_hook(
			'test/wp-error-execute',
			array(
				'label'               => 'WP Error Execute',
				'description'         => 'Returns WP_Error from execute',
				'category'            => 'test',
				'input_schema'        => array( 'type' => 'object' ),
				'execute_callback'    => static function () {
					return new \WP_Error( 'test_error', 'Test error message' );
				},
				'permission_callback' => static function () {
					return true;
				},
				'meta'                => array(
					'mcp' => array(
						'public' => true,
					),
				),
			)
		);

		$server  = $this->makeServer( array( 'test/wp-error-execute' ), array(), array() );
		$handler = new ToolsHandler( $server );

		$result = $handler->call_tool(
			array(
				'params' => array(
					'name' => 'test-wp-error-execute',
				),
			)
		);

		// WP_Error from execute is a tool execution error - returns CallToolResult with isError
		$this->assertInstanceOf( CallToolResult::class, $result );
		$res = $result->toArray();
		$this->assertArrayHasKey( 'isError', $res );
		$this->assertTrue( $res['isError'] );
		$this->assertArrayHasKey( 'content', $res );

		// Clean up
		wp_unregister_ability( 'test/wp-error-execute' );
	}

	public function test_call_tool_with_exception_in_handler(): void {
		wp_set_current_user( 1 );

		// Register an ability that throws exception during permission check
		$this->register_ability_in_hook(
			'test/permission-exception-in-call',
			array(
				'label'               => 'Permission Exception',
				'description'         => 'Throws exception in permission',
				'category'            => 'test',
				'input_schema'        => array( 'type' => 'object' ),
				'execute_callback'    => static function () {
					return array( 'result' => 'success' );
				},
				'permission_callback' => static function () {
					throw new \RuntimeException( 'Permission check exception' );
				},
				'meta'                => array(
					'mcp' => array(
						'public' => true,
					),
				),
			)
		);

		$server  = $this->makeServer( array( 'test/permission-exception-in-call' ), array(), array() );
		$handler = new ToolsHandler( $server );

		$result = $handler->call_tool(
			array(
				'params' => array(
					'name' => 'test-permission-exception-in-call',
				),
			)
		);

		// Permission exception is a tool execution error - returns CallToolResult with isError
		$this->assertInstanceOf( CallToolResult::class, $result );
		$res = $result->toArray();
		$this->assertArrayHasKey( 'isError', $res );
		$this->assertTrue( $res['isError'] );
		$this->assertArrayHasKey( 'content', $res );

		// Clean up
		wp_unregister_ability( 'test/permission-exception-in-call' );
	}

	// Note: Permission denied, execution errors, and exceptions are tested
	// using existing test abilities in DummyAbility
	// Exception handling in call_tool() outer try-catch is covered by exception tests
	// in handle_tool_call() which propagate properly

	public function test_call_tool_success_returns_content(): void {
		wp_set_current_user( 1 );

		$server  = $this->makeServer( array( 'test/always-allowed' ), array(), array() );
		$handler = new ToolsHandler( $server );

		$result = $handler->call_tool(
			array(
				'params' => array(
					'name'      => 'test-always-allowed',
					'arguments' => array( 'input' => 'test data' ),
				),
			)
		);

		// Successful execution returns CallToolResult
		$this->assertInstanceOf( CallToolResult::class, $result );
		$res = $result->toArray();
		$this->assertArrayHasKey( 'content', $res );
		// Note: _metadata is no longer returned by handler DTOs
	}

	public function test_call_tool_execution_exception_returns_error(): void {
		wp_set_current_user( 1 );

		// Use the existing test/execute-exception ability
		$server  = $this->makeServer( array( 'test/execute-exception' ), array(), array() );
		$handler = new ToolsHandler( $server );

		$result = $handler->call_tool(
			array(
				'params' => array(
					'name' => 'test-execute-exception',
				),
			)
		);

		// Execution exception is a tool execution error - returns CallToolResult with isError
		$this->assertInstanceOf( CallToolResult::class, $result );
		$res = $result->toArray();
		$this->assertArrayHasKey( 'isError', $res );
		$this->assertTrue( $res['isError'] );
		$this->assertArrayHasKey( 'content', $res );
	}

	public function test_call_tool_permission_exception_returns_error(): void {
		wp_set_current_user( 1 );

		// Use the existing test/permission-exception ability
		$server  = $this->makeServer( array( 'test/permission-exception' ), array(), array() );
		$handler = new ToolsHandler( $server );

		$result = $handler->call_tool(
			array(
				'params' => array(
					'name' => 'test-permission-exception',
				),
			)
		);

		// Per MCP spec: "Any errors that originate from the tool SHOULD be reported inside
		// the result object, with isError set to true"
		$this->assertInstanceOf( CallToolResult::class, $result );
		$res = $result->toArray();
		$this->assertArrayHasKey( 'isError', $res );
		$this->assertTrue( $res['isError'] );
		$this->assertArrayHasKey( 'content', $res );
	}

	public function test_call_tool_permission_denied_returns_error(): void {
		wp_set_current_user( 1 );

		// Use the existing test/permission-denied ability
		$server  = $this->makeServer( array( 'test/permission-denied' ), array(), array() );
		$handler = new ToolsHandler( $server );

		$result = $handler->call_tool(
			array(
				'params' => array(
					'name' => 'test-permission-denied',
				),
			)
		);

		// Per MCP spec: "Any errors that originate from the tool SHOULD be reported inside
		// the result object, with isError set to true"
		$this->assertInstanceOf( CallToolResult::class, $result );
		$res = $result->toArray();
		$this->assertArrayHasKey( 'isError', $res );
		$this->assertTrue( $res['isError'] );
		$this->assertArrayHasKey( 'content', $res );
	}

	public function test_call_tool_uses_metadata_flags_without_exposing_them(): void {
		wp_set_current_user( 1 );
		$captured_input = null;

		$this->register_ability_in_hook(
			'test/flat-transform-call',
			array(
				'label'               => 'Flat Transform Call',
				'description'         => 'Uses flat schemas',
				'category'            => 'test',
				'input_schema'        => array( 'type' => 'string' ),
				'output_schema'       => array( 'type' => 'string' ),
				'execute_callback'    => static function ( $input ) use ( &$captured_input ) {
					$captured_input = $input;
					return $input;
				},
				'permission_callback' => static function () {
					return true;
				},
				'meta'                => array(
					'mcp' => array( 'public' => true ),
				),
			)
		);

		$server  = $this->makeServer( array( 'test/flat-transform-call' ), array(), array() );
		$handler = new ToolsHandler( $server );

		$list       = $handler->list_tools()->toArray();
		$tool_entry = null;
		foreach ( $list['tools'] as $tool ) {
			if ( 'test-flat-transform-call' === $tool['name'] ) {
				$tool_entry = $tool;
				break;
			}
		}

		$this->assertNotNull( $tool_entry );
		$this->assertArrayNotHasKey( '_metadata', $tool_entry );

		$result = $handler->call_tool(
			array(
				'params' => array(
					'name'      => 'test-flat-transform-call',
					'arguments' => array( 'input' => 'hello-world' ),
				),
			)
		);

		$this->assertInstanceOf( CallToolResult::class, $result );
		$res = $result->toArray();

		$this->assertSame( 'hello-world', $captured_input, 'Ability should receive unwrapped argument from metadata flag.' );
		$this->assertArrayHasKey( 'structuredContent', $res );
		$this->assertArrayNotHasKey( '_metadata', $res['structuredContent'] );
		$this->assertSame( array( 'result' => 'hello-world' ), $res['structuredContent'] );

		wp_unregister_ability( 'test/flat-transform-call' );
	}

	public function test_list_tools_sanitizes_tool_data(): void {
		wp_set_current_user( 1 );

		// Use the existing test/always-allowed ability
		$server  = $this->makeServer( array( 'test/always-allowed' ), array(), array() );
		$handler = new ToolsHandler( $server );
		$result  = $handler->list_tools();
		$res     = $result->toArray();

		$this->assertArrayHasKey( 'tools', $res );
		$this->assertNotEmpty( $res['tools'] );

		$tool = $res['tools'][0];
		$this->assertArrayHasKey( 'name', $tool );
		$this->assertArrayHasKey( 'description', $tool );
		$this->assertArrayHasKey( 'inputSchema', $tool );
		// Ensure callback is not in the response
		$this->assertArrayNotHasKey( 'callback', $tool );
		$this->assertArrayNotHasKey( 'permission_callback', $tool );
	}

	public function test_call_tool_with_string_error_from_execute(): void {
		wp_set_current_user( 1 );

		$this->register_ability_in_hook(
			'test/string-error',
			array(
				'label'               => 'String Error',
				'description'         => 'Returns string error from execute',
				'category'            => 'test',
				'input_schema'        => array( 'type' => 'object' ),
				'execute_callback'    => static function () {
					return array(
						'success' => false,
						'error'   => 'Test string error',
					);
				},
				'permission_callback' => static function () {
					return true;
				},
				'meta'                => array(
					'mcp' => array(
						'public' => true,
					),
				),
			)
		);

		$server  = $this->makeServer( array( 'test/string-error' ), array(), array() );
		$handler = new ToolsHandler( $server );

		$result = $handler->call_tool(
			array(
				'params' => array(
					'name' => 'test-string-error',
				),
			)
		);

		$this->assertInstanceOf( CallToolResult::class, $result );
		$res = $result->toArray();
		$this->assertTrue( $res['isError'] );
		$this->assertEquals( 'Test string error', $res['content'][0]['text'] );

		wp_unregister_ability( 'test/string-error' );
	}

	public function test_call_tool_wraps_scalar_return_values(): void {
		wp_set_current_user( 1 );

		// Register an ability that returns a scalar (string) value
		$this->register_ability_in_hook(
			'test/scalar-return',
			array(
				'label'               => 'Scalar Return Test',
				'description'         => 'Returns a scalar string value',
				'category'            => 'test',
				'execute_callback'    => static function () {
					return 'hello-world';
				},
				'permission_callback' => static function () {
					return true;
				},
				'meta'                => array(
					'mcp' => array(
						'public' => true,
					),
				),
			)
		);

		$server  = $this->makeServer( array( 'test/scalar-return' ), array(), array() );
		$handler = new ToolsHandler( $server );

		$result = $handler->call_tool(
			array(
				'params' => array(
					'name' => 'test-scalar-return',
				),
			)
		);

		// Successful execution returns CallToolResult
		$this->assertInstanceOf( CallToolResult::class, $result );
		$res = $result->toArray();

		// Should not have an error
		$this->assertArrayNotHasKey( 'error', $res );
		$this->assertArrayNotHasKey( 'isError', $res );

		// Should have content
		$this->assertArrayHasKey( 'content', $res );
		$this->assertArrayHasKey( 'structuredContent', $res );

		// The scalar value should be wrapped in an array with 'result' key
		$this->assertArrayHasKey( 'result', $res['structuredContent'] );
		$this->assertSame( 'hello-world', $res['structuredContent']['result'] );

		// Note: _metadata is no longer returned by handler DTOs

		wp_unregister_ability( 'test/scalar-return' );
	}
}
