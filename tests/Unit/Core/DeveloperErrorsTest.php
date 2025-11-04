<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Core;

use WP\MCP\Core\McpAdapter;
use WP\MCP\Core\McpServer;
use WP\MCP\Core\McpTransportFactory;
use WP\MCP\Infrastructure\ErrorHandling\NullMcpErrorHandler;
use WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler;
use WP\MCP\Tests\Fixtures\DummyTransport;
use WP\MCP\Tests\TestCase;

final class DeveloperErrorsTest extends TestCase {

	private McpAdapter $adapter;

	public function set_up(): void {
		parent::set_up();
		$this->adapter = McpAdapter::instance();

		// Clear any existing servers
		$reflection       = new \ReflectionClass( $this->adapter );
		$servers_property = $reflection->getProperty( 'servers' );
		$servers_property->setAccessible( true );
		$servers_property->setValue( $this->adapter, array() );
	}

	public function test_creating_server_outside_mcp_adapter_init_triggers_doing_it_wrong(): void {
		// Try to create server outside of mcp_adapter_init
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

		// Should return WP_Error
		$this->assertWPError( $result );
		$this->assertSame( 'invalid_timing', $result->get_error_code() );

		// Verify _doing_it_wrong was called
		$this->assertDoingItWrongTriggered( 'create_server', 'mcp_adapter_init' );
	}

	public function test_duplicate_server_id_triggers_doing_it_wrong(): void {
		// Mock being inside mcp_adapter_init
		global $wp_current_filter;
		$wp_current_filter[] = 'mcp_adapter_init';

		// Create first server
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

		// Verify _doing_it_wrong was called for duplicate ID
		$this->assertDoingItWrongTriggered( 'create_server', 'already exists' );
	}

	public function test_transport_factory_with_nonexistent_class_triggers_doing_it_wrong(): void {
		$server = new McpServer(
			'test-server',
			'mcp/v1',
			'/mcp',
			'Test Server',
			'Test Description',
			'1.0.0',
			array(), // No transports to avoid constructor issues
			NullMcpErrorHandler::class,
			NullMcpObservabilityHandler::class
		);

		$factory = new McpTransportFactory( $server );

		// Try to initialize with nonexistent transport class
		$factory->initialize_transports( array( 'NonExistentTransportClass' ) );

		// Verify _doing_it_wrong was called
		$this->assertNotEmpty( $this->doing_it_wrong_log, 'Expected _doing_it_wrong to be called' );
		$this->assertDoingItWrongTriggered( 'initialize_transports', 'does not exist' );
	}

	public function test_transport_factory_with_invalid_interface_triggers_doing_it_wrong(): void {
		$server = new McpServer(
			'test-server',
			'mcp/v1',
			'/mcp',
			'Test Server',
			'Test Description',
			'1.0.0',
			array(), // No transports to avoid constructor issues
			NullMcpErrorHandler::class,
			NullMcpObservabilityHandler::class
		);

		$factory = new McpTransportFactory( $server );

		// Try to initialize with class that doesn't implement McpTransportInterface
		$factory->initialize_transports( array( \stdClass::class ) );

		// Verify _doing_it_wrong was called
		$this->assertNotEmpty( $this->doing_it_wrong_log, 'Expected _doing_it_wrong to be called' );
		$this->assertDoingItWrongTriggered( 'initialize_transports', 'must implement' );
	}

	public function test_doing_it_wrong_messages_are_helpful_for_developers(): void {
		// Test various error scenarios

		// 1. Server creation outside hook
		$this->adapter->create_server(
			'test',
			'mcp/v1',
			'/mcp',
			'Test',
			'Test',
			'1.0.0',
			array( DummyTransport::class ),
			NullMcpErrorHandler::class
		);

		// 2. Transport with wrong interface
		$server = new McpServer(
			'test-server',
			'mcp/v1',
			'/mcp',
			'Test Server',
			'Test Description',
			'1.0.0',
			array(),
			NullMcpErrorHandler::class,
			NullMcpObservabilityHandler::class
		);

		$factory = new McpTransportFactory( $server );
		$factory->initialize_transports( array( \stdClass::class ) );

		// Verify _doing_it_wrong calls were made
		$this->assertNotEmpty( $this->doing_it_wrong_log, 'Expected _doing_it_wrong calls to be captured' );
		$this->assertDoingItWrongTriggered( 'create_server' );
		$this->assertDoingItWrongTriggered( 'initialize_transports' );
	}

	public function test_no_doing_it_wrong_when_everything_is_correct(): void {
		// Mock being inside mcp_adapter_init
		global $wp_current_filter;
		$wp_current_filter[] = 'mcp_adapter_init';

		// Create server correctly
		$result = $this->adapter->create_server(
			'correct-server',
			'mcp/v1',
			'/mcp',
			'Correct Server',
			'Correct Description',
			'1.0.0',
			array( DummyTransport::class ),
			NullMcpErrorHandler::class,
			NullMcpObservabilityHandler::class
		);

		// Clean up the filter mock
		array_pop( $wp_current_filter );

		// Should succeed without WP_Error
		$this->assertNotWPError( $result );

		// Should be no _doing_it_wrong calls
		$this->assertEmpty( $this->doing_it_wrong_log );
	}
}
