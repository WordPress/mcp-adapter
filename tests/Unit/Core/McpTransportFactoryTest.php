<?php
/**
 * Tests for McpTransportFactory class.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Core;

use WP\MCP\Core\McpServer;
use WP\MCP\Core\McpTransportFactory;
use WP\MCP\Handlers\Initialize\InitializeHandler;
use WP\MCP\Handlers\Prompts\PromptsHandler;
use WP\MCP\Handlers\Resources\ResourcesHandler;
use WP\MCP\Handlers\System\SystemHandler;
use WP\MCP\Handlers\Tools\ToolsHandler;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\Fixtures\DummyObservabilityHandler;
use WP\MCP\Tests\Fixtures\DummyTransport;
use WP\MCP\Tests\TestCase;
use WP\MCP\Transport\Infrastructure\McpTransportContext;

/**
 * Test McpTransportFactory functionality.
 */
final class McpTransportFactoryTest extends TestCase {

	private McpTransportFactory $factory;
	private McpServer $server;

	public function set_up(): void {
		parent::set_up();

		$this->server = new McpServer(
			'test-server',
			'mcp/v1',
			'/test-mcp',
			'Test Server',
			'Test server for transport factory',
			'1.0.0',
			array(), // No transports to avoid constructor issues
			DummyErrorHandler::class,
			DummyObservabilityHandler::class
		);

		$this->factory = new McpTransportFactory( $this->server );
	}

	public function test_create_transport_context(): void {
		$context = $this->factory->create_transport_context();

		$this->assertInstanceOf( McpTransportContext::class, $context );

		// Verify all required handlers are created
		$this->assertInstanceOf( InitializeHandler::class, $context->initialize_handler );
		$this->assertInstanceOf( ToolsHandler::class, $context->tools_handler );
		$this->assertInstanceOf( ResourcesHandler::class, $context->resources_handler );
		$this->assertInstanceOf( PromptsHandler::class, $context->prompts_handler );
		$this->assertInstanceOf( SystemHandler::class, $context->system_handler );

		// Verify server reference
		$this->assertSame( $this->server, $context->mcp_server );

		// Verify observability and error handlers
		$this->assertEquals( $this->server->get_observability_handler(), $context->observability_handler );
		$this->assertSame( $this->server->get_error_handler(), $context->error_handler );

		// Verify transport permission callback
		$this->assertSame( $this->server->get_transport_permission_callback(), $context->transport_permission_callback );

		// Verify request router is created
		$this->assertInstanceOf( \WP\MCP\Transport\Infrastructure\RequestRouter::class, $context->request_router );
	}

	public function test_initialize_transports_with_valid_transport(): void {
		// This should not throw an exception
		$this->factory->initialize_transports( array( DummyTransport::class ) );

		// If we get here, the transport was successfully initialized
		$this->assertTrue( true );
	}

	public function test_initialize_transports_with_nonexistent_class(): void {
		// This should trigger _doing_it_wrong but not throw exception
		$this->factory->initialize_transports( array( 'NonExistentTransportClass' ) );

		// If we get here without exception, the method handled the nonexistent class gracefully
		$this->assertTrue( true );
	}

	public function test_initialize_transports_with_invalid_interface(): void {
		// This should trigger _doing_it_wrong but not throw exception
		// The method logs the error and continues processing other transports
		$this->factory->initialize_transports( array( \stdClass::class ) );

		// If we get here without exception, the method handled the invalid interface gracefully
		$this->assertTrue( true );
	}

	public function test_initialize_transports_with_multiple_transports(): void {
		// Test with multiple valid transports
		$this->factory->initialize_transports(
			array(
				DummyTransport::class,
				DummyTransport::class, // Same transport twice should work
			)
		);

		// If we get here, both transports were successfully initialized
		$this->assertTrue( true );
	}

	public function test_initialize_transports_with_mixed_validity(): void {
		// Mix valid and invalid transports
		$this->factory->initialize_transports(
			array(
				'NonExistentClass',
				DummyTransport::class, // This should still work
			)
		);

		// If we get here, the method handled mixed validity gracefully
		$this->assertTrue( true );
	}

	public function test_initialize_transports_with_empty_array(): void {
		// Empty array should not cause issues
		$this->factory->initialize_transports( array() );

		// If we get here, empty array was handled gracefully
		$this->assertTrue( true );
	}

	public function test_create_transport_context_creates_fresh_handlers(): void {
		$context1 = $this->factory->create_transport_context();
		$context2 = $this->factory->create_transport_context();

		// Contexts should be different instances
		$this->assertNotSame( $context1, $context2 );

		// But handlers should be different instances too (fresh creation)
		$this->assertNotSame( $context1->initialize_handler, $context2->initialize_handler );
		$this->assertNotSame( $context1->tools_handler, $context2->tools_handler );
		$this->assertNotSame( $context1->resources_handler, $context2->resources_handler );
		$this->assertNotSame( $context1->prompts_handler, $context2->prompts_handler );
		$this->assertNotSame( $context1->system_handler, $context2->system_handler );

		// But they should reference the same server
		$this->assertSame( $this->server, $context1->mcp_server );
		$this->assertSame( $this->server, $context2->mcp_server );
	}

	public function test_factory_preserves_server_configuration(): void {
		// Create server with specific configuration
		$server_with_callback = new McpServer(
			'callback-server',
			'custom/v1',
			'/custom-mcp',
			'Custom Server',
			'Custom description',
			'2.0.0',
			array(),
			DummyErrorHandler::class,
			DummyObservabilityHandler::class,
			array(),
			array(),
			array(),
			static function () {
				return true; } // Custom permission callback
		);

		$factory = new McpTransportFactory( $server_with_callback );
		$context = $factory->create_transport_context();

		// Verify server configuration is preserved
		$this->assertEquals( 'callback-server', $context->mcp_server->get_server_id() );
		$this->assertEquals( 'custom/v1', $context->mcp_server->get_server_route_namespace() );
		$this->assertEquals( '/custom-mcp', $context->mcp_server->get_server_route() );
		$this->assertEquals( 'Custom Server', $context->mcp_server->get_server_name() );
		$this->assertEquals( '2.0.0', $context->mcp_server->get_server_version() );

		// Verify permission callback is preserved
		$this->assertNotNull( $context->transport_permission_callback );
		$this->assertTrue( call_user_func( $context->transport_permission_callback ) );
	}
}
