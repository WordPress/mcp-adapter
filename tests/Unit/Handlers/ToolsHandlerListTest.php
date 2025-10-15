<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Handlers;

use WP\MCP\Core\McpServer;
use WP\MCP\Handlers\Tools\ToolsHandler;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\Fixtures\DummyObservabilityHandler;
use WP\MCP\Tests\TestCase;

final class ToolsHandlerListTest extends TestCase {

	public function test_list_and_list_all_only_include_json_safe_fields(): void {
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
			array( 'test/always-allowed' ),
		);

		$handler = new ToolsHandler( $server );
		$list    = $handler->list_tools();
		$all     = $handler->list_all_tools();

		$this->assertArrayHasKey( 'tools', $list );
		$this->assertArrayHasKey( 'tools', $all );
		$this->assertNotEmpty( $list['tools'] );

		$tool = $list['tools'][0];
		$this->assertArrayHasKey( 'name', $tool );
		$this->assertArrayHasKey( 'description', $tool );
		$this->assertArrayHasKey( 'inputSchema', $tool );
		$this->assertArrayNotHasKey( 'callback', $tool );
		$this->assertArrayNotHasKey( 'permission_callback', $tool );

		$tool_all = $all['tools'][0];
		$this->assertTrue( $tool_all['available'] );
	}
}
