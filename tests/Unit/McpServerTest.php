<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit;

use WP\MCP\Core\McpServer;
use WP\MCP\Infrastructure\ErrorHandling\NullMcpErrorHandler;
use WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler;
use WP\MCP\Tests\Fixtures\DummyTransport;
use WP\MCP\Tests\TestCase;

final class McpServerTest extends TestCase {

	public function test_it_initializes_and_exposes_basic_getters(): void {
		$server = new McpServer(
			'test-server',
			'mcp/v1',
			'/mcp',
			'Test MCP',
			'Testing server',
			'0.1.0',
			array( DummyTransport::class ),
			NullMcpErrorHandler::class,
			NullMcpObservabilityHandler::class,
		);

		$this->assertSame( 'test-server', $server->get_server_id() );
		$this->assertSame( 'mcp/v1', $server->get_server_route_namespace() );
		$this->assertSame( '/mcp', $server->get_server_route() );
		$this->assertSame( 'Test MCP', $server->get_server_name() );
		$this->assertSame( 'Testing server', $server->get_server_description() );
		$this->assertSame( '0.1.0', $server->get_server_version() );
	}

	public function test_constructor_properly_sets_up_error_handler(): void {
		$server = new McpServer(
			'test-server',
			'mcp/v1',
			'/mcp',
			'Test MCP',
			'Testing server',
			'0.1.0',
			array( DummyTransport::class ),
			\WP\MCP\Tests\Fixtures\DummyErrorHandler::class,
			NullMcpObservabilityHandler::class,
		);

		$this->assertInstanceOf( \WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface::class, $server->error_handler );
		$this->assertInstanceOf( \WP\MCP\Tests\Fixtures\DummyErrorHandler::class, $server->error_handler );
	}

	public function test_constructor_falls_back_to_null_error_handler(): void {
		$server = new McpServer(
			'test-server',
			'mcp/v1',
			'/mcp',
			'Test MCP',
			'Testing server',
			'0.1.0',
			array( DummyTransport::class ),
			null, // No error handler provided
			NullMcpObservabilityHandler::class,
		);

		$this->assertInstanceOf( NullMcpErrorHandler::class, $server->error_handler );
	}

	public function test_constructor_without_tools_does_not_register_system_tools(): void {
		$server = new McpServer(
			'test-server',
			'mcp/v1',
			'/mcp',
			'Test MCP',
			'Testing server',
			'0.1.0',
			array( DummyTransport::class ),
			NullMcpErrorHandler::class,
			NullMcpObservabilityHandler::class,
			array() // Empty tools array
		);

		$server_tools = $server->get_tools();
		$this->assertEmpty( $server_tools );
	}

	public function test_validation_flag_is_configurable(): void {
		// Test with validation enabled
		add_filter( 'mcp_adapter_validation_enabled', '__return_true' );

		$server = new McpServer(
			'test-server',
			'mcp/v1',
			'/mcp',
			'Test MCP',
			'Testing server',
			'0.1.0',
			array( DummyTransport::class ),
			NullMcpErrorHandler::class,
			NullMcpObservabilityHandler::class
		);

		// Access private property via reflection
		$reflection          = new \ReflectionClass( $server );
		$validation_property = $reflection->getProperty( 'mcp_validation_enabled' );
		$validation_property->setAccessible( true );

		$this->assertTrue( $validation_property->getValue( $server ) );

		// Clean up filter
		remove_filter( 'mcp_adapter_validation_enabled', '__return_true' );
	}
}
