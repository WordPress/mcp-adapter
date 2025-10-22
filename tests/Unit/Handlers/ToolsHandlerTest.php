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

/**
 * Test ToolsHandler functionality.
 */
final class ToolsHandlerTest extends TestCase {

	public function test_list_tools_returns_registered_tools(): void {
		wp_set_current_user( 1 );
		$server  = $this->makeServer( array( 'test/always-allowed' ), array(), array() );
		$handler = new ToolsHandler( $server );
		$res     = $handler->list_tools();

		$this->assertArrayHasKey( 'tools', $res );
		$this->assertNotEmpty( $res['tools'] );
		$this->assertArrayHasKey( '_metadata', $res );
		$this->assertEquals( 'tools', $res['_metadata']['component_type'] );
		$this->assertArrayHasKey( 'tools_count', $res['_metadata'] );
	}

	public function test_list_tools_returns_empty_array_when_no_tools(): void {
		$server  = $this->makeServer( array(), array(), array() );
		$handler = new ToolsHandler( $server );
		$res     = $handler->list_tools();

		$this->assertArrayHasKey( 'tools', $res );
		$this->assertEmpty( $res['tools'] );
		$this->assertEquals( 0, $res['_metadata']['tools_count'] );
	}

	public function test_list_all_tools_includes_available_flag(): void {
		wp_set_current_user( 1 );
		$server  = $this->makeServer( array( 'test/always-allowed' ), array(), array() );
		$handler = new ToolsHandler( $server );
		$res     = $handler->list_all_tools();

		$this->assertArrayHasKey( 'tools', $res );
		$this->assertNotEmpty( $res['tools'] );
		$this->assertTrue( $res['tools'][0]['available'] );
	}

	public function test_call_tool_missing_name_returns_error(): void {
		$server  = $this->makeServer( array( 'test/always-allowed' ), array(), array() );
		$handler = new ToolsHandler( $server );
		$res     = $handler->call_tool( array( 'params' => array() ) );

		$this->assertArrayHasKey( 'error', $res );
		$this->assertArrayHasKey( '_metadata', $res );
		$this->assertEquals( 'missing_parameter', $res['_metadata']['failure_reason'] );
	}

	public function test_call_tool_not_found_returns_error(): void {
		$server  = $this->makeServer( array( 'test/always-allowed' ), array(), array() );
		$handler = new ToolsHandler( $server );
		$res     = $handler->call_tool(
			array(
				'params' => array(
					'name' => 'nonexistent-tool',
				),
			)
		);

		$this->assertArrayHasKey( 'error', $res );
		$this->assertArrayHasKey( '_metadata', $res );
		$this->assertEquals( 'not_found', $res['_metadata']['failure_reason'] );
	}

	// Note: Permission denied, execution errors, and exceptions are tested
	// using existing test abilities in DummyAbility

	public function test_call_tool_success_returns_content(): void {
		wp_set_current_user( 1 );

		$server  = $this->makeServer( array( 'test/always-allowed' ), array(), array() );
		$handler = new ToolsHandler( $server );

		$res = $handler->call_tool(
			array(
				'params' => array(
					'name'      => 'test-always-allowed',
					'arguments' => array( 'input' => 'test data' ),
				),
			)
		);

		$this->assertArrayHasKey( 'content', $res );
		$this->assertArrayHasKey( '_metadata', $res );
		$this->assertEquals( 'tool', $res['_metadata']['component_type'] );
		$this->assertArrayHasKey( 'tool_name', $res['_metadata'] );
		$this->assertArrayHasKey( 'ability_name', $res['_metadata'] );
	}

	public function test_call_tool_execution_exception_returns_error(): void {
		wp_set_current_user( 1 );

		// Use the existing test/execute-exception ability
		$server  = $this->makeServer( array( 'test/execute-exception' ), array(), array() );
		$handler = new ToolsHandler( $server );

		$res = $handler->call_tool(
			array(
				'params' => array(
					'name' => 'test-execute-exception',
				),
			)
		);

		$this->assertArrayHasKey( 'isError', $res );
		$this->assertTrue( $res['isError'] );
		$this->assertArrayHasKey( 'content', $res );
		$this->assertArrayHasKey( '_metadata', $res );
		$this->assertEquals( 'execution_failed', $res['_metadata']['failure_reason'] );
		$this->assertArrayHasKey( 'error_type', $res['_metadata'] );
	}

	public function test_call_tool_permission_exception_returns_error(): void {
		wp_set_current_user( 1 );

		// Use the existing test/permission-exception ability
		$server  = $this->makeServer( array( 'test/permission-exception' ), array(), array() );
		$handler = new ToolsHandler( $server );

		$res = $handler->call_tool(
			array(
				'params' => array(
					'name' => 'test-permission-exception',
				),
			)
		);

		// Per MCP spec: "Any errors that originate from the tool SHOULD be reported inside
		// the result object, with isError set to true"
		$this->assertArrayHasKey( 'isError', $res );
		$this->assertTrue( $res['isError'] );
		$this->assertArrayHasKey( 'content', $res );
		$this->assertArrayHasKey( '_metadata', $res );
		$this->assertEquals( 'permission_check_failed', $res['_metadata']['failure_reason'] );
		$this->assertArrayHasKey( 'error_type', $res['_metadata'] );
	}

	public function test_call_tool_permission_denied_returns_error(): void {
		wp_set_current_user( 1 );

		// Use the existing test/permission-denied ability
		$server  = $this->makeServer( array( 'test/permission-denied' ), array(), array() );
		$handler = new ToolsHandler( $server );

		$res = $handler->call_tool(
			array(
				'params' => array(
					'name' => 'test-permission-denied',
				),
			)
		);

		// Per MCP spec: "Any errors that originate from the tool SHOULD be reported inside
		// the result object, with isError set to true"
		$this->assertArrayHasKey( 'isError', $res );
		$this->assertTrue( $res['isError'] );
		$this->assertArrayHasKey( 'content', $res );
		$this->assertArrayHasKey( '_metadata', $res );
		$this->assertEquals( 'permission_denied', $res['_metadata']['failure_reason'] );
	}

	public function test_list_tools_sanitizes_tool_data(): void {
		wp_set_current_user( 1 );

		// Use the existing test/always-allowed ability
		$server  = $this->makeServer( array( 'test/always-allowed' ), array(), array() );
		$handler = new ToolsHandler( $server );
		$res     = $handler->list_tools();

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
}
