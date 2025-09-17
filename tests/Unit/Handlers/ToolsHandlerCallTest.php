<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Handlers;

use WP\MCP\Core\McpServer;
use WP\MCP\Handlers\Tools\ToolsHandler;
use WP\MCP\Tests\Fixtures\DummyAbility;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\Fixtures\DummyObservabilityHandler;
use WP\MCP\Tests\TestCase;

final class ToolsHandlerCallTest extends TestCase {

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		do_action( 'abilities_api_init' );
		DummyAbility::register_all();
	}

	private function makeServer( array $tools ): McpServer {
		return new McpServer(
			'srv',
			'mcp/v1',
			'/mcp',
			'Srv',
			'desc',
			'0.0.1',
			array(),
			DummyErrorHandler::class,
			DummyObservabilityHandler::class,
			$tools,
		);
	}

	public function test_missing_name_returns_missing_parameter_error(): void {
		$server  = $this->makeServer( array( 'test/always-allowed' ) );
		$handler = new ToolsHandler( $server );
		$res     = $handler->call_tool( array( 'params' => array( 'arguments' => array() ) ) );
		$this->assertArrayHasKey( 'error', $res );
		$this->assertArrayHasKey( 'code', $res['error'] );
	}

	public function test_unknown_tool_logs_and_returns_error(): void {
		$server = $this->makeServer( array( 'test/always-allowed' ) );
		DummyErrorHandler::reset();
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
		$this->assertArrayHasKey( 'error', $res );
		$this->assertArrayHasKey( 'code', $res['error'] );
		$this->assertArrayHasKey( 'message', $res['error'] );
		$this->assertStringContainsString( 'Permission denied', $res['error']['message'] );
	}

	public function test_permission_exception_logs_and_returns_error(): void {
		$server = $this->makeServer( array( 'test/permission-exception' ) );
		DummyErrorHandler::reset();
		$handler = new ToolsHandler( $server );
		$res     = $handler->call_tool(
			array(
				'params' => array( 'name' => 'test-permission-exception' ),
			)
		);
		$this->assertArrayHasKey( 'error', $res );
		$this->assertNotEmpty( DummyErrorHandler::$logs );
	}

	public function test_execute_exception_logs_and_returns_internal_error_envelope(): void {
		$server = $this->makeServer( array( 'test/execute-exception' ) );
		DummyErrorHandler::reset();
		$handler = new ToolsHandler( $server );
		$res     = $handler->call_tool(
			array(
				'params' => array( 'name' => 'test-execute-exception' ),
			)
		);
		$this->assertArrayHasKey( 'error', $res );
		$this->assertArrayHasKey( 'code', $res['error'] );
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
		$this->assertSame( 'image', $res['content'][0]['type'] );
		$this->assertArrayHasKey( 'data', $res['content'][0] );
		$this->assertArrayHasKey( 'mimeType', $res['content'][0] );
	}
}
