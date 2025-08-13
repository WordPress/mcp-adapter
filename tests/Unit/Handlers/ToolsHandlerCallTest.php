<?php //phpcs:ignoreFile

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Handlers;

use WP\MCP\Core\McpServer;
use WP\MCP\Handlers\Tools\ToolsHandler;
use WP\MCP\Tests\Fixtures\DummyAbility;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\Fixtures\DummyObservabilityHandler;
use WP\MCP\Tests\TestCase;

final class ToolsHandlerCallTest extends TestCase
{
    public static function set_up_before_class(): void
    {
        parent::set_up_before_class();
        do_action('abilities_api_init');
        DummyAbility::register_all();
    }

    private function makeServer(array $tools): McpServer
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

    public function test_missing_name_returns_missing_parameter_error(): void
    {
        $server = $this->makeServer(['test/always-allowed']);
        $handler = new ToolsHandler($server);
        $res = $handler->call_tool([ 'params' => [ 'arguments' => [] ] ]);
        $this->assertArrayHasKey('error', $res);
        $this->assertArrayHasKey('code', $res['error']);
    }

    public function test_unknown_tool_logs_and_returns_error(): void
    {
        $server = $this->makeServer(['test/always-allowed']);
        DummyErrorHandler::reset();
        $handler = new ToolsHandler($server);
        $res = $handler->call_tool([ 'params' => [ 'name' => 'nope' ] ]);
        $this->assertArrayHasKey('error', $res);
        $this->assertNotEmpty(DummyErrorHandler::$logs);
    }

    public function test_arguments_trimmed_before_execution(): void
    {
        $server = $this->makeServer(['test/always-allowed']);
        $handler = new ToolsHandler($server);
        $res = $handler->call_tool([
            'params' => [
                'name' => 'test-always-allowed',
                'arguments' => [ 'a' => '', 'b' => 'null', 'c' => 'ok' ],
            ],
        ]);

        $this->assertArrayHasKey('content', $res);
        $this->assertSame('text', $res['content'][0]['type']);
        $payload = json_decode($res['content'][0]['text'], true);
        $this->assertSame(['ok' => true, 'echo' => ['c' => 'ok']], $payload);
    }

    public function test_permission_denied_returns_error(): void
    {
        $server = $this->makeServer(['test/permission-denied']);
        $handler = new ToolsHandler($server);
        $res = $handler->call_tool([
            'params' => [ 'name' => 'test-permission-denied' ],
        ]);
        $this->assertArrayHasKey('error', $res);
        $this->assertArrayHasKey('code', $res['error']);
        $this->assertArrayHasKey('message', $res['error']);
        $this->assertStringContainsString('Permission denied', $res['error']['message']);
    }

    public function test_permission_exception_logs_and_returns_error(): void
    {
        $server = $this->makeServer(['test/permission-exception']);
        DummyErrorHandler::reset();
        $handler = new ToolsHandler($server);
        $res = $handler->call_tool([
            'params' => [ 'name' => 'test-permission-exception' ],
        ]);
        $this->assertArrayHasKey('error', $res);
        $this->assertNotEmpty(DummyErrorHandler::$logs);
    }

    public function test_execute_exception_logs_and_returns_internal_error_envelope(): void
    {
        $server = $this->makeServer(['test/execute-exception']);
        DummyErrorHandler::reset();
        $handler = new ToolsHandler($server);
        $res = $handler->call_tool([
            'params' => [ 'name' => 'test-execute-exception' ],
        ]);
        $this->assertArrayHasKey('error', $res);
        $this->assertArrayHasKey('code', $res['error']);
        $this->assertNotEmpty(DummyErrorHandler::$logs);
    }

    public function test_image_result_is_converted_to_base64_with_mime_type(): void
    {
        $server = $this->makeServer(['test/image']);
        $handler = new ToolsHandler($server);
        $res = $handler->call_tool([
            'params' => [ 'name' => 'test-image' ],
        ]);
        $this->assertSame('image', $res['content'][0]['type']);
        $this->assertArrayHasKey('data', $res['content'][0]);
        $this->assertArrayHasKey('mimeType', $res['content'][0]);
    }
}


