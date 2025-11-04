<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Core;

use WP\MCP\Core\McpAdapter;
use WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler;
use WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler;
use WP\MCP\Tests\TestCase;
use WP\MCP\Transport\HttpTransport;

final class McpAdapterConfigTest extends TestCase {

	private McpAdapter $adapter;

	public function set_up(): void {
		parent::set_up();
		$this->adapter = McpAdapter::instance();

		// Clear any existing servers to ensure clean state
		$reflection       = new \ReflectionClass( $this->adapter );
		$servers_property = $reflection->getProperty( 'servers' );
		$servers_property->setAccessible( true );
		$servers_property->setValue( $this->adapter, array() );
	}

	public function tear_down(): void {
		parent::tear_down();

		// Clean up filters first
		remove_all_filters( 'mcp_adapter_default_server_config' );
		remove_all_filters( 'mcp_adapter_create_default_server' );

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

	public function test_default_server_config_filter_allows_customization(): void {
		// Add the filter for customization
		add_filter(
			'mcp_adapter_default_server_config',
			static function ( $defaults ) {
				$defaults['server_name']        = 'Custom Server Name';
				$defaults['server_description'] = 'Custom Description';
				$defaults['server_version']     = 'v2.0.0';
				return $defaults;
			}
		);

		// Ensure abilities API is initialized first (already done in TestCase::set_up_before_class)

		// Reset the initialized flag to allow re-initialization
		$reflection           = new \ReflectionClass( $this->adapter );
		$initialized_property = $reflection->getProperty( 'initialized' );
		$initialized_property->setAccessible( true );
		$initialized_property->setValue( null, false );

		// Mock being inside mcp_adapter_init
		global $wp_current_filter;
		$wp_current_filter[] = 'mcp_adapter_init';

		// Initialize the adapter (this triggers mcp_adapter_init internally)
		$this->adapter->init();

		// Clean up the filter mock
		array_pop( $wp_current_filter );

		// Get the created server
		$server = $this->adapter->get_server( 'mcp-adapter-default-server' );

		$this->assertNotNull( $server );
		$this->assertSame( 'Custom Server Name', $server->get_server_name() );
		$this->assertSame( 'Custom Description', $server->get_server_description() );
		$this->assertSame( 'v2.0.0', $server->get_server_version() );
	}

	public function test_default_server_config_with_invalid_config_uses_defaults(): void {
		// Mock the filter to return invalid config
		add_filter(
			'mcp_adapter_default_server_config',
			static function ( $defaults ) {
				return 'invalid-config'; // Not an array
			}
		);

		// Mock being inside mcp_adapter_init
		global $wp_current_filter;
		$wp_current_filter[] = 'mcp_adapter_init';

		// Initialize the adapter (this triggers mcp_adapter_init internally)
		$this->adapter->init();

		// Clean up the filter mock
		array_pop( $wp_current_filter );

		// Get the created server
		$server = $this->adapter->get_server( 'mcp-adapter-default-server' );

		$this->assertNotNull( $server );
		$this->assertSame( 'MCP Adapter Default Server', $server->get_server_name() );
		$this->assertSame( 'Default MCP server for WordPress abilities discovery and execution', $server->get_server_description() );
	}

	public function test_default_server_config_partial_override(): void {
		// Mock the filter to only change some values
		add_filter(
			'mcp_adapter_default_server_config',
			static function ( $defaults ) {
				$defaults['server_route_namespace'] = 'custom-namespace';
				$defaults['server_route']           = '/custom-route';
				// Leave other values as default
				return $defaults;
			}
		);

		// Mock being inside mcp_adapter_init
		global $wp_current_filter;
		$wp_current_filter[] = 'mcp_adapter_init';

		// Initialize the adapter (this triggers mcp_adapter_init internally)
		$this->adapter->init();

		// Clean up the filter mock
		array_pop( $wp_current_filter );

		// Get the created server
		$server = $this->adapter->get_server( 'mcp-adapter-default-server' );

		$this->assertNotNull( $server );
		$this->assertSame( 'custom-namespace', $server->get_server_route_namespace() );
		$this->assertSame( '/custom-route', $server->get_server_route() );
		// Default values should remain
		$this->assertSame( 'MCP Adapter Default Server', $server->get_server_name() );
		$this->assertSame( 'v1.0.0', $server->get_server_version() );
	}

	public function test_default_server_creation_can_be_disabled(): void {
		// Mock the filter to disable default server creation
		add_filter( 'mcp_adapter_create_default_server', '__return_false' );

		// Mock being inside mcp_adapter_init
		global $wp_current_filter;
		$wp_current_filter[] = 'mcp_adapter_init';

		// Initialize the adapter (this triggers mcp_adapter_init internally)
		$this->adapter->init();

		// Clean up the filter mock
		array_pop( $wp_current_filter );

		// Verify no server was created
		$server = $this->adapter->get_server( 'mcp-adapter-default-server' );
		$this->assertNull( $server );
	}

	public function test_default_server_has_expected_defaults(): void {
		// Initialize the adapter (this triggers mcp_adapter_init internally)
		$this->adapter->init();

		// Get the created server
		$server = $this->adapter->get_server( 'mcp-adapter-default-server' );

		$this->assertNotNull( $server );
		$this->assertSame( 'mcp-adapter-default-server', $server->get_server_id() );
		$this->assertSame( 'mcp', $server->get_server_route_namespace() );
		$this->assertSame( 'mcp-adapter-default-server', $server->get_server_route() );
		$this->assertSame( 'MCP Adapter Default Server', $server->get_server_name() );
		$this->assertSame( 'Default MCP server for WordPress abilities discovery and execution', $server->get_server_description() );
		$this->assertSame( 'v1.0.0', $server->get_server_version() );
	}

	public function test_config_filter_receives_all_expected_keys(): void {
		$received_config = null;

		add_filter(
			'mcp_adapter_default_server_config',
			static function ( $defaults ) use ( &$received_config ) {
				$received_config = $defaults;
				return $defaults;
			}
		);

		// Mock being inside mcp_adapter_init
		global $wp_current_filter;
		$wp_current_filter[] = 'mcp_adapter_init';

		// Initialize the adapter (this triggers mcp_adapter_init internally)
		$this->adapter->init();

		// Clean up the filter mock
		array_pop( $wp_current_filter );

		$this->assertNotNull( $received_config );
		$this->assertArrayHasKey( 'server_id', $received_config );
		$this->assertArrayHasKey( 'server_route_namespace', $received_config );
		$this->assertArrayHasKey( 'server_route', $received_config );
		$this->assertArrayHasKey( 'server_name', $received_config );
		$this->assertArrayHasKey( 'server_description', $received_config );
		$this->assertArrayHasKey( 'server_version', $received_config );
		$this->assertArrayHasKey( 'mcp_transports', $received_config );
		$this->assertArrayHasKey( 'error_handler', $received_config );
		$this->assertArrayHasKey( 'observability_handler', $received_config );
		$this->assertArrayHasKey( 'resources', $received_config );
		$this->assertArrayHasKey( 'prompts', $received_config );
	}

	public function test_config_has_expected_default_values(): void {
		$received_config = null;

		add_filter(
			'mcp_adapter_default_server_config',
			static function ( $defaults ) use ( &$received_config ) {
				$received_config = $defaults;
				return $defaults;
			}
		);

		// Mock being inside mcp_adapter_init
		global $wp_current_filter;
		$wp_current_filter[] = 'mcp_adapter_init';

		// Initialize the adapter (this triggers mcp_adapter_init internally)
		$this->adapter->init();

		// Clean up the filter mock
		array_pop( $wp_current_filter );

		$this->assertSame( 'mcp-adapter-default-server', $received_config['server_id'] );
		$this->assertSame( 'mcp', $received_config['server_route_namespace'] );
		$this->assertSame( 'mcp-adapter-default-server', $received_config['server_route'] );
		$this->assertSame( 'MCP Adapter Default Server', $received_config['server_name'] );
		$this->assertSame( 'v1.0.0', $received_config['server_version'] );
		$this->assertSame( array( HttpTransport::class ), $received_config['mcp_transports'] );
		$this->assertSame( ErrorLogMcpErrorHandler::class, $received_config['error_handler'] );
		$this->assertSame( NullMcpObservabilityHandler::class, $received_config['observability_handler'] );
		// Auto-discovered resources from test fixtures (test/resource has mcp.public=true and mcp.type='resource')
		$this->assertSame( array( 'test/resource' ), $received_config['resources'] );
		// Auto-discovered prompts from test fixtures (test/prompt has mcp.public=true and mcp.type='prompt')
		$this->assertSame( array( 'test/prompt' ), $received_config['prompts'] );
	}

	public function test_multiple_config_modifications(): void {
		// First filter
		add_filter(
			'mcp_adapter_default_server_config',
			static function ( $defaults ) {
				$defaults['server_name'] = 'First Modified Name';
				return $defaults;
			},
			10
		);

		// Second filter with higher priority
		add_filter(
			'mcp_adapter_default_server_config',
			static function ( $defaults ) {
				$defaults['server_name']    = 'Second Modified Name';
				$defaults['server_version'] = 'v3.0.0';
				return $defaults;
			},
			20
		);

		// Mock being inside mcp_adapter_init
		global $wp_current_filter;
		$wp_current_filter[] = 'mcp_adapter_init';

		// Initialize the adapter (this triggers mcp_adapter_init internally)
		$this->adapter->init();

		// Clean up the filter mock
		array_pop( $wp_current_filter );

		// Get the created server
		$server = $this->adapter->get_server( 'mcp-adapter-default-server' );

		$this->assertNotNull( $server );
		$this->assertSame( 'Second Modified Name', $server->get_server_name() );
		$this->assertSame( 'v3.0.0', $server->get_server_version() );
	}

	public function test_default_server_factory_handles_wp_error_from_create_server(): void {
		// Configure default server with invalid error handler to force WP_Error
		add_filter(
			'mcp_adapter_default_server_config',
			static function ( $defaults ) {
				$defaults['error_handler'] = 'NonExistentErrorHandlerClass';
				return $defaults;
			}
		);

		// Mock being inside mcp_adapter_init so create_server() allows the call
		global $wp_current_filter;
		$wp_current_filter[] = 'mcp_adapter_init';

		// Call DefaultServerFactory::create() directly to test error handling
		\WP\MCP\Servers\DefaultServerFactory::create();

		// Clean up the filter mock
		array_pop( $wp_current_filter );

		// Verify _doing_it_wrong was called by DefaultServerFactory
		$this->assertDoingItWrongTriggered( 'WP\MCP\Servers\DefaultServerFactory::create' );

		// Verify server was not created due to error
		$server = $this->adapter->get_server( 'mcp-adapter-default-server' );
		$this->assertNull( $server, 'Server should not be created when create_server returns WP_Error' );
	}
}
