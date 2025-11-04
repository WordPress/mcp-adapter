<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Handlers;

use WP\MCP\Handlers\Tools\ToolsHandler;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\TestCase;

final class ToolsHandlerCallTest extends TestCase {

	public function test_missing_name_returns_missing_parameter_error(): void {
		$server  = $this->makeServer( array( 'test/always-allowed' ) );
		$handler = new ToolsHandler( $server );
		$res     = $handler->call_tool( array( 'params' => array( 'arguments' => array() ) ) );
		$this->assertArrayHasKey( 'error', $res );
		$this->assertArrayHasKey( 'code', $res['error'] );
	}

	public function test_unknown_tool_logs_and_returns_error(): void {
		$server = $this->makeServer( array( 'test/always-allowed' ) );
		$handler = new ToolsHandler( $server );
		$res     = $handler->call_tool( array( 'params' => array( 'name' => 'nope' ) ) );
		$this->assertArrayHasKey( 'error', $res );
		$this->assertNotEmpty( DummyErrorHandler::$logs );
	}

	public function test_permission_denied_returns_error(): void {
		$server  = $this->makeServer( array( 'test/permission-denied' ) );
		$handler = new ToolsHandler( $server );
		$res     = $handler->call_tool(
			array(
				'params' => array( 'name' => 'test-permission-denied' ),
			)
		);
		// Permission denied is now returned as isError: true (tool execution error)
		$this->assertArrayHasKey( 'isError', $res );
		$this->assertTrue( $res['isError'] );
		$this->assertArrayHasKey( 'content', $res );
		$this->assertIsArray( $res['content'] );
		$this->assertArrayHasKey( 'text', $res['content'][0] );
		$this->assertStringContainsString( 'Permission denied', $res['content'][0]['text'] );
	}

	public function test_permission_exception_logs_and_returns_error(): void {
		$server = $this->makeServer( array( 'test/permission-exception' ) );
		$handler = new ToolsHandler( $server );
		$res     = $handler->call_tool(
			array(
				'params' => array( 'name' => 'test-permission-exception' ),
			)
		);
		// Permission check exception is returned as isError: true (tool execution error)
		$this->assertArrayHasKey( 'isError', $res );
		$this->assertTrue( $res['isError'] );
		$this->assertNotEmpty( DummyErrorHandler::$logs );
	}

	public function test_execute_exception_logs_and_returns_internal_error_envelope(): void {
		$server = $this->makeServer( array( 'test/execute-exception' ) );
		$handler = new ToolsHandler( $server );
		$res     = $handler->call_tool(
			array(
				'params' => array( 'name' => 'test-execute-exception' ),
			)
		);
		// Execute exceptions are returned as tool execution errors (isError: true)
		// not as protocol errors, per MCP spec
		$this->assertArrayHasKey( 'isError', $res );
		$this->assertTrue( $res['isError'] );
		$this->assertArrayHasKey( 'content', $res );
		$this->assertIsArray( $res['content'] );
		$this->assertArrayHasKey( 'type', $res['content'][0] );
		$this->assertEquals( 'text', $res['content'][0]['type'] );
		$this->assertNotEmpty( DummyErrorHandler::$logs );
	}

	public function test_image_result_is_converted_to_base64_with_mime_type(): void {
		$server  = $this->makeServer( array( 'test/image' ) );
		$handler = new ToolsHandler( $server );
		$res     = $handler->call_tool(
			array(
				'params' => array( 'name' => 'test-image' ),
			)
		);
		$this->assertArrayHasKey( 'content', $res, 'Response should have content key' );
		$this->assertIsArray( $res['content'], 'Content should be an array' );
		$this->assertNotEmpty( $res['content'], 'Content array should not be empty' );
		$this->assertArrayHasKey( 0, $res['content'], 'Content should have at least one element' );
		$this->assertSame( 'image', $res['content'][0]['type'] );
		$this->assertArrayHasKey( 'data', $res['content'][0] );
		$this->assertArrayHasKey( 'mimeType', $res['content'][0] );
	}
}
