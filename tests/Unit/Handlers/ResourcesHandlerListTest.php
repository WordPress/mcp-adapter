<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Handlers;

use WP\MCP\Core\McpServer;
use WP\MCP\Handlers\Resources\ResourcesHandler;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\Fixtures\DummyObservabilityHandler;
use WP\MCP\Tests\TestCase;
use WP\McpSchema\Server\Resources\DTO\ListResourcesResult;
use WP\McpSchema\Server\Resources\DTO\Resource as ResourceDto;

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
		$this->assertContainsOnlyInstancesOf( ResourceDto::class, $resources );

		// Verify Resource DTO structure via toArray() for field checks
		$resource_array = $resources[0]->toArray();
		$this->assertArrayHasKey( 'uri', $resource_array );
		$this->assertArrayHasKey( 'name', $resource_array );
	}

	public function test_list_resources_applies_resources_list_filter(): void {
		$server  = $this->makeServer( array(), array( 'test/resource' ) );
		$handler = new ResourcesHandler( $server );

		// Verify resources exist before filtering.
		$before = $handler->list_resources();
		$this->assertNotEmpty( $before->getResources() );

		$filter = static function (): array {
			return array();
		};
		add_filter( 'mcp_adapter_resources_list', $filter );

		$result = $handler->list_resources();
		$this->assertInstanceOf( ListResourcesResult::class, $result );
		$this->assertEmpty( $result->getResources() );

		remove_filter( 'mcp_adapter_resources_list', $filter );
	}

	public function test_list_resources_does_not_call_ability_execute(): void {
		wp_set_current_user( 1 );

		// Track whether execute was called.
		$execute_called = false;

		// Register a resource ability that tracks execute calls.
		$this->register_ability_in_hook(
			'test/resource-execute-tracker',
			array(
				'label'               => 'Execute Tracker Resource',
				'description'         => 'Tracks if execute is called',
				'category'            => 'test',
				'execute_callback'    => static function () use ( &$execute_called ) {
					$execute_called = true;
					return 'content';
				},
				'permission_callback' => static function () {
					return true;
				},
				'meta'                => array(
					'mcp' => array(
						'public' => true,
						'type'   => 'resource',
						'uri'    => 'WordPress://local/execute-tracker',
					),
				),
			)
		);

		$server = new McpServer(
			'srv-execute-tracker',
			'mcp/v1',
			'/mcp-tracker',
			'Srv Execute Tracker',
			'desc',
			'0.0.1',
			array(),
			DummyErrorHandler::class,
			DummyObservabilityHandler::class,
			array(),
			array( 'test/resource-execute-tracker' ),
		);

		$handler = new ResourcesHandler( $server );

		// Call list_resources - this should NOT call execute.
		$result = $handler->list_resources();

		// Verify the resource is in the list.
		$this->assertInstanceOf( ListResourcesResult::class, $result );
		$resources = $result->getResources();
		$this->assertNotEmpty( $resources );

		// Verify execute was NOT called.
		$this->assertFalse( $execute_called, 'resources/list should NOT call ability execute()' );

		// Clean up.
		wp_unregister_ability( 'test/resource-execute-tracker' );
	}

	public function test_list_resources_includes_metadata_only(): void {
		wp_set_current_user( 1 );

		$server  = $this->makeServer( array(), array( 'test/resource-new-meta' ) );
		$handler = new ResourcesHandler( $server );
		$result  = $handler->list_resources();

		$this->assertInstanceOf( ListResourcesResult::class, $result );
		$resources = $result->getResources();
		$this->assertNotEmpty( $resources );

		// Verify the resource contains metadata fields.
		$resource_array = $resources[0]->toArray();

		// Required metadata fields.
		$this->assertArrayHasKey( 'uri', $resource_array );
		$this->assertArrayHasKey( 'name', $resource_array );

		// Optional metadata fields (from test/resource-new-meta fixture).
		$this->assertArrayHasKey( 'title', $resource_array );
		$this->assertArrayHasKey( 'description', $resource_array );
		$this->assertArrayHasKey( 'mimeType', $resource_array );

		// Content fields should NOT be present in list response.
		$this->assertArrayNotHasKey( 'text', $resource_array );
		$this->assertArrayNotHasKey( 'blob', $resource_array );
	}

	public function test_list_resources_withFilterReturningNonArray_fallsBackToOriginal(): void {
		$server  = $this->makeServer( array(), array( 'test/resource' ) );
		$handler = new ResourcesHandler( $server );

		$filter = static function (): string {
			return 'not an array';
		};
		add_filter( 'mcp_adapter_resources_list', $filter );

		DummyErrorHandler::reset();
		$result = $handler->list_resources();

		$this->assertInstanceOf( ListResourcesResult::class, $result );
		$this->assertNotEmpty( $result->getResources() );

		$this->assertNotEmpty( DummyErrorHandler::$logs );
		$last_log = end( DummyErrorHandler::$logs );
		$this->assertSame( 'warning', $last_log['type'] );
		$this->assertStringContainsString( 'mcp_adapter_resources_list', $last_log['context']['filter'] );

		remove_filter( 'mcp_adapter_resources_list', $filter );
	}
}
