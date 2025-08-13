<?php //phpcs:ignoreFile

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Handlers;

use WP\MCP\Core\McpServer;
use WP\MCP\Handlers\System\SystemHandler;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\Fixtures\DummyObservabilityHandler;
use WP\MCP\Tests\TestCase;

final class SystemHandlerTest extends TestCase
{
    public function test_ping_returns_empty_array(): void
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
        );
        $handler = new SystemHandler($server);
        $this->assertSame([], $handler->ping());
    }

    public function test_set_logging_level_missing_level_returns_error(): void
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
        );
        $handler = new SystemHandler($server);
        $res = $handler->set_logging_level(['params' => []]);
        $this->assertArrayHasKey('error', $res);
    }

    public function test_complete_and_roots_list_return_expected_shapes(): void
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
        );
        $handler = new SystemHandler($server);
        $this->assertTrue($handler->complete()['success']);
        $this->assertArrayHasKey('roots', $handler->list_roots());
    }
}


