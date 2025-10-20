<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Integration;

use WP\MCP\Core\McpServer;
use WP\MCP\Handlers\Tools\ToolsHandler;
use WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface;
use WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler;
use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;
use WP\MCP\Infrastructure\ErrorHandling\NullMcpErrorHandler;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\Fixtures\DummyObservabilityHandler;
use WP\MCP\Tests\TestCase;

final class ErrorHandlingIntegrationTest extends TestCase {

	public function test_error_factory_creates_consistent_errors(): void {
		// Test that all error factory methods return consistent structure
		$errors = array(
			McpErrorFactory::missing_parameter( 1, 'test' ),
			McpErrorFactory::method_not_found( 2, 'test/method' ),
			McpErrorFactory::internal_error( 3, 'test error' ),
			McpErrorFactory::tool_not_found( 4, 'test-tool' ),
			McpErrorFactory::resource_not_found( 5, 'test-resource' ),
			McpErrorFactory::prompt_not_found( 6, 'test-prompt' ),
			McpErrorFactory::permission_denied( 7, 'access denied' ),
			McpErrorFactory::unauthorized( 8, 'not logged in' ),
			McpErrorFactory::parse_error( 9, 'invalid json' ),
			McpErrorFactory::invalid_request( 10, 'bad request' ),
			McpErrorFactory::invalid_params( 11, 'wrong params' ),
			McpErrorFactory::mcp_disabled( 12 ),
		);

		foreach ( $errors as $error ) {
			$this->assertArrayHasKey( 'jsonrpc', $error );
			$this->assertSame( '2.0', $error['jsonrpc'] );
			$this->assertArrayHasKey( 'id', $error );
			$this->assertArrayHasKey( 'error', $error );
			$this->assertArrayHasKey( 'code', $error['error'] );
			$this->assertArrayHasKey( 'message', $error['error'] );
			$this->assertIsInt( $error['error']['code'] );
			$this->assertIsString( $error['error']['message'] );
			$this->assertNotEmpty( $error['error']['message'] );
		}
	}

	public function test_error_handlers_implement_interface(): void {
		$handlers = array(
			new ErrorLogMcpErrorHandler(),
			new NullMcpErrorHandler(),
			new DummyErrorHandler(),
		);

		foreach ( $handlers as $handler ) {
			$this->assertInstanceOf( McpErrorHandlerInterface::class, $handler );
		}
	}

	public function test_server_instantiates_error_handler_correctly(): void {
		$server = new McpServer(
			'test',
			'test/v1',
			'/test',
			'Test Server',
			'Test Description',
			'1.0.0',
			array(),
			DummyErrorHandler::class,
			DummyObservabilityHandler::class,
		);

		$this->assertInstanceOf( McpErrorHandlerInterface::class, $server->error_handler );
		$this->assertInstanceOf( DummyErrorHandler::class, $server->error_handler );
	}

	public function test_handlers_return_consistent_error_format(): void {
		$server  = $this->makeServer( array( 'test/always-allowed' ) );
		$handler = new ToolsHandler( $server );

		// Test missing parameter error
		$result = $handler->call_tool( array( 'params' => array() ) );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertArrayHasKey( 'code', $result['error'] );
		$this->assertSame( McpErrorFactory::INVALID_PARAMS, $result['error']['code'] );

		// Test tool not found error
		$result = $handler->call_tool( array( 'params' => array( 'name' => 'nonexistent-tool' ) ) );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertArrayHasKey( 'code', $result['error'] );
		$this->assertSame( McpErrorFactory::TOOL_NOT_FOUND, $result['error']['code'] );
	}

	public function test_error_logging_works_with_instances(): void {
		$server  = $this->makeServer( array( 'test/permission-exception' ) );
		$handler = new ToolsHandler( $server );

		// This should trigger an error and log it
		$result = $handler->call_tool( array( 'params' => array( 'name' => 'test-permission-exception' ) ) );

		// Permission exceptions are tool execution errors (isError: true)
		$this->assertArrayHasKey( 'isError', $result );
		$this->assertTrue( $result['isError'] );
		$this->assertNotEmpty( DummyErrorHandler::$logs );

		$log = DummyErrorHandler::$logs[0];
		$this->assertArrayHasKey( 'message', $log );
		$this->assertArrayHasKey( 'context', $log );
		$this->assertArrayHasKey( 'type', $log );
	}

	public function test_json_rpc_validation_methods(): void {
		// Valid message
		$valid_message = array(
			'jsonrpc' => '2.0',
			'method'  => 'test',
			'id'      => 1,
		);
		$this->assertTrue( McpErrorFactory::validate_jsonrpc_message( $valid_message ) );

		// Invalid version
		$invalid_message = array(
			'jsonrpc' => '1.0',
			'method'  => 'test',
			'id'      => 1,
		);
		$result          = McpErrorFactory::validate_jsonrpc_message( $invalid_message );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'error', $result );

		// Missing method (but has id and result - response message)
		$response_message = array(
			'jsonrpc' => '2.0',
			'id'      => 1,
			'result'  => array( 'success' => true ),
		);
		$this->assertTrue( McpErrorFactory::validate_jsonrpc_message( $response_message ) );

		// Completely invalid
		$invalid_message = array(
			'jsonrpc' => '2.0',
			'id'      => 1,
			// No method, result, or error
		);
		$result = McpErrorFactory::validate_jsonrpc_message( $invalid_message );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'error', $result );
	}

	public function test_error_codes_are_properly_defined(): void {
		// Test that all error codes are negative integers as per JSON-RPC spec
		$error_codes = array(
			McpErrorFactory::PARSE_ERROR,
			McpErrorFactory::INVALID_REQUEST,
			McpErrorFactory::METHOD_NOT_FOUND,
			McpErrorFactory::INVALID_PARAMS,
			McpErrorFactory::INTERNAL_ERROR,
			McpErrorFactory::SERVER_ERROR,
			McpErrorFactory::RESOURCE_NOT_FOUND,
			McpErrorFactory::TOOL_NOT_FOUND,
			McpErrorFactory::PROMPT_NOT_FOUND,
			McpErrorFactory::PERMISSION_DENIED,
			McpErrorFactory::UNAUTHORIZED,
		);

		foreach ( $error_codes as $code ) {
			$this->assertIsInt( $code );
			$this->assertLessThan( 0, $code );
		}

		// Test that standard JSON-RPC codes are in the right range (-32768 to -32000)
		$this->assertGreaterThanOrEqual( -32768, McpErrorFactory::PARSE_ERROR );
		$this->assertLessThanOrEqual( -32000, McpErrorFactory::PARSE_ERROR );

		// Test that custom MCP codes are in the implementation-defined range (-32000 to -32099)
		$this->assertLessThanOrEqual( -32000, McpErrorFactory::SERVER_ERROR );
		$this->assertGreaterThanOrEqual( -32099, McpErrorFactory::SERVER_ERROR );
	}
}
