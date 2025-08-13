<?php //phpcs:ignoreFile

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Handlers;

use WP\MCP\Core\McpServer;
use WP\MCP\Handlers\Prompts\PromptsHandler;
use WP\MCP\Tests\Fixtures\DummyAbility;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\Fixtures\DummyObservabilityHandler;
use WP\MCP\Tests\TestCase;

final class PromptsHandlerTest extends TestCase
{
    public static function set_up_before_class(): void
    {
        parent::set_up_before_class();
        do_action('abilities_api_init');
        DummyAbility::register_all();
    }

    private function makeServer(array $prompts = []): McpServer
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
            prompts: $prompts,
        );
    }

    public function test_list_prompts_returns_registered_prompts(): void
    {
        wp_set_current_user(1);
        $server = $this->makeServer(['test/prompt']);
        $handler = new PromptsHandler($server);
        $res = $handler->list_prompts();
        $this->assertArrayHasKey('prompts', $res);
        $this->assertNotEmpty($res['prompts']);
    }

    public function test_get_prompt_missing_name_returns_error(): void
    {
        $server = $this->makeServer(['test/prompt']);
        $handler = new PromptsHandler($server);
        $res = $handler->get_prompt(['params' => []]);
        $this->assertArrayHasKey('error', $res);
    }

    public function test_get_prompt_unknown_returns_error(): void
    {
        $server = $this->makeServer(['test/prompt']);
        $handler = new PromptsHandler($server);
        $res = $handler->get_prompt(['params' => ['name' => 'unknown']]);
        $this->assertArrayHasKey('error', $res);
    }

    public function test_get_prompt_success_runs_ability(): void
    {
        $server = $this->makeServer(['test/prompt']);
        $handler = new PromptsHandler($server);
        $res = $handler->get_prompt(['params' => ['name' => 'test-prompt', 'arguments' => ['code' => 'x']]]);
        $this->assertIsArray($res);
    }
}


