<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Handlers;

use WP\MCP\Core\McpServer;
use WP\MCP\Handlers\Resources\ResourcesHandler;
use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;
use WP\MCP\Tests\Fixtures\DummyAbility;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\Fixtures\DummyObservabilityHandler;
use WP\MCP\Tests\TestCase;

final class ResourcesHandlerWpErrorTest extends TestCase {

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		do_action( 'abilities_api_init' );
		DummyAbility::register_all();
	}

	public function test_wp_error_from_resource_returns_internal_error(): void {
		wp_set_current_user( 1 );
		$server  = $this->makeServer( array( 'test/wp-error-resource' ) );
		$handler = new ResourcesHandler( $server );
		
		$result = $handler->read_resource( 
			array( 'params' => array( 'uri' => 'WordPress://error/resource' ) ), 
			123 
		);

		// Should return an error response
		$this->assertArrayHasKey( 'error', $result );
		$this->assertArrayNotHasKey( 'contents', $result );
		
		// Verify error structure matches MCP error format
		$error = $result['error'];
		$this->assertArrayHasKey( 'code', $error );
		$this->assertArrayHasKey( 'message', $error );
		
		// Should be an internal error
		$this->assertSame( McpErrorFactory::INTERNAL_ERROR, $error['code'] );
		$this->assertStringContainsString( 'Error reading resource', $error['message'] );
	}

	public function test_wp_error_from_resource_with_different_request_id(): void {
		wp_set_current_user( 1 );
		$server  = $this->makeServer( array( 'test/wp-error-resource' ) );
		$handler = new ResourcesHandler( $server );
		
		$request_id = 456;
		$result = $handler->read_resource( 
			array( 'params' => array( 'uri' => 'WordPress://error/resource' ) ), 
			$request_id
		);

		$this->assertArrayHasKey( 'error', $result );
		
		// The error should contain the internal error code
		$error = $result['error'];
		$this->assertSame( McpErrorFactory::INTERNAL_ERROR, $error['code'] );
	}

	public function test_successful_resource_read_still_works(): void {
		wp_set_current_user( 1 );
		$server  = $this->makeServer( array( 'test/resource', 'test/wp-error-resource' ) );
		$handler = new ResourcesHandler( $server );
		
		// Test that normal resources still work
		$result = $handler->read_resource( 
			array( 'params' => array( 'uri' => 'WordPress://local/resource-1' ) ) 
		);

		$this->assertArrayHasKey( 'contents', $result );
		$this->assertArrayNotHasKey( 'error', $result );
		$this->assertSame( 'content', $result['contents'] );
	}

	public function test_wp_error_resource_mixed_with_normal_resources(): void {
		wp_set_current_user( 1 );
		$server  = $this->makeServer( array( 'test/resource', 'test/wp-error-resource' ) );
		$handler = new ResourcesHandler( $server );
		
		// Test normal resource
		$normal_result = $handler->read_resource( 
			array( 'params' => array( 'uri' => 'WordPress://local/resource-1' ) ) 
		);
		$this->assertArrayHasKey( 'contents', $normal_result );
		
		// Test error resource
		$error_result = $handler->read_resource( 
			array( 'params' => array( 'uri' => 'WordPress://error/resource' ) ) 
		);
		$this->assertArrayHasKey( 'error', $error_result );
		$this->assertSame( McpErrorFactory::INTERNAL_ERROR, $error_result['error']['code'] );
	}

	private function makeServer( array $resources = array() ): McpServer {
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
			array(),
			$resources,
		);
	}
}
