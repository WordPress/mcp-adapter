<?php
/**
 * Tests for McpTransportContext constructor validation.
 *
 * @package WP\MCP\Tests\Unit\Transport\Infrastructure
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Transport\Infrastructure;

use InvalidArgumentException;
use WP\MCP\Core\McpServer;
use WP\MCP\Handlers\Initialize\InitializeHandler;
use WP\MCP\Handlers\Prompts\PromptsHandler;
use WP\MCP\Handlers\Resources\ResourcesHandler;
use WP\MCP\Handlers\System\SystemHandler;
use WP\MCP\Handlers\Tools\ToolsHandler;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\Fixtures\DummyObservabilityHandler;
use WP\MCP\Tests\TestCase;
use WP\MCP\Transport\Infrastructure\McpTransportContext;
use WP\MCP\Transport\Infrastructure\RequestRouter;

/**
 * Test McpTransportContext constructor validation.
 *
 * @since n.e.x.t
 */
final class McpTransportContextTest extends TestCase {

	/**
	 * The MCP server instance used across tests.
	 *
	 * @var \WP\MCP\Core\McpServer
	 */
	private McpServer $server;

	/**
	 * Set up before each test.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->server = $this->makeServer(
			array( 'test-dummy/echo-tool' ),
		);
	}

	/**
	 * Test that providing all required keys creates a valid context.
	 */
	public function test_construct_with_all_required_keys_creates_context(): void {
		$properties = $this->build_required_properties();

		$context = new McpTransportContext( $properties );

		$this->assertInstanceOf( McpTransportContext::class, $context );
		$this->assertSame( $properties['mcp_server'], $context->mcp_server );
		$this->assertSame( $properties['initialize_handler'], $context->initialize_handler );
		$this->assertSame( $properties['tools_handler'], $context->tools_handler );
		$this->assertSame( $properties['resources_handler'], $context->resources_handler );
		$this->assertSame( $properties['prompts_handler'], $context->prompts_handler );
		$this->assertSame( $properties['system_handler'], $context->system_handler );
		$this->assertSame( $properties['observability_handler'], $context->observability_handler );
	}

	/**
	 * Test that request_router is auto-created when not provided.
	 */
	public function test_construct_without_request_router_creates_router_automatically(): void {
		$properties = $this->build_required_properties();

		$context = new McpTransportContext( $properties );

		$this->assertInstanceOf( RequestRouter::class, $context->request_router );
	}

	/**
	 * Test that request_router is used when explicitly provided.
	 */
	public function test_construct_with_request_router_uses_provided_router(): void {
		$properties = $this->build_required_properties();
		// Create a context first to get a RequestRouter instance.
		$temp_context               = new McpTransportContext( $properties );
		$router                     = $temp_context->request_router;
		$properties['request_router'] = $router;

		$context = new McpTransportContext( $properties );

		$this->assertSame( $router, $context->request_router );
	}

	/**
	 * Test that transport_permission_callback defaults to null when not provided.
	 */
	public function test_construct_without_permission_callback_defaults_to_null(): void {
		$properties = $this->build_required_properties();

		$context = new McpTransportContext( $properties );

		$this->assertNull( $context->transport_permission_callback );
	}

	/**
	 * Test that transport_permission_callback is assigned when provided.
	 */
	public function test_construct_with_permission_callback_assigns_callback(): void {
		$properties = $this->build_required_properties();
		$callback   = static function () {
			return true;
		};
		$properties['transport_permission_callback'] = $callback;

		$context = new McpTransportContext( $properties );

		$this->assertSame( $callback, $context->transport_permission_callback );
	}

	/**
	 * Test that error_handler is assigned when provided.
	 */
	public function test_construct_with_error_handler_assigns_handler(): void {
		$properties                   = $this->build_required_properties();
		$error_handler                = new DummyErrorHandler();
		$properties['error_handler']  = $error_handler;

		$context = new McpTransportContext( $properties );

		$this->assertSame( $error_handler, $context->error_handler );
	}

	/**
	 * Test that error_handler defaults to the server's error handler when omitted.
	 */
	public function test_construct_without_error_handler_defaults_to_server_error_handler(): void {
		$properties = $this->build_required_properties();

		$context = new McpTransportContext( $properties );

		$this->assertSame( $this->server->get_error_handler(), $context->error_handler );
	}

	/**
	 * Test that missing a single required key throws InvalidArgumentException.
	 */
	public function test_construct_with_missing_required_key_throws_exception(): void {
		$properties = $this->build_required_properties();
		unset( $properties['tools_handler'] );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Missing required properties for McpTransportContext: tools_handler' );

		new McpTransportContext( $properties );
	}

	/**
	 * Test that missing multiple required keys lists all missing keys.
	 */
	public function test_construct_with_multiple_missing_required_keys_lists_all_missing(): void {
		$properties = $this->build_required_properties();
		unset( $properties['mcp_server'], $properties['system_handler'] );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Missing required properties for McpTransportContext: mcp_server, system_handler' );

		new McpTransportContext( $properties );
	}

	/**
	 * Test that providing an unknown key throws InvalidArgumentException.
	 */
	public function test_construct_with_unknown_key_throws_exception(): void {
		$properties                = $this->build_required_properties();
		$properties['typo_server'] = 'some_value';

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Unknown properties provided to McpTransportContext: typo_server' );

		new McpTransportContext( $properties );
	}

	/**
	 * Test that providing multiple unknown keys lists all unknown keys.
	 */
	public function test_construct_with_multiple_unknown_keys_lists_all_unknown(): void {
		$properties            = $this->build_required_properties();
		$properties['foo']     = 'bar';
		$properties['baz_qux'] = 'quux';

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Unknown properties provided to McpTransportContext: foo, baz_qux' );

		new McpTransportContext( $properties );
	}

	/**
	 * Test that an empty array throws InvalidArgumentException for missing required keys.
	 */
	public function test_construct_with_empty_array_throws_exception(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Missing required properties for McpTransportContext' );

		new McpTransportContext( array() );
	}

	/**
	 * Test that all optional keys can be provided alongside required keys.
	 */
	public function test_construct_with_all_keys_creates_context(): void {
		$properties                                  = $this->build_required_properties();
		$properties['error_handler']                 = new DummyErrorHandler();
		$properties['transport_permission_callback'] = static function () {
			return true;
		};

		// Create context to get a router, then rebuild with it.
		$temp_context                 = new McpTransportContext( $properties );
		$properties['request_router'] = $temp_context->request_router;

		$context = new McpTransportContext( $properties );

		$this->assertInstanceOf( McpTransportContext::class, $context );
		$this->assertSame( $properties['request_router'], $context->request_router );
		$this->assertSame( $properties['error_handler'], $context->error_handler );
		$this->assertSame( $properties['transport_permission_callback'], $context->transport_permission_callback );
	}

	/**
	 * Build the minimal set of required properties for McpTransportContext.
	 *
	 * @return array<string, mixed> Properties array with all required keys.
	 */
	private function build_required_properties(): array {
		return array(
			'mcp_server'            => $this->server,
			'initialize_handler'    => new InitializeHandler( $this->server ),
			'tools_handler'         => new ToolsHandler( $this->server ),
			'resources_handler'     => new ResourcesHandler( $this->server ),
			'prompts_handler'       => new PromptsHandler( $this->server ),
			'system_handler'        => new SystemHandler(),
			'observability_handler' => new DummyObservabilityHandler(),
		);
	}
}
