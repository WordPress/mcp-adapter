<?php //phpcs:ignoreFile

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Handlers;

use WP\MCP\Core\McpServer;
use WP\MCP\Handlers\Resources\ResourcesHandler;
use WP\MCP\Tests\Fixtures\DummyAbility;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\Fixtures\DummyObservabilityHandler;
use WP\MCP\Tests\TestCase;

final class ResourcesHandlerReadTest extends TestCase
{
    public static function set_up_before_class(): void
    {
        parent::set_up_before_class();
        do_action('abilities_api_init');
        DummyAbility::register_all();
    }

    public function test_missing_uri_returns_error(): void
    {
        wp_set_current_user(1);
        $server = $this->makeServer(['test/resource']);
        $handler = new ResourcesHandler($server);
        $res = $handler->read_resource(['params' => []]);
        $this->assertArrayHasKey('error', $res);
    }

    public function test_unknown_resource_returns_error(): void
    {
        wp_set_current_user(1);
        $server = $this->makeServer();
        $handler = new ResourcesHandler($server);
        $res = $handler->read_resource(['params' => ['uri' => 'WordPress://missing']]);
        $this->assertArrayHasKey('error', $res);
    }

    public function test_successful_read_returns_contents(): void
    {
        wp_set_current_user(1);
        $server = $this->makeServer(['test/resource']);
        $handler = new ResourcesHandler($server);
        $res = $handler->read_resource(['params' => ['uri' => 'WordPress://local/resource-1']]);
        $this->assertArrayHasKey('contents', $res);
    }

    private function makeServer(array $resources = []): McpServer
    {
        return new McpServer(
            server_id: 'srv',
            server_route_namespace: 'mcp/v1',
            server_route: '/mcp',
            server_name: 'Srv',
            server_description: 'desc',
            server_version: '0.0.1',
            mcp_transports: [],
            error_handler: DummyErrorHandler::class,
            observability_handler: DummyObservabilityHandler::class,
            resources: $resources,
        );
    }
}


