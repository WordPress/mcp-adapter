<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Handlers;

use WP\MCP\Handlers\Tools\ToolsHandler;
use WP\MCP\Tests\TestCase;
use WP\McpSchema\Server\Tools\ListToolsResult;

final class ToolsHandlerListTest extends TestCase {

	public function test_list_tools_returns_dto(): void {
		// Use makeServer helper to properly set up the server with registered abilities.
		$server = $this->makeServer( array( 'test/always-allowed' ) );

		$handler = new ToolsHandler( $server );
		$result  = $handler->list_tools();

		// Verify it returns a ListToolsResult DTO
		$this->assertInstanceOf( ListToolsResult::class, $result );
	}

	public function test_list_and_list_all_only_include_json_safe_fields(): void {
		// Use makeServer helper to properly set up the server with registered abilities.
		$server = $this->makeServer( array( 'test/always-allowed' ) );

		$handler = new ToolsHandler( $server );
		$list    = $handler->list_tools()->toArray();
		$all     = $handler->list_all_tools()->toArray();

		$this->assertArrayHasKey( 'tools', $list );
		$this->assertArrayHasKey( 'tools', $all );
		$this->assertNotEmpty( $list['tools'] );

		$tool = $list['tools'][0];
		$this->assertArrayHasKey( 'name', $tool );
		$this->assertArrayHasKey( 'description', $tool );
		$this->assertArrayHasKey( 'inputSchema', $tool );
		$this->assertArrayNotHasKey( 'callback', $tool );
		$this->assertArrayNotHasKey( 'permission_callback', $tool );

		// list_all_tools now returns the same as list_tools (standard MCP format)
		// The 'available' flag was a non-standard extension that's no longer included in DTOs
		$tool_all = $all['tools'][0];
		$this->assertArrayHasKey( 'name', $tool_all );
	}
}
