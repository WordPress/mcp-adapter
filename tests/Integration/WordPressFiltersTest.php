<?php //phpcs:ignoreFile

declare(strict_types=1);

namespace WP\MCP\Tests\Integration;

use WP\MCP\Core\McpServer;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\Fixtures\DummyObservabilityHandler;
use WP\MCP\Tests\TestCase;

final class WordPressFiltersTest extends TestCase
{
    public function test_validation_toggle_filter_is_respected(): void
    {
        add_filter('mcp_validation_enabled', '__return_false');

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

        $this->assertFalse($server->is_mcp_validation_enabled());

        remove_filter('mcp_validation_enabled', '__return_false');
    }
}


