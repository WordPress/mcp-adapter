<?php //phpcs:ignoreFile

declare(strict_types=1);

namespace WP\MCP\Tests\Unit;

use WP\MCP\Core\McpServer;
use WP\MCP\Transport\Infrastructure\McpTransportContext;
use WP\MCP\Transport\Infrastructure\McpRequestRouter;
use WP\MCP\Handlers\Initialize\InitializeHandler;
use WP\MCP\Handlers\Tools\ToolsHandler;
use WP\MCP\Handlers\Resources\ResourcesHandler;
use WP\MCP\Handlers\Prompts\PromptsHandler;
use WP\MCP\Handlers\System\SystemHandler;
use WP\MCP\Tests\Fixtures\DummyAbility;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\Fixtures\DummyObservabilityHandler;
use WP\MCP\Tests\Fixtures\DummyTransport;
use WP\MCP\Tests\TestCase;

final class McpTransportTest extends TestCase
{
    public static function set_up_before_class(): void
    {
        parent::set_up_before_class();
        // Make sure abilities API is initialized
        do_action('abilities_api_init');
        DummyAbility::register_all();
    }

    public function test_transport_helper_trait_normalizes_class_name(): void
    {
        $server = $this->makeServer();
        $context = $this->createTransportContext($server);
        $transport = new DummyTransport($context);

        $ref = new \ReflectionClass($transport);
        $method = $ref->getMethod('get_transport_name');
        $method->setAccessible(true);
        $name = $method->invoke($transport);

        $this->assertIsString($name);
        $this->assertNotSame('', $name);
    }

    public function test_transport_routes_requests_successfully_with_metrics(): void
    {
        $server = $this->makeServer([ 'test/always-allowed' ]);
        $context = $this->createTransportContext($server);
        $transport = new DummyTransport($context);

        DummyObservabilityHandler::reset();

        $res = $transport->test_route_request('tools/list', []);
        $this->assertIsArray($res);
        $this->assertArrayHasKey('tools', $res);

        // metrics
        $this->assertNotEmpty(DummyObservabilityHandler::$events);
        $this->assertNotEmpty(DummyObservabilityHandler::$timings);
        $eventMetrics = array_column(DummyObservabilityHandler::$events, 'event');
        $this->assertContains('mcp.request.count', $eventMetrics);
        $this->assertContains('mcp.request.success', $eventMetrics);
    }

    public function test_transport_handles_unknown_methods_with_error_metrics(): void
    {
        $server = $this->makeServer();
        $context = $this->createTransportContext($server);
        $transport = new DummyTransport($context);
        DummyObservabilityHandler::reset();

        $res = $transport->test_route_request('unknown/method', []);
        $this->assertArrayHasKey('error', $res);

        $this->assertNotEmpty(DummyObservabilityHandler::$events);
        $this->assertNotEmpty(DummyObservabilityHandler::$timings);
    }

    private function makeServer(array $tools = []): McpServer
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
            tools: $tools,
        );
    }

    private function createTransportContext(McpServer $server): McpTransportContext
    {
        // Create handlers
        $initialize_handler = new InitializeHandler($server);
        $tools_handler      = new ToolsHandler($server);
        $resources_handler  = new ResourcesHandler($server);
        $prompts_handler    = new PromptsHandler($server);
        $system_handler     = new SystemHandler($server);

        // Create context for the router first (without router to avoid circular dependency)
        $router_context = new McpTransportContext(
            mcp_server: $server,
            initialize_handler: $initialize_handler,
            tools_handler: $tools_handler,
            resources_handler: $resources_handler,
            prompts_handler: $prompts_handler,
            system_handler: $system_handler,
            observability_handler: DummyObservabilityHandler::class,
            request_router: null
        );

        // Create the router
        $request_router = new McpRequestRouter($router_context);

        // Create the final context with the router
        return new McpTransportContext(
            mcp_server: $server,
            initialize_handler: $initialize_handler,
            tools_handler: $tools_handler,
            resources_handler: $resources_handler,
            prompts_handler: $prompts_handler,
            system_handler: $system_handler,
            observability_handler: DummyObservabilityHandler::class,
            request_router: $request_router
        );
    }
}


