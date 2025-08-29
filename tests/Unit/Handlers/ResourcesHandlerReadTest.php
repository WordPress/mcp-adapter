<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Handlers;

use WP\MCP\Core\McpServer;
use WP\MCP\Handlers\Resources\ResourcesHandler;
use WP\MCP\Tests\Fixtures\DummyAbility;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\Fixtures\DummyObservabilityHandler;
use WP\MCP\Tests\TestCase;

final class ResourcesHandlerReadTest extends TestCase {

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		do_action( 'abilities_api_init' );
		DummyAbility::register_all();
	}

	public function test_missing_uri_returns_error(): void {
		wp_set_current_user( 1 );
		$server  = $this->makeServer( array( 'test/resource' ) );
		$handler = new ResourcesHandler( $server );
		$res     = $handler->read_resource( array( 'params' => array() ) );
		$this->assertArrayHasKey( 'error', $res );
	}

	public function test_unknown_resource_returns_error(): void {
		wp_set_current_user( 1 );
		$server  = $this->makeServer();
		$handler = new ResourcesHandler( $server );
		$res     = $handler->read_resource( array( 'params' => array( 'uri' => 'WordPress://missing' ) ) );
		$this->assertArrayHasKey( 'error', $res );
	}

	public function test_successful_read_returns_contents(): void {
		wp_set_current_user( 1 );
		$server  = $this->makeServer( array( 'test/resource' ) );
		$handler = new ResourcesHandler( $server );
		$res     = $handler->read_resource( array( 'params' => array( 'uri' => 'WordPress://local/resource-1' ) ) );
		$this->assertArrayHasKey( 'contents', $res );
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
