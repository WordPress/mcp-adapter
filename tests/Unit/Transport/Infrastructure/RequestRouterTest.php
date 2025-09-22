<?php
/**
 * Tests for RequestRouter class.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Transport\Infrastructure;

use WP\MCP\Core\McpServer;
use WP\MCP\Handlers\Initialize\InitializeHandler;
use WP\MCP\Handlers\Prompts\PromptsHandler;
use WP\MCP\Handlers\Resources\ResourcesHandler;
use WP\MCP\Handlers\System\SystemHandler;
use WP\MCP\Handlers\Tools\ToolsHandler;
use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;
use WP\MCP\Tests\Fixtures\DummyAbility;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\Fixtures\DummyObservabilityHandler;
use WP\MCP\Tests\TestCase;
use WP\MCP\Transport\Infrastructure\HttpRequestContext;
use WP\MCP\Transport\Infrastructure\McpTransportContext;
use WP\MCP\Transport\Infrastructure\RequestRouter;
use WP_REST_Request;

/**
 * Test RequestRouter functionality.
 */
final class RequestRouterTest extends TestCase {

	private RequestRouter $router;
	private McpTransportContext $context;
	private int $test_user_id;

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		do_action( 'abilities_api_init' );
		DummyAbility::register_all();
	}

	public function set_up(): void {
		parent::set_up();

		// Create a test user
		$this->test_user_id = wp_create_user( 'router_test_user', 'test_password', 'router_test@example.com' );
		wp_set_current_user( $this->test_user_id );

		// Create MCP server
		$server = new McpServer(
			'test-server',
			'mcp/v1',
			'/test-mcp',
			'Test MCP Server',
			'Test server for request router',
			'1.0.0',
			array(),
			DummyErrorHandler::class,
			DummyObservabilityHandler::class,
			array( 'test/always-allowed' ),
			array( 'test/resource' ),
			array( 'test/prompt' )
		);

		// Create transport context
		$this->context = $this->createTransportContext( $server );
		$this->router = new RequestRouter( $this->context );
	}

	public function tear_down(): void {
		// Clean up test user
		if ( $this->test_user_id ) {
			delete_user_meta( $this->test_user_id, 'mcp_adapter_sessions' );
			wp_delete_user( $this->test_user_id );
		}

		parent::tear_down();
	}

	public function test_route_request_initialize(): void {
		DummyObservabilityHandler::reset();

		$result = $this->router->route_request(
			'initialize',
			array(
				'protocolVersion' => '2025-06-18',
				'clientInfo'      => array( 'name' => 'test-client', 'version' => '1.0.0' )
			),
			1,
			'test-transport'
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'protocolVersion', $result );
		$this->assertEquals( '2025-06-18', $result['protocolVersion'] );
		$this->assertArrayHasKey( 'serverInfo', $result );

		// Verify observability events
		$this->assertNotEmpty( DummyObservabilityHandler::$events );
		$events = array_column( DummyObservabilityHandler::$events, 'event' );
		$this->assertContains( 'mcp.request.count', $events );
		$this->assertContains( 'mcp.request.success', $events );

		// Verify timing metrics
		$this->assertNotEmpty( DummyObservabilityHandler::$timings );
		$timings = array_column( DummyObservabilityHandler::$timings, 'metric' );
		$this->assertContains( 'mcp.request.duration', $timings );
	}

	public function test_route_request_initialize_with_session(): void {
		$request = new WP_REST_Request( 'POST', '/test-mcp' );
		$http_context = new HttpRequestContext( $request );

		$result = $this->router->route_request(
			'initialize',
			array(
				'protocolVersion' => '2025-06-18',
				'clientInfo'      => array( 'name' => 'test-client', 'version' => '1.0.0' )
			),
			1,
			'test-transport',
			$http_context
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'protocolVersion', $result );
		
		// Should have session ID for HTTP context
		$this->assertArrayHasKey( '_session_id', $result );
		$this->assertIsString( $result['_session_id'] );
	}

	public function test_route_request_tools_list(): void {
		$result = $this->router->route_request( 'tools/list', array(), 1 );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'tools', $result );
		$this->assertIsArray( $result['tools'] );
	}

	public function test_route_request_tools_call(): void {
		$result = $this->router->route_request(
			'tools/call',
			array(
				'name'      => 'test-always-allowed',
				'arguments' => array( 'test' => 'value' )
			),
			1
		);

		$this->assertIsArray( $result );
		// Should either have content or error
		$this->assertTrue( isset( $result['content'] ) || isset( $result['error'] ) );
	}

	public function test_route_request_resources_list(): void {
		$result = $this->router->route_request( 'resources/list', array(), 1 );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'resources', $result );
		$this->assertArrayHasKey( 'nextCursor', $result ); // Cursor compatibility
		$this->assertIsArray( $result['resources'] );
	}

	public function test_route_request_prompts_list(): void {
		$result = $this->router->route_request( 'prompts/list', array(), 1 );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'prompts', $result );
		$this->assertIsArray( $result['prompts'] );
	}

	public function test_route_request_ping(): void {
		$result = $this->router->route_request( 'ping', array(), 1 );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result ); // Ping returns empty array
	}

	public function test_route_request_unknown_method(): void {
		DummyObservabilityHandler::reset();

		$result = $this->router->route_request( 'unknown/method', array(), 1 );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertEquals( McpErrorFactory::METHOD_NOT_FOUND, $result['error']['code'] );
		$this->assertStringContainsString( 'unknown/method', $result['error']['message'] );

		// Verify error event was recorded
		$events = array_column( DummyObservabilityHandler::$events, 'event' );
		$this->assertContains( 'mcp.request.error', $events );
	}

	public function test_route_request_handles_handler_exceptions(): void {
		DummyObservabilityHandler::reset();

		// Test with a tools/call that will cause an exception due to missing tool
		$result = $this->router->route_request(
			'tools/call',
			array( 'name' => 'nonexistent-tool' ), // This will cause an exception in the handler
			1
		);

		$this->assertIsArray( $result );
		// Should either have error from handler or from exception handling
		$this->assertTrue( isset( $result['error'] ) );

		// Verify observability events were recorded
		$events = array_column( DummyObservabilityHandler::$events, 'event' );
		$this->assertContains( 'mcp.request.count', $events );
	}

	public function test_add_cursor_compatibility(): void {
		$result_without_cursor = array(
			'resources' => array(
				array( 'uri' => 'test://resource1' ),
				array( 'uri' => 'test://resource2' ),
			)
		);

		$result = $this->router->add_cursor_compatibility( $result_without_cursor );

		$this->assertArrayHasKey( 'nextCursor', $result );
		$this->assertEquals( '', $result['nextCursor'] );
		$this->assertArrayHasKey( 'resources', $result );
		$this->assertCount( 2, $result['resources'] );
	}

	public function test_add_cursor_compatibility_preserves_existing(): void {
		$result_with_cursor = array(
			'resources'  => array(),
			'nextCursor' => 'existing-cursor-value'
		);

		$result = $this->router->add_cursor_compatibility( $result_with_cursor );

		$this->assertArrayHasKey( 'nextCursor', $result );
		$this->assertEquals( 'existing-cursor-value', $result['nextCursor'] );
	}

	public function test_route_request_observability_metrics(): void {
		DummyObservabilityHandler::reset();

		// Make a request
		$this->router->route_request( 'tools/list', array(), 1, 'test-transport' );

		// Verify events were recorded
		$events = DummyObservabilityHandler::$events;
		$this->assertNotEmpty( $events );

		$event_names = array_column( $events, 'event' );
		$this->assertContains( 'mcp.request.count', $event_names );
		$this->assertContains( 'mcp.request.success', $event_names );

		// Verify timing was recorded
		$timings = DummyObservabilityHandler::$timings;
		$this->assertNotEmpty( $timings );

		$timing_metrics = array_column( $timings, 'metric' );
		$this->assertContains( 'mcp.request.duration', $timing_metrics );

		// Verify tags are included
		$first_event = $events[0];
		$this->assertArrayHasKey( 'tags', $first_event );
		$this->assertArrayHasKey( 'method', $first_event['tags'] );
		$this->assertArrayHasKey( 'transport', $first_event['tags'] );
		$this->assertEquals( 'tools/list', $first_event['tags']['method'] );
		$this->assertEquals( 'test-transport', $first_event['tags']['transport'] );
	}

	private function createTransportContext( McpServer $server ): McpTransportContext {
		// Create handlers
		$initialize_handler = new InitializeHandler( $server );
		$tools_handler      = new ToolsHandler( $server );
		$resources_handler  = new ResourcesHandler( $server );
		$prompts_handler    = new PromptsHandler( $server );
		$system_handler     = new SystemHandler();

		// Create the context - the router will be created automatically
		return new McpTransportContext(
			array(
				'mcp_server'            => $server,
				'initialize_handler'    => $initialize_handler,
				'tools_handler'         => $tools_handler,
				'resources_handler'     => $resources_handler,
				'prompts_handler'       => $prompts_handler,
				'system_handler'        => $system_handler,
				'observability_handler' => DummyObservabilityHandler::class,
				'error_handler'         => new DummyErrorHandler(),
			)
		);
	}
}
