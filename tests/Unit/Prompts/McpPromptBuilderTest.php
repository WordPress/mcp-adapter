<?php //phpcs:ignoreFile

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Prompts;

use InvalidArgumentException;
use WP\MCP\Core\McpServer;
use WP\MCP\Domain\Prompts\McpPromptBuilder;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\Fixtures\DummyObservabilityHandler;
use WP\MCP\Tests\TestCase;

// Test prompt class
class TestPrompt extends McpPromptBuilder
{
    protected function configure(): void
    {
        $this->name = 'test-prompt';
        $this->title = 'Test Prompt';
        $this->description = 'A test prompt for unit testing';
        $this->arguments = [
            $this->create_argument('input', 'Test input', true),
            $this->create_argument('optional', 'Optional parameter', false),
        ];
    }

    public function handle(array $arguments): array
    {
        return [
            'result' => 'success',
            'input' => $arguments['input'] ?? 'no input',
            'optional' => $arguments['optional'] ?? 'default',
        ];
    }

    public function has_permission(array $arguments): bool
    {
        // Test permission logic - always allow for testing
        return true;
    }
}

final class McpPromptBuilderTest extends TestCase
{
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

    public function test_builder_creates_prompt(): void
    {
        $builder = new TestPrompt();
        $prompt = $builder->build();

        $this->assertSame('test-prompt', $prompt->get_name());
        $this->assertSame('Test Prompt', $prompt->get_title());
        $this->assertSame('A test prompt for unit testing', $prompt->get_description());
        
        $arguments = $prompt->get_arguments();
        $this->assertCount(2, $arguments);
        $this->assertSame('input', $arguments[0]['name']);
        $this->assertTrue($arguments[0]['required']);
        $this->assertSame('optional', $arguments[1]['name']);
        $this->assertArrayNotHasKey('required', $arguments[1]);
    }

    public function test_prompt_can_be_registered_with_server(): void
    {
        $server = $this->makeServer();
        $server->register_prompts([TestPrompt::class]);

        $prompts = $server->get_prompts();
        $this->assertCount(1, $prompts);
        $this->assertArrayHasKey('test-prompt', $prompts);
        
        $prompt = $server->get_prompt('test-prompt');
        $this->assertNotNull($prompt);
        $this->assertSame('test-prompt', $prompt->get_name());
    }

    public function test_prompt_execution_bypasses_abilities(): void
    {
        $server = $this->makeServer();
        $server->register_prompts([TestPrompt::class]);
        
        $prompt = $server->get_prompt('test-prompt');
        
        // Verify this is a builder-based prompt
        $this->assertTrue($prompt->is_builder_based());
        
        // Verify abilities are bypassed (get_ability returns null)
        $this->assertNull($prompt->get_ability());
        
        // Test direct permission checking
        $this->assertTrue($prompt->check_permission_direct([]));
        
        // Test direct execution
        $result = $prompt->execute_direct(['input' => 'test value', 'optional' => 'custom']);
        $this->assertSame('success', $result['result']);
        $this->assertSame('test value', $result['input']);
        $this->assertSame('custom', $result['optional']);
    }

    public function test_mixed_registration_abilities_and_builders(): void
    {
        $server = $this->makeServer();
        
        // This should work with mixed registration (though abilities won't exist in test)
        $server->register_prompts([
            TestPrompt::class,
            'some/fake-ability', // This will fail but shouldn't break the builder registration
        ]);

        $prompts = $server->get_prompts();
        // Should have at least the builder prompt even if ability fails
        $this->assertArrayHasKey('test-prompt', $prompts);
    }
}
