<?php //phpcs:ignoreFile

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Prompts;

use InvalidArgumentException;
use WP\MCP\Core\McpServer;
use WP\MCP\Domain\Prompts\RegisterAbilityAsMcpPrompt;
use WP\MCP\Tests\Fixtures\DummyAbility;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\Fixtures\DummyObservabilityHandler;
use WP\MCP\Tests\TestCase;

final class RegisterAbilityAsMcpPromptTest extends TestCase
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

    public function test_make_builds_prompt_from_ability(): void
    {
        $prompt = RegisterAbilityAsMcpPrompt::make('test/prompt', $this->makeServer());
        $arr = $prompt->to_array();
        $this->assertSame('test-prompt', $arr['name']);
        $this->assertArrayHasKey('arguments', $arr);
    }

    public function test_make_invalid_ability_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RegisterAbilityAsMcpPrompt::make('test/missing', $this->makeServer());
    }
}


