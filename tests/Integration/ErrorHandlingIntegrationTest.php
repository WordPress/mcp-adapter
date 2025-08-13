<?php //phpcs:ignoreFile

declare(strict_types=1);

namespace WP\MCP\Tests\Integration;

use WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler;
use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;
use WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface;
use WP\MCP\Infrastructure\ErrorHandling\NullMcpErrorHandler;
use WP\MCP\Core\McpServer;
use WP\MCP\Handlers\Tools\ToolsHandler;
use WP\MCP\Tests\Fixtures\DummyAbility;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\Fixtures\DummyObservabilityHandler;
use WP\MCP\Tests\TestCase;

final class ErrorHandlingIntegrationTest extends TestCase
{
    public static function set_up_before_class(): void
    {
        parent::set_up_before_class();
        do_action('abilities_api_init');
        DummyAbility::register_all();
    }

    public function test_error_factory_creates_consistent_errors(): void
    {
        // Test that all error factory methods return consistent structure
        $errors = [
            McpErrorFactory::missing_parameter(1, 'test'),
            McpErrorFactory::method_not_found(2, 'test/method'),
            McpErrorFactory::internal_error(3, 'test error'),
            McpErrorFactory::tool_not_found(4, 'test-tool'),
            McpErrorFactory::resource_not_found(5, 'test-resource'),
            McpErrorFactory::prompt_not_found(6, 'test-prompt'),
            McpErrorFactory::permission_denied(7, 'access denied'),
            McpErrorFactory::unauthorized(8, 'not logged in'),
            McpErrorFactory::parse_error(9, 'invalid json'),
            McpErrorFactory::invalid_request(10, 'bad request'),
            McpErrorFactory::invalid_params(11, 'wrong params'),
            McpErrorFactory::mcp_disabled(12),
        ];

        foreach ($errors as $error) {
            $this->assertArrayHasKey('jsonrpc', $error);
            $this->assertSame('2.0', $error['jsonrpc']);
            $this->assertArrayHasKey('id', $error);
            $this->assertArrayHasKey('error', $error);
            $this->assertArrayHasKey('code', $error['error']);
            $this->assertArrayHasKey('message', $error['error']);
            $this->assertIsInt($error['error']['code']);
            $this->assertIsString($error['error']['message']);
            $this->assertNotEmpty($error['error']['message']);
        }
    }

    public function test_error_handlers_implement_interface(): void
    {
        $handlers = [
            new ErrorLogMcpErrorHandler(),
            new NullMcpErrorHandler(),
            new DummyErrorHandler(),
        ];

        foreach ($handlers as $handler) {
            $this->assertInstanceOf(McpErrorHandlerInterface::class, $handler);
        }
    }

    public function test_server_instantiates_error_handler_correctly(): void
    {
        $server = new McpServer(
            server_id: 'test',
            server_route_namespace: 'test/v1',
            server_route: '/test',
            server_name: 'Test Server',
            server_description: 'Test Description',
            server_version: '1.0.0',
            mcp_transports: [],
            error_handler: DummyErrorHandler::class,
            observability_handler: DummyObservabilityHandler::class,
        );

        $this->assertInstanceOf(McpErrorHandlerInterface::class, $server->error_handler);
        $this->assertInstanceOf(DummyErrorHandler::class, $server->error_handler);
    }

    public function test_handlers_return_consistent_error_format(): void
    {
        $server = $this->makeServer(['test/always-allowed']);
        $handler = new ToolsHandler($server);

        // Test missing parameter error
        $result = $handler->call_tool(['params' => []]);
        $this->assertArrayHasKey('error', $result);
        $this->assertArrayHasKey('code', $result['error']);
        $this->assertSame(McpErrorFactory::MISSING_PARAMETER, $result['error']['code']);

        // Test tool not found error
        $result = $handler->call_tool(['params' => ['name' => 'nonexistent-tool']]);
        $this->assertArrayHasKey('error', $result);
        $this->assertArrayHasKey('code', $result['error']);
        $this->assertSame(McpErrorFactory::TOOL_NOT_FOUND, $result['error']['code']);
    }

    public function test_error_logging_works_with_instances(): void
    {
        $server = $this->makeServer(['test/permission-exception']);
        $handler = new ToolsHandler($server);
        
        DummyErrorHandler::reset();
        
        // This should trigger an error and log it
        $result = $handler->call_tool(['params' => ['name' => 'test-permission-exception']]);
        
        $this->assertArrayHasKey('error', $result);
        $this->assertNotEmpty(DummyErrorHandler::$logs);
        
        $log = DummyErrorHandler::$logs[0];
        $this->assertArrayHasKey('message', $log);
        $this->assertArrayHasKey('context', $log);
        $this->assertArrayHasKey('type', $log);
    }

    public function test_json_rpc_validation_methods(): void
    {
        // Valid message
        $validMessage = [
            'jsonrpc' => '2.0',
            'method' => 'test',
            'id' => 1
        ];
        $this->assertTrue(McpErrorFactory::validate_jsonrpc_message($validMessage));

        // Invalid version
        $invalidMessage = [
            'jsonrpc' => '1.0',
            'method' => 'test',
            'id' => 1
        ];
        $result = McpErrorFactory::validate_jsonrpc_message($invalidMessage);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('error', $result);

        // Missing method (but has id and result - response message)
        $responseMessage = [
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => ['success' => true]
        ];
        $this->assertTrue(McpErrorFactory::validate_jsonrpc_message($responseMessage));

        // Completely invalid
        $invalidMessage = [
            'jsonrpc' => '2.0',
            'id' => 1
            // No method, result, or error
        ];
        $result = McpErrorFactory::validate_jsonrpc_message($invalidMessage);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('error', $result);
    }

    public function test_error_codes_are_properly_defined(): void
    {
        // Test that all error codes are negative integers as per JSON-RPC spec
        $errorCodes = [
            McpErrorFactory::PARSE_ERROR,
            McpErrorFactory::INVALID_REQUEST,
            McpErrorFactory::METHOD_NOT_FOUND,
            McpErrorFactory::INVALID_PARAMS,
            McpErrorFactory::INTERNAL_ERROR,
            McpErrorFactory::MCP_DISABLED,
            McpErrorFactory::MISSING_PARAMETER,
            McpErrorFactory::RESOURCE_NOT_FOUND,
            McpErrorFactory::TOOL_NOT_FOUND,
            McpErrorFactory::PROMPT_NOT_FOUND,
            McpErrorFactory::PERMISSION_DENIED,
            McpErrorFactory::UNAUTHORIZED,
        ];

        foreach ($errorCodes as $code) {
            $this->assertIsInt($code);
            $this->assertLessThan(0, $code);
        }

        // Test that standard JSON-RPC codes are in the right range (-32768 to -32000)
        $this->assertGreaterThanOrEqual(-32768, McpErrorFactory::PARSE_ERROR);
        $this->assertLessThanOrEqual(-32000, McpErrorFactory::PARSE_ERROR);

        // Test that custom MCP codes are in the implementation-defined range (-32000 to -32099)
        $this->assertLessThanOrEqual(-32000, McpErrorFactory::MISSING_PARAMETER);
        $this->assertGreaterThanOrEqual(-32099, McpErrorFactory::MISSING_PARAMETER);
    }

    private function makeServer(array $tools = []): McpServer
    {
        return new McpServer(
            server_id: 'test',
            server_route_namespace: 'test/v1',
            server_route: '/test',
            server_name: 'Test Server',
            server_description: 'Test Description',
            server_version: '1.0.0',
            mcp_transports: [],
            error_handler: DummyErrorHandler::class,
            observability_handler: DummyObservabilityHandler::class,
            tools: $tools,
        );
    }
}
