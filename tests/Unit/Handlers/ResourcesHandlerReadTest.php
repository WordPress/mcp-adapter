<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Handlers;

use WP\MCP\Handlers\Resources\ResourcesHandler;
use WP\MCP\Tests\TestCase;

final class ResourcesHandlerReadTest extends TestCase {

	public function test_missing_uri_returns_error(): void {
		wp_set_current_user( 1 );
		$server  = $this->makeServer( array(), array( 'test/resource' ) );
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
		$server  = $this->makeServer( array(), array( 'test/resource' ) );
		$handler = new ResourcesHandler( $server );
		$res     = $handler->read_resource( array( 'params' => array( 'uri' => 'WordPress://local/resource-1' ) ) );
		$this->assertArrayHasKey( 'contents', $res );
	}
}
