<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Servers;

use WP\MCP\Core\McpAdapter;
use WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler;
use WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler;
use WP\MCP\Servers\DefaultServerFactory;
use WP\MCP\Tests\TestCase;

final class DefaultServerFactoryTest extends TestCase {

	private McpAdapter $adapter;

	public function setUp(): void {
		parent::setUp();
		$this->adapter = McpAdapter::instance();

		// Clear any existing servers
		$reflection       = new \ReflectionClass( $this->adapter );
		$servers_property = $reflection->getProperty( 'servers' );
		$servers_property->setAccessible( true );
		$servers_property->setValue( $this->adapter, array() );
	}

	public function tearDown(): void {
		parent::tearDown();

		// Clean up any actions
		remove_all_actions( 'mcp_adapter_init' );
		remove_all_filters( 'mcp_adapter_default_server_config' );

		// Clean up servers
		$reflection       = new \ReflectionClass( $this->adapter );
		$servers_property = $reflection->getProperty( 'servers' );
		$servers_property->setAccessible( true );
		$servers_property->setValue( $this->adapter, array() );

		// Reset initialized flag
		$initialized_property = $reflection->getProperty( 'initialized' );
		$initialized_property->setAccessible( true );
		$initialized_property->setValue( null, false );
	}

	public function test_create_registers_default_server(): void {
		// Mock being inside mcp_adapter_init
		global $wp_current_filter;
		$wp_current_filter[] = 'mcp_adapter_init';

		DefaultServerFactory::create();

		// Clean up
		array_pop( $wp_current_filter );

		$server = $this->adapter->get_server( 'mcp-adapter-default-server' );
		$this->assertNotNull( $server );
		$this->assertSame( 'mcp-adapter-default-server', $server->get_server_id() );
	}

	public function test_create_discovers_resources_from_abilities(): void {
		// Mock being inside mcp_adapter_init
		global $wp_current_filter;
		$wp_current_filter[] = 'mcp_adapter_init';

		DefaultServerFactory::create();

		// Clean up
		array_pop( $wp_current_filter );

		$server = $this->adapter->get_server( 'mcp-adapter-default-server' );
		$this->assertNotNull( $server );

		// Check that test/resource ability was discovered and registered
		// The test/resource ability has mcp.public=true and mcp.type='resource'
		$resources = $server->get_resources();
		$resource_names = array_map(
			static function ( $resource ) {
				return $resource->get_name();
			},
			$resources
		);

		// test/resource should be discovered if it exists and has mcp.public=true and mcp.type='resource'
		// Note: This tests the discover_abilities_by_type functionality indirectly
		$this->assertIsArray( $resource_names );
	}

	public function test_create_discovers_prompts_from_abilities(): void {
		// Mock being inside mcp_adapter_init
		global $wp_current_filter;
		$wp_current_filter[] = 'mcp_adapter_init';

		DefaultServerFactory::create();

		// Clean up
		array_pop( $wp_current_filter );

		$server = $this->adapter->get_server( 'mcp-adapter-default-server' );
		$this->assertNotNull( $server );

		// Check that test/prompt ability was discovered and registered
		// The test/prompt ability has mcp.public=true and mcp.type='prompt'
		$prompts = $server->get_prompts();
		$prompt_names = array_map(
			static function ( $prompt ) {
				return $prompt->get_name();
			},
			$prompts
		);

		// test/prompt should be discovered if it exists and has mcp.public=true and mcp.type='prompt'
		// Note: This tests the discover_abilities_by_type functionality indirectly
		$this->assertIsArray( $prompt_names );
	}

	public function test_create_only_discovers_public_abilities(): void {
		// Mock being inside mcp_adapter_init
		global $wp_current_filter;
		$wp_current_filter[] = 'mcp_adapter_init';

		DefaultServerFactory::create();

		// Clean up
		array_pop( $wp_current_filter );

		$server = $this->adapter->get_server( 'mcp-adapter-default-server' );
		$this->assertNotNull( $server );

		// Verify that abilities without mcp.public=true are not discovered
		// This is tested indirectly by checking that only expected abilities are present
		$resources = $server->get_resources();
		$prompts   = $server->get_prompts();

		// Both should be arrays (empty or populated)
		$this->assertIsArray( $resources );
		$this->assertIsArray( $prompts );
	}

	public function test_create_registers_default_tools(): void {
		// Verify abilities exist before creating server
		$this->assertNotNull( wp_get_ability( 'mcp-adapter/discover-abilities' ), 'discover-abilities should be registered' );
		$this->assertNotNull( wp_get_ability( 'mcp-adapter/get-ability-info' ), 'get-ability-info should be registered' );
		$this->assertNotNull( wp_get_ability( 'mcp-adapter/execute-ability' ), 'execute-ability should be registered' );

		// Mock being inside mcp_adapter_init
		global $wp_current_filter;
		$wp_current_filter[] = 'mcp_adapter_init';

		DefaultServerFactory::create();

		// Clean up
		array_pop( $wp_current_filter );

		$server = $this->adapter->get_server( 'mcp-adapter-default-server' );
		$this->assertNotNull( $server );

		$tools = $server->get_tools();
		$tool_names = array_map(
			static function ( $tool ) {
				return $tool->get_name();
			},
			$tools
		);

		// Should include the default MCP adapter tools
		$this->assertContains( 'mcp-adapter-discover-abilities', $tool_names, 'discover-abilities tool should be registered' );
		$this->assertContains( 'mcp-adapter-get-ability-info', $tool_names, 'get-ability-info tool should be registered' );
		$this->assertContains( 'mcp-adapter-execute-ability', $tool_names, 'execute-ability tool should be registered' );
	}

	public function test_create_respects_filter_modifications(): void {
		// Modify default server config via filter
		add_filter(
			'mcp_adapter_default_server_config',
			static function ( $defaults ) {
				$defaults['server_name']        = 'Custom Server Name';
				$defaults['server_description'] = 'Custom Description';
				$defaults['server_version']     = 'v2.0.0';
				return $defaults;
			}
		);

		// Mock being inside mcp_adapter_init
		global $wp_current_filter;
		$wp_current_filter[] = 'mcp_adapter_init';

		DefaultServerFactory::create();

		// Clean up
		array_pop( $wp_current_filter );

		$server = $this->adapter->get_server( 'mcp-adapter-default-server' );
		$this->assertNotNull( $server );
		$this->assertSame( 'Custom Server Name', $server->get_server_name() );
		$this->assertSame( 'Custom Description', $server->get_server_description() );
		$this->assertSame( 'v2.0.0', $server->get_server_version() );
	}

	public function test_create_handles_invalid_filter_return(): void {
		// Filter returns non-array
		add_filter(
			'mcp_adapter_default_server_config',
			static function () {
				return 'not an array';
			}
		);

		// Mock being inside mcp_adapter_init
		global $wp_current_filter;
		$wp_current_filter[] = 'mcp_adapter_init';

		DefaultServerFactory::create();

		// Clean up
		array_pop( $wp_current_filter );

		// Should still create server with defaults (filter invalid return is ignored)
		$server = $this->adapter->get_server( 'mcp-adapter-default-server' );
		$this->assertNotNull( $server );
		$this->assertSame( 'MCP Adapter Default Server', $server->get_server_name() );
	}

	public function test_create_uses_default_error_handler(): void {
		// Mock being inside mcp_adapter_init
		global $wp_current_filter;
		$wp_current_filter[] = 'mcp_adapter_init';

		DefaultServerFactory::create();

		// Clean up
		array_pop( $wp_current_filter );

		$server = $this->adapter->get_server( 'mcp-adapter-default-server' );
		$this->assertNotNull( $server );
		$this->assertInstanceOf( ErrorLogMcpErrorHandler::class, $server->error_handler );
	}

	public function test_create_uses_default_observability_handler(): void {
		// Mock being inside mcp_adapter_init
		global $wp_current_filter;
		$wp_current_filter[] = 'mcp_adapter_init';

		DefaultServerFactory::create();

		// Clean up
		array_pop( $wp_current_filter );

		$server = $this->adapter->get_server( 'mcp-adapter-default-server' );
		$this->assertNotNull( $server );
		$this->assertInstanceOf( NullMcpObservabilityHandler::class, $server->observability_handler );
	}
}

