<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Handlers;

use WP\MCP\Core\McpServer;
use WP\MCP\Handlers\Tools\ToolsHandler;
use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;
use WP\MCP\Tests\Fixtures\DummyAbility;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\Fixtures\DummyObservabilityHandler;
use WP\MCP\Tests\TestCase;

final class ToolsHandlerWpErrorTest extends TestCase {

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		do_action( 'abilities_api_init' );
		DummyAbility::register_all();
	}

	public function test_wp_error_from_tool_returns_internal_error(): void {
		$server  = $this->makeServer( array( 'test/wp-error-tool' ) );
		$handler = new ToolsHandler( $server );
		
		$result = $handler->call_tool(
			array(
				'params' => array(
					'name' => 'test-wp-error-tool',
				),
			),
			123
		);

		// Should return an error response
		$this->assertArrayHasKey( 'error', $result );
		$this->assertArrayNotHasKey( 'content', $result );
		
		// Verify error structure matches MCP error format
		$error = $result['error'];
		$this->assertArrayHasKey( 'code', $error );
		$this->assertArrayHasKey( 'message', $error );
		
		// Should be an internal error
		$this->assertSame( McpErrorFactory::INTERNAL_ERROR, $error['code'] );
		$this->assertStringContainsString( 'Error executing tool', $error['message'] );
	}

	public function test_wp_error_from_tool_with_different_request_id(): void {
		$server  = $this->makeServer( array( 'test/wp-error-tool' ) );
		$handler = new ToolsHandler( $server );
		
		$request_id = 789;
		$result = $handler->call_tool(
			array(
				'params' => array(
					'name' => 'test-wp-error-tool',
				),
			),
			$request_id
		);

		$this->assertArrayHasKey( 'error', $result );
		
		// The error should contain the internal error code
		$error = $result['error'];
		$this->assertSame( McpErrorFactory::INTERNAL_ERROR, $error['code'] );
	}

	public function test_successful_tool_execution_still_works(): void {
		$server  = $this->makeServer( array( 'test/always-allowed', 'test/wp-error-tool' ) );
		$handler = new ToolsHandler( $server );
		
		// Test that normal tools still work
		$result = $handler->call_tool(
			array(
				'params' => array(
					'name' => 'test-always-allowed',
					'arguments' => array( 'test' => 'data' ),
				),
			)
		);

		$this->assertArrayHasKey( 'content', $result );
		$this->assertArrayNotHasKey( 'error', $result );
		$this->assertSame( 'text', $result['content'][0]['type'] );
		
		// Verify the structured content is correct
		$this->assertArrayHasKey( 'structuredContent', $result );
		$this->assertArrayHasKey( 'ok', $result['structuredContent'] );
		$this->assertTrue( $result['structuredContent']['ok'] );
	}

	public function test_wp_error_tool_mixed_with_normal_tools(): void {
		$server  = $this->makeServer( array( 'test/always-allowed', 'test/wp-error-tool' ) );
		$handler = new ToolsHandler( $server );
		
		// Test normal tool
		$normal_result = $handler->call_tool(
			array(
				'params' => array(
					'name' => 'test-always-allowed',
					'arguments' => array(),
				),
			)
		);
		$this->assertArrayHasKey( 'content', $normal_result );
		
		// Test error tool
		$error_result = $handler->call_tool(
			array(
				'params' => array(
					'name' => 'test-wp-error-tool',
				),
			)
		);
		$this->assertArrayHasKey( 'error', $error_result );
		$this->assertSame( McpErrorFactory::INTERNAL_ERROR, $error_result['error']['code'] );
	}

	public function test_wp_error_tool_with_arguments(): void {
		$server  = $this->makeServer( array( 'test/wp-error-tool' ) );
		$handler = new ToolsHandler( $server );
		
		$result = $handler->call_tool(
			array(
				'params' => array(
					'name' => 'test-wp-error-tool',
					'arguments' => array(
						'param1' => 'value1',
						'param2' => 'value2',
					),
				),
			)
		);

		// Even with arguments, WP_Error should still be handled
		$this->assertArrayHasKey( 'error', $result );
		$this->assertSame( McpErrorFactory::INTERNAL_ERROR, $result['error']['code'] );
	}

	public function test_wp_error_vs_exception_handling(): void {
		$server  = $this->makeServer( array( 'test/wp-error-tool', 'test/execute-exception' ) );
		$handler = new ToolsHandler( $server );
		
		// Test WP_Error tool
		$wp_error_result = $handler->call_tool(
			array(
				'params' => array( 'name' => 'test-wp-error-tool' ),
			)
		);
		
		// Test exception tool
		$exception_result = $handler->call_tool(
			array(
				'params' => array( 'name' => 'test-execute-exception' ),
			)
		);

		// Both should return internal errors, but they're handled differently
		$this->assertArrayHasKey( 'error', $wp_error_result );
		$this->assertArrayHasKey( 'error', $exception_result );
		
		$this->assertSame( McpErrorFactory::INTERNAL_ERROR, $wp_error_result['error']['code'] );
		$this->assertSame( McpErrorFactory::INTERNAL_ERROR, $exception_result['error']['code'] );
		
		// Both should contain "Error executing tool" as that's the message used in both cases
		// The WP_Error path and exception path both use the same error message
		$this->assertStringContainsString( 'Error executing tool', $wp_error_result['error']['message'] );
		$this->assertStringContainsString( 'Error executing tool', $exception_result['error']['message'] );
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
}
