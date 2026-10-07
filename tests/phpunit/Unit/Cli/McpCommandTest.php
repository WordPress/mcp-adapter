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
use WP\MCP\Domain\Prompts\McpPrompt;
use WP\MCP\Domain\Resources\McpResource;
use WP\MCP\Domain\Tools\McpTool;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\Fixtures\DummyObservabilityHandler;
use WP\MCP\Tests\Stubs\WpCliStubs;
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
		WpCliStubs::$formatted_output = null;

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

	/**
	 * Counts reflect the requested revision while output columns stay compact.
	 *
	 * @dataProvider protocol_count_provider
	 * @param string|null $protocol Selected revision, or default counts.
	 * @param int $expected_tools Expected available tools.
	 */
	public function test_list_counts_match_selected_protocol( ?string $protocol, int $expected_tools ): void {
		$tool     = McpTool::fromArray(
			array(
				'name'        => 'legacy-only',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'region' => array(
							'type'         => 'number',
							'x-mcp-header' => 'Region',
						),
					),
				),
				'handler'     => static fn(): array => array( 'ok' => true ),
			)
		);
		$resource = McpResource::fromArray(
			array(
				'uri'     => 'test://resource',
				'handler' => static fn(): array => array( 'text' => 'hello' ),
			)
		);
		$prompt   = McpPrompt::fromArray(
			array(
				'name'    => 'test-prompt',
				'handler' => static fn(): array => array( 'text' => 'hello' ),
			)
		);
		$this->assertInstanceOf( McpTool::class, $tool );
		$this->assertInstanceOf( McpResource::class, $resource );
		$this->assertInstanceOf( McpPrompt::class, $prompt );

		$server           = $this->makeServer( array( 'test/always-allowed', $tool ), array( $resource ), array( $prompt ) );
		$reflection       = new \ReflectionClass( $this->adapter );
		$servers_property = $reflection->getProperty( 'servers' );
		if ( PHP_VERSION_ID < 80100 ) {
			$servers_property->setAccessible( true );
		}
		$servers_property->setValue( $this->adapter, array( 'srv' => $server ) );

		foreach ( array( 'table', 'json', 'csv', 'yaml' ) as $format ) {
			$options = array( 'format' => $format );
			if ( null !== $protocol ) {
				$options['protocol'] = $protocol;
			}
			( new McpCommand() )->list( array(), $options );
			$output = WpCliStubs::$formatted_output;
			$this->assertNotNull( $output );
			$this->assertSame( $format, $output['format'] );
			$this->assertSame( array( 'ID', 'Name', 'Version', 'Tools', 'Resources', 'Prompts' ), $output['fields'] );
			$this->assertSame(
				array(
					array(
						'ID'          => 'srv',
						'Name'        => 'Srv',
						'Version'     => '0.0.1',
						'Tools'       => $expected_tools,
						'Resources'   => 1,
						'Prompts'     => 1,
						'Description' => 'desc',
					),
				),
				$output['items']
			);
		}
	}

	/** @return array<string, array{string|null, int}> */
	public static function protocol_count_provider(): array {
		return array(
			'default registration counts' => array( null, 2 ),
			'2025 availability'           => array( '2025-11-25', 2 ),
			'2026 availability'           => array( '2026-07-28', 1 ),
		);
	}

	/**
	 * Invalid selectors fail even when no servers are registered.
	 *
	 * @dataProvider invalid_protocol_provider
	 * @param mixed $protocol Invalid option value.
	 */
	public function test_list_rejects_unsupported_protocol( $protocol ): void {
		$this->expectException( \Throwable::class );
		$this->expectExceptionMessage( 'Unsupported protocol revision. Supported revisions: 2026-07-28, 2025-11-25.' );
		( new McpCommand() )->list( array(), array( 'protocol' => $protocol ) );
	}

	/** @return array<string, array{mixed}> */
	public static function invalid_protocol_provider(): array {
		return array(
			'unknown revision'                         => array( '2099-01-01' ),
			'legacy identifier without its own schema' => array( '2025-06-18' ),
			'empty revision'                           => array( '' ),
			'missing value'                            => array( true ),
		);
	}
}
