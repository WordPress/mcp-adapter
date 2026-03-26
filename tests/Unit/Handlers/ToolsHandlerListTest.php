<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Handlers;

use WP\MCP\Handlers\Tools\ToolsHandler;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\TestCase;
use WP\McpSchema\Server\Tools\DTO\ListToolsResult;
use WP\McpSchema\Server\Tools\DTO\Tool as ToolDto;

final class ToolsHandlerListTest extends TestCase {

	public function test_list_tools_returns_dto(): void {
		// Use makeServer helper to properly set up the server with registered abilities.
		$server = $this->makeServer( array( 'test/always-allowed' ) );

		$handler = new ToolsHandler( $server );
		$result  = $handler->list_tools();

		// Verify it returns a ListToolsResult DTO
		$this->assertInstanceOf( ListToolsResult::class, $result );
	}

	public function test_list_tools_applies_tools_list_filter(): void {
		$server  = $this->makeServer( array( 'test/always-allowed' ) );
		$handler = new ToolsHandler( $server );

		// Register a filter that removes all tools.
		$filter = static function (): array {
			return array();
		};
		add_filter( 'mcp_adapter_tools_list', $filter );

		$result = $handler->list_tools();
		$this->assertInstanceOf( ListToolsResult::class, $result );
		$this->assertEmpty( $result->getTools() );

		remove_filter( 'mcp_adapter_tools_list', $filter );
	}

	public function test_list_and_list_all_only_include_json_safe_fields(): void {
		// Use makeServer helper to properly set up the server with registered abilities.
		$server = $this->makeServer( array( 'test/always-allowed' ) );

		$handler     = new ToolsHandler( $server );
		$list_result = $handler->list_tools();
		$all_result  = $handler->list_all_tools();

		// Use DTO getter methods instead of toArray()
		$list_tools = $list_result->getTools();
		$all_tools  = $all_result->getTools();

		$this->assertNotEmpty( $list_tools );
		$this->assertNotEmpty( $all_tools );
		$this->assertContainsOnlyInstancesOf( ToolDto::class, $list_tools );
		$this->assertContainsOnlyInstancesOf( ToolDto::class, $all_tools );

		// Verify Tool DTO structure via toArray() for field checks
		$tool       = $list_tools[0];
		$tool_array = $tool->toArray();
		$this->assertArrayHasKey( 'name', $tool_array );
		$this->assertArrayHasKey( 'description', $tool_array );
		$this->assertArrayHasKey( 'inputSchema', $tool_array );
		$this->assertArrayNotHasKey( 'callback', $tool_array );
		$this->assertArrayNotHasKey( 'permission_callback', $tool_array );

		// list_all_tools now returns the same as list_tools (standard MCP format)
		$tool_all       = $all_tools[0];
		$tool_all_array = $tool_all->toArray();
		$this->assertArrayHasKey( 'name', $tool_all_array );
	}

	public function test_list_tools_withFilterReturningNonArray_fallsBackToOriginal(): void {
		$server  = $this->makeServer( array( 'test/always-allowed' ) );
		$handler = new ToolsHandler( $server );

		$filter = static function (): string {
			return 'not an array';
		};
		add_filter( 'mcp_adapter_tools_list', $filter );

		DummyErrorHandler::reset();
		$result = $handler->list_tools();

		// Should fall back to the original unfiltered list.
		$this->assertInstanceOf( ListToolsResult::class, $result );
		$this->assertNotEmpty( $result->getTools() );

		// Should have logged a warning.
		$this->assertNotEmpty( DummyErrorHandler::$logs );
		$last_log = end( DummyErrorHandler::$logs );
		$this->assertSame( 'warning', $last_log['type'] );
		$this->assertStringContainsString( 'mcp_adapter_tools_list', $last_log['context']['filter'] );

		remove_filter( 'mcp_adapter_tools_list', $filter );
	}
}
