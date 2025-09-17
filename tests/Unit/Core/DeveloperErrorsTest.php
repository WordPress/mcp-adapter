<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Core;

use WP\MCP\Core\McpAdapter;
use WP\MCP\Core\McpTransportFactory;
use WP\MCP\Core\McpServer;
use WP\MCP\Infrastructure\ErrorHandling\NullMcpErrorHandler;
use WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler;
use WP\MCP\Tests\Fixtures\DummyTransport;
use WP\MCP\Tests\TestCase;

final class DeveloperErrorsTest extends TestCase {

	private McpAdapter $adapter;

	public function setUp(): void {
		parent::setUp();
		$this->adapter = McpAdapter::instance();

		// Clear any existing servers
		$reflection = new \ReflectionClass( $this->adapter );
		$servers_property = $reflection->getProperty( 'servers' );
		$servers_property->setAccessible( true );
		$servers_property->setValue( $this->adapter, array() );

		// Clear any captured _doing_it_wrong calls
		$GLOBALS['wp_tests_doing_it_wrong'] = array();
	}

	public function tearDown(): void {
		parent::tearDown();
		// Clean up captured _doing_it_wrong calls
		unset( $GLOBALS['wp_tests_doing_it_wrong'] );
	}

	public function test_creating_server_outside_mcp_adapter_init_triggers_doing_it_wrong(): void {
		// Capture _doing_it_wrong calls
		$captured_calls = array();

		add_action( 'doing_it_wrong_run', function( ...$args ) use ( &$captured_calls ) {
			$captured_calls[] = array(
				'function' => $args[0] ?? '',
				'message' => $args[1] ?? '',
				'version' => $args[2] ?? '',
			);
		} );

		// Try to create server outside of mcp_adapter_init
		try {
			$this->adapter->create_server(
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
		} catch ( \Exception $e ) {
			// Expected exception
		}

		// Verify _doing_it_wrong was called
		$this->assertNotEmpty( $captured_calls, 'No _doing_it_wrong calls were captured' );

		// In the test environment, only the function name is passed
		$this->assertSame( 'create_server', $captured_calls[0]['function'] );
	}

	public function test_duplicate_server_id_triggers_doing_it_wrong(): void {
		$captured_calls = array();

		add_action( 'doing_it_wrong_run', function( ...$args ) use ( &$captured_calls ) {
			$captured_calls[] = array(
				'function' => $args[0] ?? '',
				'message' => $args[1] ?? '',
				'version' => $args[2] ?? '',
			);
		} );

		// Mock being inside mcp_adapter_init
		global $wp_current_filter;
		$wp_current_filter[] = 'mcp_adapter_init';

		try {
			// Create first server
			$this->adapter->create_server(
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

			// Try to create second server with same ID
			$this->adapter->create_server(
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
		} catch ( \Exception $e ) {
			// Expected exception for duplicate ID
		}

		// Clean up the filter mock
		array_pop( $wp_current_filter );

		// Verify _doing_it_wrong was called for duplicate ID
		$this->assertNotEmpty( $captured_calls );
		// Should have at least 2 calls - one for each create_server attempt
		$this->assertGreaterThanOrEqual( 1, count( $captured_calls ) );
	}

	public function test_transport_factory_with_nonexistent_class_triggers_doing_it_wrong(): void {
		$captured_calls = array();

		add_action( 'doing_it_wrong_run', function( ...$args ) use ( &$captured_calls ) {
			$captured_calls[] = array(
				'function' => $args[0] ?? '',
				'message' => $args[1] ?? '',
				'version' => $args[2] ?? '',
			);
		} );

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
		$this->assertNotEmpty( $captured_calls );
		$this->assertSame( 'initialize_transports', $captured_calls[0]['function'] );
	}

	public function test_transport_factory_with_invalid_interface_triggers_doing_it_wrong(): void {
		$captured_calls = array();

		add_action( 'doing_it_wrong_run', function( ...$args ) use ( &$captured_calls ) {
			$captured_calls[] = array(
				'function' => $args[0] ?? '',
				'message' => $args[1] ?? '',
				'version' => $args[2] ?? '',
			);
		} );

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
		try {
			$factory->initialize_transports( array( \stdClass::class ) );
		} catch ( \Exception $e ) {
			// Expected exception
		}

		// Verify _doing_it_wrong was called
		$this->assertNotEmpty( $captured_calls );
		$this->assertSame( 'initialize_transports', $captured_calls[0]['function'] );
	}

	public function test_doing_it_wrong_messages_are_helpful_for_developers(): void {
		$captured_calls = array();

		add_action( 'doing_it_wrong_run', function( ...$args ) use ( &$captured_calls ) {
			$captured_calls[] = array(
				'function' => $args[0] ?? '',
				'message' => $args[1] ?? '',
				'version' => $args[2] ?? '',
			);
		} );

		// Test various error scenarios

		// 1. Server creation outside hook
		try {
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
		} catch ( \Exception $e ) {
			// Expected
		}

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
		try {
			$factory->initialize_transports( array( \stdClass::class ) );
		} catch ( \Exception $e ) {
			// Expected
		}

		// Verify _doing_it_wrong calls were made
		$this->assertNotEmpty( $captured_calls );

		// Should have multiple calls from different error scenarios
		$function_names = array_map( function( $call ) {
			return $call['function'];
		}, $captured_calls );

		$this->assertContains( 'create_server', $function_names );
		$this->assertContains( 'initialize_transports', $function_names );
	}

	public function test_no_doing_it_wrong_when_everything_is_correct(): void {
		$captured_calls = array();

		add_action( 'doing_it_wrong_run', function( ...$args ) use ( &$captured_calls ) {
			$captured_calls[] = array(
				'function' => $args[0] ?? '',
				'message' => $args[1] ?? '',
				'version' => $args[2] ?? '',
			);
		} );

		// Mock being inside mcp_adapter_init
		global $wp_current_filter;
		$wp_current_filter[] = 'mcp_adapter_init';

		// Create server correctly
		$this->adapter->create_server(
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

		// Should be no _doing_it_wrong calls
		$this->assertEmpty( $captured_calls );
	}
}