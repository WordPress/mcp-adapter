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
use WP_Error;

/**
 * Test ToolsHandler functionality.
 */
final class ToolsHandlerTest extends TestCase {

	public function test_list_tools_returns_tools_array(): void {
		wp_set_current_user( 1 );
		$server  = $this->makeServer( array( 'test/always-allowed' ), array(), array() );
		$handler = new ToolsHandler( $server );
		$result  = $handler->list_tools();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'tools', $result );
	}

	public function test_list_tools_returns_registered_tools(): void {
		wp_set_current_user( 1 );
		$server  = $this->makeServer( array( 'test/always-allowed' ), array(), array() );
		$handler = new ToolsHandler( $server );
		$result  = $handler->list_tools();

		$tools = $result['tools'];
		$this->assertNotEmpty( $tools );
		foreach ( $tools as $tool ) {
			$this->assertIsArray( $tool );
			$this->assertArrayHasKey( 'name', $tool );
		}
	}

	public function test_list_tools_returns_empty_array_when_no_tools(): void {
		$server  = $this->makeServer( array(), array(), array() );
		$handler = new ToolsHandler( $server );
		$result  = $handler->list_tools();

		$tools = $result['tools'];
		$this->assertIsArray( $tools );
		$this->assertEmpty( $tools );
	}

	public function test_list_all_tools_returns_tools_array(): void {
		wp_set_current_user( 1 );
		$server  = $this->makeServer( array( 'test/always-allowed' ), array(), array() );
		$handler = new ToolsHandler( $server );
		$result  = $handler->list_all_tools();

		$this->assertIsArray( $result );
		$tools = $result['tools'];
		$this->assertNotEmpty( $tools );
		foreach ( $tools as $tool ) {
			$this->assertIsArray( $tool );
			$this->assertArrayHasKey( 'name', $tool );
		}
	}

	public function test_call_tool_missing_name_returns_error(): void {
		$server  = $this->makeServer( array( 'test/always-allowed' ), array(), array() );
		$handler = new ToolsHandler( $server );
		$result  = $handler->call_tool( array( 'params' => array() ) );

		// Missing name is a protocol error - returns a JSON-RPC error envelope.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'error', $result );
		$error = $result['error'];
		$this->assertNotEmpty( $error['message'] );
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

		// Tool not found is a protocol error - returns a JSON-RPC error envelope.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'error', $result );
		$error = $result['error'];
		$this->assertNotEmpty( $error['message'] );
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
					return new WP_Error( 'test_error', 'Test error message' );
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

		// WP_Error from execute is a tool execution error - returns a tool-call result with isError.
		$this->assertIsArray( $result );
		$this->assertTrue( $result['isError'] );
		$content = $result['content'];
		$this->assertNotEmpty( $content );
		$this->assertIsArray( $content[0] );
		$this->assertSame( 'text', $content[0]['type'] );

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

		// Permission exception is a tool execution error - returns a tool-call result with isError.
		$this->assertIsArray( $result );
		$this->assertTrue( $result['isError'] );
		$content = $result['content'];
		$this->assertNotEmpty( $content );
		$this->assertIsArray( $content[0] );
		$this->assertSame( 'text', $content[0]['type'] );

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

		// Call tool without arguments since test/always-allowed doesn't define input_schema
		$result = $handler->call_tool(
			array(
				'params' => array(
					'name' => 'test-always-allowed',
				),
			)
		);

		// Successful execution returns a tool-call result.
		$this->assertIsArray( $result );
		// Success means isError is not true.
		$this->assertNotTrue( $result['isError'] );
		$content = $result['content'];
		$this->assertNotEmpty( $content );
		$this->assertIsArray( $content[0] );
		$this->assertSame( 'text', $content[0]['type'] );
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

		// Execution exception is a tool execution error - returns a tool-call result with isError.
		$this->assertIsArray( $result );
		$this->assertTrue( $result['isError'] );
		$content = $result['content'];
		$this->assertNotEmpty( $content );
		$this->assertIsArray( $content[0] );
		$this->assertSame( 'text', $content[0]['type'] );
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
		$this->assertIsArray( $result );
		$this->assertTrue( $result['isError'] );
		$content = $result['content'];
		$this->assertNotEmpty( $content );
		$this->assertIsArray( $content[0] );
		$this->assertSame( 'text', $content[0]['type'] );
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
		$this->assertIsArray( $result );
		$this->assertTrue( $result['isError'] );
		$content = $result['content'];
		$this->assertNotEmpty( $content );
		$this->assertIsArray( $content[0] );
		$this->assertSame( 'text', $content[0]['type'] );
		$this->assertStringContainsString( 'Permission denied', $content[0]['text'] );
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

		$tools      = $handler->list_tools()['tools'];
		$tool_entry = null;
		foreach ( $tools as $tool ) {
			if ( 'test-flat-transform-call' === $tool['name'] ) {
				$tool_entry = $tool;
				break;
			}
		}

		$this->assertNotNull( $tool_entry );
		$this->assertIsArray( $tool_entry );

		$result = $handler->call_tool(
			array(
				'params' => array(
					'name'      => 'test-flat-transform-call',
					'arguments' => array( 'input' => 'hello-world' ),
				),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'hello-world', $captured_input, 'Ability should receive unwrapped argument from metadata flag.' );
		$this->assertArrayHasKey( 'structuredContent', $result );
		$structured_content = $result['structuredContent'];
		$this->assertArrayNotHasKey( '_meta', $structured_content );
		$this->assertSame( array( 'result' => 'hello-world' ), $structured_content );

		wp_unregister_ability( 'test/flat-transform-call' );
	}

	public function test_list_tools_sanitizes_tool_data(): void {
		wp_set_current_user( 1 );

		// Use the existing test/always-allowed ability
		$server  = $this->makeServer( array( 'test/always-allowed' ), array(), array() );
		$handler = new ToolsHandler( $server );
		$result  = $handler->list_tools();

		$tools = $result['tools'];
		$this->assertNotEmpty( $tools );
		foreach ( $tools as $tool ) {
			$this->assertIsArray( $tool );
			$this->assertArrayHasKey( 'name', $tool );
		}

		// Verify required properties exist on the tool entry.
		$tool_array = $tools[0];
		$this->assertArrayHasKey( 'name', $tool_array );
		$this->assertArrayHasKey( 'description', $tool_array );
		$this->assertArrayHasKey( 'inputSchema', $tool_array );
		// Ensure callback is not in the response (internal callbacks are never exposed)
		$this->assertArrayNotHasKey( 'callback', $tool_array );
		$this->assertArrayNotHasKey( 'permission_callback', $tool_array );
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

		$this->assertIsArray( $result );
		$this->assertTrue( $result['isError'] );
		$content = $result['content'];
		$this->assertIsArray( $content[0] );
		$this->assertSame( 'text', $content[0]['type'] );
		$this->assertEquals( 'Test string error', $content[0]['text'] );

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

		// Successful execution returns a tool-call result.
		$this->assertIsArray( $result );

		// Should not have an error (isError is not true).
		$this->assertNotTrue( $result['isError'] );

		// Should have content
		$content = $result['content'];
		$this->assertNotEmpty( $content );
		$this->assertIsArray( $content[0] );
		$this->assertSame( 'text', $content[0]['type'] );

		// Should have structured content with the scalar wrapped
		$this->assertArrayHasKey( 'structuredContent', $result );
		$structured_content = $result['structuredContent'];
		$this->assertArrayHasKey( 'result', $structured_content );
		$this->assertSame( 'hello-world', $structured_content['result'] );

		wp_unregister_ability( 'test/scalar-return' );
	}
}
