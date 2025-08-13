<?php //phpcs:ignoreFile

declare(strict_types=1);

namespace WP\MCP\Tests\Integration;

use WP\MCP\Core\McpServer;
use WP\MCP\Domain\Prompts\McpPromptBuilder;
use WP\MCP\Handlers\Prompts\PromptsHandler;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\Fixtures\DummyObservabilityHandler;
use WP\MCP\Tests\TestCase;

// Test prompt that requires admin permissions
class AdminOnlyPrompt extends McpPromptBuilder
{
    protected function configure(): void
    {
        $this->name = 'admin-only-test';
        $this->title = 'Admin Only Test';
        $this->description = 'A test prompt that requires admin permissions';
        $this->arguments = [
            $this->create_argument('action', 'Action to perform', true),
        ];
    }

    public function has_permission(array $arguments): bool
    {
        // Always deny for testing purposes - regardless of WordPress user permissions
        return false;
    }

    public function handle(array $arguments): array
    {
        return [
            'success' => true,
            'action' => $arguments['action'] ?? 'none',
            'user_can_manage' => current_user_can('manage_options'),
        ];
    }
}

// Test prompt that always allows execution
class OpenPrompt extends McpPromptBuilder
{
    protected function configure(): void
    {
        $this->name = 'open-test';
        $this->title = 'Open Test';
        $this->description = 'A test prompt that allows all users';
        $this->arguments = [];
    }

    public function has_permission(array $arguments): bool
    {
        return true; // Always allow
    }

    public function handle(array $arguments): array
    {
        return [
            'message' => 'Hello from open prompt!',
            'timestamp' => current_time('c'),
        ];
    }
}

final class BuilderPromptExecutionTest extends TestCase
{
    private function makeServer(): McpServer
    {
        return new McpServer(
            server_id: 'test-srv',
            server_route_namespace: 'mcp/v1',
            server_route: '/mcp',
            server_name: 'Test Server',
            server_description: 'Test server for builder prompts',
            server_version: '1.0.0',
            mcp_transports: [],
            error_handler: DummyErrorHandler::class,
            observability_handler: DummyObservabilityHandler::class,
        );
    }

    public function test_builder_prompt_execution_through_handler(): void
    {
        $server = $this->makeServer();
        $server->register_prompts([OpenPrompt::class]);
        
        $handler = new PromptsHandler($server);
        
        // Test successful execution
        $result = $handler->get_prompt([
            'name' => 'open-test',
            'arguments' => [],
        ]);
        
        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame('Hello from open prompt!', $result['message']);
        $this->assertArrayHasKey('timestamp', $result);
    }

    public function test_builder_prompt_permission_denied(): void
    {
        $server = $this->makeServer();
        $server->register_prompts([AdminOnlyPrompt::class]);
        
        $handler = new PromptsHandler($server);
        
        // Test permission denied (always denies in test)
        $result = $handler->get_prompt([
            'name' => 'admin-only-test',
            'arguments' => ['action' => 'delete_everything'],
        ]);
        
        // Should return permission denied error
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Access denied', $result['error']['message'] ?? '');
    }

    public function test_mixed_ability_and_builder_prompts(): void
    {
        $server = $this->makeServer();
        
        // Register both builder and ability-based prompts
        $server->register_prompts([
            OpenPrompt::class,           // Builder-based
            'fake/ability-prompt',       // Ability-based (will fail to register)
        ]);
        
        $prompts = $server->get_prompts();
        
        // Should have the builder prompt even if ability registration failed
        $this->assertArrayHasKey('open-test', $prompts);
        $this->assertTrue($prompts['open-test']->is_builder_based());
    }

    public function test_builder_prompt_bypasses_abilities_completely(): void
    {
        $server = $this->makeServer();
        $server->register_prompts([OpenPrompt::class]);
        
        $prompt = $server->get_prompt('open-test');
        
        // Verify complete ability bypass
        $this->assertTrue($prompt->is_builder_based());
        $this->assertNull($prompt->get_ability());
        
        // Verify direct execution works
        $this->assertTrue($prompt->check_permission_direct([]));
        $result = $prompt->execute_direct([]);
        $this->assertSame('Hello from open prompt!', $result['message']);
    }
}
