<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Handlers;

use WP\MCP\Core\McpServer;
use WP\MCP\Handlers\Resources\ResourcesHandler;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\Fixtures\DummyObservabilityHandler;
use WP\MCP\Tests\TestCase;

final class ResourcesHandlerListTest extends TestCase {

	public function test_list_resources_returns_registered_resources(): void {
		// Simulate logged-in for permission check.
		wp_set_current_user( 1 );

		$server = new McpServer(
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
			array( 'test/resource' ),
		);

		$handler = new ResourcesHandler( $server );
		$res     = $handler->list_resources();
		$this->assertArrayHasKey( 'resources', $res );
		$this->assertNotEmpty( $res['resources'] );
		$this->assertArrayHasKey( 'uri', $res['resources'][0] );
	}
}
