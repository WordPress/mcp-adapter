<?php //phpcs:ignoreFile

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Resources;

use InvalidArgumentException;
use WP\MCP\Core\McpServer;
use WP\MCP\Domain\Resources\RegisterAbilityAsMcpResource;
use WP\MCP\Tests\Fixtures\DummyAbility;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\Fixtures\DummyObservabilityHandler;
use WP\MCP\Tests\TestCase;

final class RegisterAbilityAsMcpResourceTest extends TestCase
{
    public static function set_up_before_class(): void
    {
        parent::set_up_before_class();
        do_action('abilities_api_init');
        DummyAbility::register_all();
    }

    private function makeServer(): McpServer
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
        );
    }

    public function test_make_builds_resource_from_ability(): void
    {
        $resource = RegisterAbilityAsMcpResource::make('test/resource', $this->makeServer());
        $arr = $resource->to_array();
        $this->assertSame('WordPress://local/resource-1', $arr['uri']);
    }

    public function test_make_invalid_ability_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RegisterAbilityAsMcpResource::make('test/missing', $this->makeServer());
    }
}


