<?php //phpcs:ignoreFile

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Handlers;

use WP\MCP\Core\McpServer;
use WP\MCP\Handlers\Tools\ToolsHandler;
use WP\MCP\Tests\Fixtures\DummyAbility;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\Fixtures\DummyObservabilityHandler;
use WP\MCP\Tests\TestCase;

final class ToolsHandlerListTest extends TestCase
{
    public static function set_up_before_class(): void
    {
        parent::set_up_before_class();
        do_action('abilities_api_init');
        DummyAbility::register_all();
    }

    public function test_list_and_list_all_only_include_json_safe_fields(): void
    {
        $server = new McpServer(
            server_id: 'srv',
            server_route_namespace: 'mcp/v1',
            server_route: '/mcp',
            server_name: 'Srv',
            server_description: 'desc',
            server_version: '0.0.1',
            mcp_transports: [],
            error_handler: DummyErrorHandler::class,
            observability_handler: DummyObservabilityHandler::class,
            tools: ['test/always-allowed'],
        );

        $handler = new ToolsHandler($server);
        $list = $handler->list_tools();
        $all = $handler->list_all_tools();

        $this->assertArrayHasKey('tools', $list);
        $this->assertArrayHasKey('tools', $all);
        $this->assertNotEmpty($list['tools']);

        $tool = $list['tools'][0];
        $this->assertArrayHasKey('name', $tool);
        $this->assertArrayHasKey('description', $tool);
        $this->assertArrayHasKey('inputSchema', $tool);
        $this->assertArrayNotHasKey('callback', $tool);
        $this->assertArrayNotHasKey('permission_callback', $tool);

        $toolAll = $all['tools'][0];
        $this->assertTrue($toolAll['available']);
    }
}


