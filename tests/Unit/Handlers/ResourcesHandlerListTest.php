<?php //phpcs:ignoreFile

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Handlers;

use WP\MCP\Core\McpServer;
use WP\MCP\Handlers\Resources\ResourcesHandler;
use WP\MCP\Tests\Fixtures\DummyAbility;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\Fixtures\DummyObservabilityHandler;
use WP\MCP\Tests\TestCase;

final class ResourcesHandlerListTest extends TestCase
{
    public static function set_up_before_class(): void
    {
        parent::set_up_before_class();
        do_action('abilities_api_init');
        DummyAbility::register_all();
    }

    public function test_list_resources_returns_registered_resources(): void
    {
        // Simulate logged-in for permission check.
        wp_set_current_user(1);

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
            resources: ['test/resource'],
        );

        $handler = new ResourcesHandler($server);
        $res = $handler->list_resources();
        $this->assertArrayHasKey('resources', $res);
        $this->assertNotEmpty($res['resources']);
        $this->assertArrayHasKey('uri', $res['resources'][0]);
    }
}


