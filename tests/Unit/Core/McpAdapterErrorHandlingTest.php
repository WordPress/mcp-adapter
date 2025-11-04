<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Core;

use WP\MCP\Core\McpAdapter;
use WP\MCP\Infrastructure\ErrorHandling\NullMcpErrorHandler;
use WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler;
use WP\MCP\Tests\Fixtures\DummyTransport;
use WP\MCP\Tests\TestCase;

final class McpAdapterErrorHandlingTest extends TestCase {

	private McpAdapter $adapter;

	public function setUp(): void {
		parent::setUp();
		$this->adapter = McpAdapter::instance();

		// Clear any existing servers to ensure clean state
		$reflection       = new \ReflectionClass( $this->adapter );
		$servers_property = $reflection->getProperty( 'servers' );
		$servers_property->setAccessible( true );
		$servers_property->setValue( $this->adapter, array() );
	}

	public function tearDown(): void {
		parent::tearDown();

		// Clean up any actions that might have been added
		remove_all_actions( 'mcp_adapter_init' );

		// Clean up any registered servers
		$reflection       = new \ReflectionClass( $this->adapter );
		$servers_property = $reflection->getProperty( 'servers' );
		$servers_property->setAccessible( true );
		$servers_property->setValue( $this->adapter, array() );

		// Reset the initialized flag to allow re-initialization
		$initialized_property = $reflection->getProperty( 'initialized' );
		$initialized_property->setAccessible( true );
		$initialized_property->setValue( null, false );
	}

	public function test_create_server_returns_wp_error_when_error_handler_class_does_not_exist(): void {
		// Mock being inside mcp_adapter_init
		global $wp_current_filter;
		$wp_current_filter[] = 'mcp_adapter_init';

		$result = $this->adapter->create_server(
			'test-server',
			'mcp/v1',
			'/mcp',
			'Test Server',
			'Test Description',
			'1.0.0',
			array( DummyTransport::class ),
			'NonExistentErrorHandlerClass',
			NullMcpObservabilityHandler::class
		);

		// Clean up the filter mock
		array_pop( $wp_current_filter );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_error_handler', $result->get_error_code() );
		$this->assertStringContainsString( 'does not exist', $result->get_error_message() );
	}

	public function test_create_server_returns_wp_error_when_error_handler_does_not_implement_interface(): void {
		// Mock being inside mcp_adapter_init
		global $wp_current_filter;
		$wp_current_filter[] = 'mcp_adapter_init';

		$result = $this->adapter->create_server(
			'test-server',
			'mcp/v1',
			'/mcp',
			'Test Server',
			'Test Description',
			'1.0.0',
			array( DummyTransport::class ),
			\stdClass::class, // stdClass exists but doesn't implement the interface
			NullMcpObservabilityHandler::class
		);

		// Clean up the filter mock
		array_pop( $wp_current_filter );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_error_handler', $result->get_error_code() );
		$this->assertStringContainsString( 'must implement', $result->get_error_message() );
	}

	public function test_create_server_returns_wp_error_when_observability_handler_class_does_not_exist(): void {
		// Mock being inside mcp_adapter_init
		global $wp_current_filter;
		$wp_current_filter[] = 'mcp_adapter_init';

		$result = $this->adapter->create_server(
			'test-server',
			'mcp/v1',
			'/mcp',
			'Test Server',
			'Test Description',
			'1.0.0',
			array( DummyTransport::class ),
			NullMcpErrorHandler::class,
			'NonExistentObservabilityHandlerClass'
		);

		// Clean up the filter mock
		array_pop( $wp_current_filter );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_observability_handler', $result->get_error_code() );
		$this->assertStringContainsString( 'does not exist', $result->get_error_message() );
	}

	public function test_create_server_returns_wp_error_when_observability_handler_does_not_implement_interface(): void {
		// Mock being inside mcp_adapter_init
		global $wp_current_filter;
		$wp_current_filter[] = 'mcp_adapter_init';

		$result = $this->adapter->create_server(
			'test-server',
			'mcp/v1',
			'/mcp',
			'Test Server',
			'Test Description',
			'1.0.0',
			array( DummyTransport::class ),
			NullMcpErrorHandler::class,
			\stdClass::class // stdClass exists but doesn't implement the interface
		);

		// Clean up the filter mock
		array_pop( $wp_current_filter );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_observability_handler', $result->get_error_code() );
		$this->assertStringContainsString( 'must implement', $result->get_error_message() );
	}

	public function test_create_server_returns_wp_error_when_called_outside_mcp_adapter_init(): void {
		// Don't mock being inside mcp_adapter_init - call it directly

		$result = $this->adapter->create_server(
			'test-server',
			'mcp/v1',
			'/mcp',
			'Test Server',
			'Test Description',
			'1.0.0',
			array( DummyTransport::class ),
			NullMcpErrorHandler::class,
			NullMcpObservabilityHandler::class
		);

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_timing', $result->get_error_code() );
		$this->assertStringContainsString( 'mcp_adapter_init', $result->get_error_message() );
	}

	public function test_create_server_returns_wp_error_for_duplicate_server_id(): void {
		// Mock being inside mcp_adapter_init
		global $wp_current_filter;
		$wp_current_filter[] = 'mcp_adapter_init';

		// Create first server successfully
		$first_result = $this->adapter->create_server(
			'duplicate-id',
			'mcp/v1',
			'/mcp',
			'First Server',
			'First Description',
			'1.0.0',
			array( DummyTransport::class ),
			NullMcpErrorHandler::class,
			NullMcpObservabilityHandler::class
		);

		// First server should succeed
		$this->assertNotWPError( $first_result );

		// Try to create second server with same ID
		$second_result = $this->adapter->create_server(
			'duplicate-id',
			'mcp/v1',
			'/mcp2',
			'Second Server',
			'Second Description',
			'1.0.0',
			array( DummyTransport::class ),
			NullMcpErrorHandler::class,
			NullMcpObservabilityHandler::class
		);

		// Clean up the filter mock
		array_pop( $wp_current_filter );

		// Second server should return WP_Error
		$this->assertWPError( $second_result );
		$this->assertSame( 'duplicate_server_id', $second_result->get_error_code() );
		$this->assertStringContainsString( 'already exists', $second_result->get_error_message() );
	}

	public function test_create_server_returns_adapter_instance_on_success(): void {
		// Mock being inside mcp_adapter_init
		global $wp_current_filter;
		$wp_current_filter[] = 'mcp_adapter_init';

		$result = $this->adapter->create_server(
			'successful-server',
			'mcp/v1',
			'/mcp',
			'Successful Server',
			'Successful Description',
			'1.0.0',
			array( DummyTransport::class ),
			NullMcpErrorHandler::class,
			NullMcpObservabilityHandler::class
		);

		// Clean up the filter mock
		array_pop( $wp_current_filter );

		// Should return adapter instance, not WP_Error
		$this->assertNotWPError( $result );
		$this->assertInstanceOf( McpAdapter::class, $result );
		$this->assertSame( $this->adapter, $result );

		// Verify server was actually created
		$server = $this->adapter->get_server( 'successful-server' );
		$this->assertNotNull( $server );
	}
}

