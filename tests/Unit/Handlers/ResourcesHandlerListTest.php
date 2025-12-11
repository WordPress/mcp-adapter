<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Handlers;

use WP\MCP\Core\McpServer;
use WP\MCP\Handlers\Resources\ResourcesHandler;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\Fixtures\DummyObservabilityHandler;
use WP\MCP\Tests\TestCase;
use WP\McpSchema\Server\Resources\ListResourcesResult;
use WP\McpSchema\Server\Resources\Resource;

final class ResourcesHandlerListTest extends TestCase {

	public function test_list_resources_returns_dto(): void {
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
		$result  = $handler->list_resources();

		// Verify it returns a ListResourcesResult DTO
		$this->assertInstanceOf( ListResourcesResult::class, $result );

		// Use DTO getter methods
		$resources = $result->getResources();
		$this->assertNotEmpty( $resources );
		$this->assertContainsOnlyInstancesOf( Resource::class, $resources );

		// Verify Resource DTO structure via toArray() for field checks
		$resource_array = $resources[0]->toArray();
		$this->assertArrayHasKey( 'uri', $resource_array );
		$this->assertArrayHasKey( 'name', $resource_array );
	}
}
