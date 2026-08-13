<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Handlers;

use WP\MCP\Handlers\Tools\ToolsHandler;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\TestCase;
use WP\McpSchema\Server\Tools\DTO\Tool;

final class ToolsHandlerListTest extends TestCase {

	public function test_list_tools_returns_array(): void {
		// Use makeServer helper to properly set up the server with registered abilities.
		$server = $this->makeServer( array( 'test/always-allowed' ) );

		$handler = new ToolsHandler( $server );
		$result  = $handler->list_tools();

		$this->assertArrayHasKey( 'tools', $result );
		$this->assertIsArray( $result['tools'] );
	}

	public function test_list_tools_applies_tools_list_filter(): void {
		$server  = $this->makeServer( array( 'test/always-allowed' ) );
		$handler = new ToolsHandler( $server );

		// The documented hook still receives Tool objects.
		$filter = function ( array $tools ): array {
			$this->assertInstanceOf( Tool::class, $tools[0] );
			$this->assertSame( 'test-always-allowed', $tools[0]->getName() );

			return array();
		};
		add_filter( 'mcp_adapter_tools_list', $filter );

		$result = $handler->list_tools();
		$this->assertSame( array( 'tools' => array() ), $result );

		remove_filter( 'mcp_adapter_tools_list', $filter );
	}

	public function test_list_and_list_all_only_include_json_safe_fields(): void {
		// Use makeServer helper to properly set up the server with registered abilities.
		$server = $this->makeServer( array( 'test/always-allowed' ) );

		$handler     = new ToolsHandler( $server );
		$list_result = $handler->list_tools();
		$all_result  = $handler->list_all_tools();

		$list_tools = $list_result['tools'];
		$all_tools  = $all_result['tools'];

		$this->assertNotEmpty( $list_tools );
		$this->assertNotEmpty( $all_tools );
		$this->assertContainsOnly( 'array', $list_tools );
		$this->assertContainsOnly( 'array', $all_tools );

		$tool_array = $list_tools[0];
		$this->assertArrayHasKey( 'name', $tool_array );
		$this->assertArrayHasKey( 'description', $tool_array );
		$this->assertArrayHasKey( 'inputSchema', $tool_array );
		$this->assertArrayNotHasKey( 'callback', $tool_array );
		$this->assertArrayNotHasKey( 'permission_callback', $tool_array );

		// list_all_tools now returns the same as list_tools (standard MCP format)
		$tool_all_array = $all_tools[0];
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
		$this->assertNotEmpty( $result['tools'] );

		// Should have logged a warning.
		$this->assertNotEmpty( DummyErrorHandler::$logs );
		$last_log = end( DummyErrorHandler::$logs );
		$this->assertSame( 'warning', $last_log['type'] );
		$this->assertStringContainsString( 'mcp_adapter_tools_list', $last_log['context']['filter'] );

		remove_filter( 'mcp_adapter_tools_list', $filter );
	}
}
