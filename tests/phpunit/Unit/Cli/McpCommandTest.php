<?php
/**
 * Tests for McpCommand class.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Cli;

use WP\MCP\Cli\McpCommand;
use WP\MCP\Core\McpAdapter;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\Fixtures\DummyObservabilityHandler;
use WP\MCP\Tests\TestCase;
use WP\MCP\Transport\HttpTransport;

/**
 * Test McpCommand functionality.
 *
 * Note: These tests mock WP-CLI since it's not available in the test environment.
 */
final class McpCommandTest extends TestCase {

	private McpAdapter $adapter;

	public function setUp(): void {
		parent::setUp();

		// Skip if WP-CLI classes aren't available
		if ( ! class_exists( 'WP_CLI_Command' ) ) {
			$this->markTestSkipped( 'WP-CLI not available in test environment' );
		}

		$this->adapter = McpAdapter::instance();

		// Clear any existing servers for clean testing
		$reflection       = new \ReflectionClass( $this->adapter );
		$servers_property = $reflection->getProperty( 'servers' );
		if ( PHP_VERSION_ID < 80100 ) {
			$servers_property->setAccessible( true );
		}
		$servers_property->setValue( $this->adapter, array() );
	}

	public function test_serve_command_handles_runtime_exception_from_bridge(): void {
		// Test when STDIO transport is disabled (should be caught from StdioServerBridge)
		add_filter( 'mcp_adapter_enable_stdio_transport', '__return_false' );

		// Create a test server for the command to use
		global $wp_current_filter;
		$wp_current_filter[] = 'mcp_adapter_init';

		$this->adapter->create_server(
			'test-stdio-server',
			'mcp/v1',
			'/mcp',
			'Test STDIO Server',
			'Test Description',
			'1.0.0',
			array( HttpTransport::class ),
			DummyErrorHandler::class,
			DummyObservabilityHandler::class
		);

		array_pop( $wp_current_filter );

		// Mock WP_CLI::error to capture the call
		if ( ! class_exists( 'WP_CLI' ) ) {
			// Create a mock WP_CLI class for testing
			eval(
				'
				class WP_CLI {
					public static $error_called = false;
					public static $error_message = "";
					public static $debug_called = false;

					public static function error( $message ) {
						self::$error_called = true;
						self::$error_message = $message;
						throw new Exception( "WP_CLI::error called: " . $message );
					}

					public static function debug( $message ) {
						self::$debug_called = true;
					}

					public static function line( $message ) {
						// Mock implementation
					}
				}
			'
			);
		}

		try {
			$command = new McpCommand();
			$command->serve( array(), array() );
			$this->fail( 'Expected WP_CLI::error to be called' );
		} catch ( \Throwable $e ) {
			$this->assertStringContainsString( 'STDIO transport is disabled', $e->getMessage() );
		}

		// Clean up filter
		remove_filter( 'mcp_adapter_enable_stdio_transport', '__return_false' );
	}

	public function test_list_command_with_no_servers(): void {
		// Ensure no servers are registered
		$servers = $this->adapter->get_servers();
		$this->assertEmpty( $servers );

		// Mock WP_CLI::line to capture output
		if ( ! class_exists( 'WP_CLI' ) ) {
			eval(
				'
				class WP_CLI {
					public static $line_called = false;
					public static $line_message = "";

					public static function line( $message ) {
						self::$line_called = true;
						self::$line_message = $message;
					}
				}
			'
			);
		}

		$command = new McpCommand();
		$command->list( array(), array() );

		// In a real test environment, we'd verify WP_CLI::line was called
		// For now, just verify the method completes without error
		$this->assertTrue( true );
	}

	public function test_list_command_with_servers(): void {
		// Create a test server
		global $wp_current_filter;
		$wp_current_filter[] = 'mcp_adapter_init';

		$this->adapter->create_server(
			'test-server',
			'mcp/v1',
			'/mcp',
			'Test Server',
			'Test Description',
			'1.0.0',
			array( HttpTransport::class ),
			DummyErrorHandler::class,
			DummyObservabilityHandler::class,
			array( 'test/always-allowed' ),
			array(),
			array()
		);

		array_pop( $wp_current_filter );

		// Verify server was created
		$servers = $this->adapter->get_servers();
		$this->assertCount( 1, $servers );

		// Mock format_items function if it doesn't exist
		if ( ! function_exists( 'WP_CLI\\Utils\\format_items' ) ) {
			function format_items( $format, $items, $fields ) {
				// Mock implementation for testing
				return true;
			}
		}

		// Test list command
		$command = new McpCommand();
		$command->list( array(), array( 'format' => 'table' ) );

		// If we get here without error, the method handled the server list correctly
		$this->assertTrue( true );
	}

	public function test_command_handles_different_output_formats(): void {
		// Create a test server
		global $wp_current_filter;
		$wp_current_filter[] = 'mcp_adapter_init';

		$this->adapter->create_server(
			'format-test-server',
			'mcp/v1',
			'/mcp',
			'Format Test Server',
			'Test Description',
			'1.0.0',
			array( HttpTransport::class ),
			DummyErrorHandler::class,
			DummyObservabilityHandler::class
		);

		array_pop( $wp_current_filter );

		// Test different formats
		$formats = array( 'table', 'json', 'csv', 'yaml' );

		$command = new McpCommand();

		foreach ( $formats as $format ) {
			$command->list( array(), array( 'format' => $format ) );
			// If we get here, the format was handled without error
			$this->assertTrue( true );
		}
	}
}
