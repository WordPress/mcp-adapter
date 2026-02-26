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
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\Fixtures\DummyObservabilityHandler;
use WP\MCP\Tests\TestCase;
use WP\MCP\Transport\Infrastructure\HttpRequestContext;
use WP\MCP\Transport\Infrastructure\McpTransportContext;
use WP\MCP\Transport\Infrastructure\RequestRouter;
use WP\McpSchema\Common\McpConstants;
use WP_REST_Request;

/**
 * Test RequestRouter functionality.
 */
final class RequestRouterTest extends TestCase {

	private RequestRouter $router;
	private McpTransportContext $context;
	private int $test_user_id;

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
			array( 'test/always-allowed', 'test/meta-leak' ),
			array( 'test/resource' ),
			array( 'test/prompt' )
		);

		// Create transport context
		$this->context = $this->createTransportContext( $server );
		$this->router  = new RequestRouter( $this->context );
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
		$result = $this->router->route_request(
			'initialize',
			array(
				'protocolVersion' => '2025-11-25',
				'clientInfo'      => array(
					'name'    => 'test-client',
					'version' => '1.0.0',
				),
			),
			1,
			'test-transport'
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'protocolVersion', $result );
		$this->assertEquals( McpConstants::LATEST_PROTOCOL_VERSION, $result['protocolVersion'] );
		$this->assertArrayHasKey( 'serverInfo', $result );

		// Verify observability events (unified event name with status tag)
		$this->assertNotEmpty( DummyObservabilityHandler::$events );
		$events = array_column( DummyObservabilityHandler::$events, 'event' );
		$this->assertContains( 'mcp.request', $events );

		// Verify timing and status tag are included in the event
		$request_event = array_filter(
			DummyObservabilityHandler::$events,
			static function ( $event ) {
				return 'mcp.request' === $event['event'] && isset( $event['tags']['status'] ) && 'success' === $event['tags']['status'];
			}
		);
		$this->assertNotEmpty( $request_event );
		$first_request = reset( $request_event );
		$this->assertNotNull( $first_request['duration_ms'] );
		$this->assertGreaterThan( 0, $first_request['duration_ms'] );
	}

	public function test_route_request_initialize_with_session(): void {
		$request      = new WP_REST_Request( 'POST', '/test-mcp' );
		$http_context = new HttpRequestContext( $request );

		$result = $this->router->route_request(
			'initialize',
			array(
				'protocolVersion' => '2025-11-25',
				'clientInfo'      => array(
					'name'    => 'test-client',
					'version' => '1.0.0',
				),
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
		$this->assertNotEmpty( $result['tools'] );
		$this->assertContainsOnly( 'array', $result['tools'] );
	}

	public function test_route_request_tools_call(): void {
		$result = $this->router->route_request(
			'tools/call',
			array(
				'name'      => 'test-always-allowed',
				'arguments' => array( 'test' => 'value' ),
			),
			1
		);

		$this->assertIsArray( $result );
		// Should either have content or error
		$this->assertTrue( isset( $result['content'] ) || isset( $result['error'] ) );
	}

	public function test_route_request_tools_call_preserves_meta_in_text_content(): void {
		$result = $this->router->route_request(
			'tools/call',
			array(
				'name'      => 'test-meta-leak',
				'arguments' => array(),
			),
			1
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'content', $result );
		$this->assertIsArray( $result['content'] );
		$this->assertNotEmpty( $result['content'] );
		$this->assertSame( 'text', $result['content'][0]['type'] );
		$this->assertIsString( $result['content'][0]['text'] );
		$this->assertStringContainsString( 'mcp_adapter', $result['content'][0]['text'] );
	}

	public function test_route_request_resources_list(): void {
		$result = $this->router->route_request( 'resources/list', array(), 1 );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'resources', $result );
		$this->assertIsArray( $result['resources'] );
		$this->assertNotEmpty( $result['resources'] );
		$this->assertContainsOnly( 'array', $result['resources'] );
	}

	public function test_route_request_prompts_list(): void {
		$result = $this->router->route_request( 'prompts/list', array(), 1 );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'prompts', $result );
		$this->assertIsArray( $result['prompts'] );
		$this->assertNotEmpty( $result['prompts'] );
		$this->assertContainsOnly( 'array', $result['prompts'] );
	}

	public function test_route_request_ping(): void {
		$result = $this->router->route_request( 'ping', array(), 1 );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result ); // Ping returns empty array
	}

	public function test_route_request_unknown_method(): void {
		$result = $this->router->route_request( 'unknown/method', array(), 1 );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertArrayNotHasKey( 'jsonrpc', $result );
		$this->assertArrayNotHasKey( 'id', $result );
		$this->assertEquals( McpErrorFactory::METHOD_NOT_FOUND, $result['error']['code'] );
		$this->assertStringContainsString( 'unknown/method', $result['error']['message'] );

		// Verify error event was recorded with status tag
		$events = array_column( DummyObservabilityHandler::$events, 'event' );
		$this->assertContains( 'mcp.request', $events );

		// Check for error status tag
		$error_event = array_filter(
			DummyObservabilityHandler::$events,
			static function ( $event ) {
				return 'mcp.request' === $event['event'] && isset( $event['tags']['status'] ) && 'error' === $event['tags']['status'];
			}
		);
		$this->assertNotEmpty( $error_event );
	}

	public function test_route_request_handles_handler_exceptions(): void {
		// Test with a tools/call that will cause an exception due to missing tool
		$result = $this->router->route_request(
			'tools/call',
			array( 'name' => 'nonexistent-tool' ), // This will cause an exception in the handler
			1
		);

		$this->assertIsArray( $result );
		// Should either have error from handler or from exception handling
		$this->assertTrue( isset( $result['error'] ) );

		// Verify observability events were recorded (error event with duration)
		$events = array_column( DummyObservabilityHandler::$events, 'event' );
		$this->assertContains( 'mcp.request', $events );

		// Check for error status tag
		$error_event = array_filter(
			DummyObservabilityHandler::$events,
			static function ( $event ) {
				return 'mcp.request' === $event['event'] && isset( $event['tags']['status'] ) && 'error' === $event['tags']['status'];
			}
		);
		$this->assertNotEmpty( $error_event );
	}

	public function test_route_request_observability_metrics(): void {
		// Make a request
		$this->router->route_request( 'tools/list', array(), 1, 'test-transport' );

		// Verify events were recorded (unified event name with status tag)
		$events = DummyObservabilityHandler::$events;
		$this->assertNotEmpty( $events );

		$event_names = array_column( $events, 'event' );
		$this->assertContains( 'mcp.request', $event_names );

		// Verify timing and status tag are included in the event
		$success_event = array_filter(
			$events,
			static function ( $event ) {
				return 'mcp.request' === $event['event'] && isset( $event['tags']['status'] ) && 'success' === $event['tags']['status'];
			}
		);
		$this->assertNotEmpty( $success_event );
		$first_success = reset( $success_event );
		$this->assertNotNull( $first_success['duration_ms'] );
		$this->assertGreaterThan( 0, $first_success['duration_ms'] );

		// Verify tags are included
		$this->assertArrayHasKey( 'tags', $first_success );
		$this->assertArrayHasKey( 'status', $first_success['tags'] );
		$this->assertArrayHasKey( 'method', $first_success['tags'] );
		$this->assertArrayHasKey( 'transport', $first_success['tags'] );
		$this->assertEquals( 'success', $first_success['tags']['status'] );
		$this->assertEquals( 'tools/list', $first_success['tags']['method'] );
		$this->assertEquals( 'test-transport', $first_success['tags']['transport'] );
	}

	public function test_route_request_initialize_unauthenticated_returns_correct_error_structure(): void {
		// Set no user (unauthenticated)
		wp_set_current_user( 0 );

		$request      = new WP_REST_Request( 'POST', '/test-mcp' );
		$http_context = new HttpRequestContext( $request );

		$result = $this->router->route_request(
			'initialize',
			array(
				'protocolVersion' => '2025-11-25',
				'clientInfo'      => array(
					'name'    => 'test-client',
					'version' => '1.0.0',
				),
			),
			1,
			'test-transport',
			$http_context
		);

		// Verify error response structure (not double-wrapped)
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertArrayNotHasKey( 'jsonrpc', $result );
		$this->assertArrayNotHasKey( 'id', $result );

		// The error should be a proper error object with code and message
		$this->assertArrayHasKey( 'code', $result['error'] );
		$this->assertArrayHasKey( 'message', $result['error'] );

		// Should NOT have nested jsonrpc/id/error (double-wrapping)
		$this->assertArrayNotHasKey( 'jsonrpc', $result['error'] );
		$this->assertArrayNotHasKey( 'id', $result['error'] );

		// Verify the correct error code (unauthorized)
		$this->assertEquals( McpErrorFactory::UNAUTHORIZED, $result['error']['code'] );
		$this->assertStringContainsString( 'authentication', strtolower( $result['error']['message'] ) );

		// Restore user
		wp_set_current_user( $this->test_user_id );
	}

	public function test_route_request_initialize_session_error_has_correct_code(): void {
		// Set no user (unauthenticated) to trigger session creation failure
		wp_set_current_user( 0 );

		$request      = new WP_REST_Request( 'POST', '/test-mcp' );
		$http_context = new HttpRequestContext( $request );

		$result = $this->router->route_request(
			'initialize',
			array(
				'protocolVersion' => '2025-11-25',
				'clientInfo'      => array(
					'name'    => 'test-client',
					'version' => '1.0.0',
				),
			),
			1,
			'test-transport',
			$http_context
		);

		// Verify error has correct structure for HTTP status mapping
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertArrayHasKey( 'code', $result['error'] );

		// The error code should map to HTTP 401
		$http_status = McpErrorFactory::get_http_status_for_error( array( 'error' => $result['error'] ) );
		$this->assertEquals( 401, $http_status );

		// Restore user
		wp_set_current_user( $this->test_user_id );
	}

	// =========================================================================
	// Observability Context Resolution Tests
	// =========================================================================

	public function test_route_request_tools_call_with_empty_tool_name(): void {
		$result = $this->router->route_request(
			'tools/call',
			array(
				'name'      => '',
				'arguments' => array(),
			),
			1
		);

		$this->assertIsArray( $result );
		// Should return an error for invalid tool name
		$this->assertArrayHasKey( 'error', $result );
	}

	public function test_route_request_tools_call_with_null_tool_name(): void {
		$result = $this->router->route_request(
			'tools/call',
			array(
				'name'      => null,
				'arguments' => array(),
			),
			1
		);

		$this->assertIsArray( $result );
		// Should return an error for invalid tool name
		$this->assertArrayHasKey( 'error', $result );
	}

	public function test_route_request_tools_call_with_non_string_tool_name(): void {
		$result = $this->router->route_request(
			'tools/call',
			array(
				'name'      => 12345,
				'arguments' => array(),
			),
			1
		);

		$this->assertIsArray( $result );
		// Should return an error for invalid tool name
		$this->assertArrayHasKey( 'error', $result );
	}

	public function test_route_request_prompts_get_with_empty_prompt_name(): void {
		$result = $this->router->route_request(
			'prompts/get',
			array(
				'name' => '',
			),
			1
		);

		$this->assertIsArray( $result );
		// Should return an error for invalid prompt name
		$this->assertArrayHasKey( 'error', $result );
	}

	public function test_route_request_prompts_get_with_null_prompt_name(): void {
		$result = $this->router->route_request(
			'prompts/get',
			array(
				'name' => null,
			),
			1
		);

		$this->assertIsArray( $result );
		// Should return an error for invalid prompt name
		$this->assertArrayHasKey( 'error', $result );
	}

	public function test_route_request_prompts_get_with_nonexistent_prompt(): void {
		$result = $this->router->route_request(
			'prompts/get',
			array(
				'name' => 'nonexistent-prompt',
			),
			1
		);

		$this->assertIsArray( $result );
		// Should return an error for nonexistent prompt
		$this->assertArrayHasKey( 'error', $result );
	}

	public function test_route_request_resources_read_with_empty_uri(): void {
		$result = $this->router->route_request(
			'resources/read',
			array(
				'uri' => '',
			),
			1
		);

		$this->assertIsArray( $result );
		// Should return an error for invalid uri
		$this->assertArrayHasKey( 'error', $result );
	}

	public function test_route_request_resources_read_with_null_uri(): void {
		$result = $this->router->route_request(
			'resources/read',
			array(
				'uri' => null,
			),
			1
		);

		$this->assertIsArray( $result );
		// Should return an error for invalid uri
		$this->assertArrayHasKey( 'error', $result );
	}

	public function test_route_request_resources_read_with_nonexistent_resource(): void {
		$result = $this->router->route_request(
			'resources/read',
			array(
				'uri' => 'nonexistent://resource',
			),
			1
		);

		$this->assertIsArray( $result );
		// Should return an error for nonexistent resource
		$this->assertArrayHasKey( 'error', $result );
	}

	// =========================================================================
	// Sanitize Params Tests (via observability events)
	// =========================================================================

	public function test_route_request_sanitizes_client_info_in_params(): void {
		DummyObservabilityHandler::reset();

		$this->router->route_request(
			'initialize',
			array(
				'protocolVersion' => '2025-11-25',
				'clientInfo'      => array(
					'name'    => 'test-client',
					'version' => '1.0.0',
				),
			),
			1,
			'test-transport'
		);

		// Check that client_name was extracted in sanitized params
		$events = DummyObservabilityHandler::$events;
		$this->assertNotEmpty( $events );

		$mcp_event = array_filter(
			$events,
			static function ( $event ) {
				return 'mcp.request' === $event['event'];
			}
		);
		$this->assertNotEmpty( $mcp_event );

		$first_event = reset( $mcp_event );
		$this->assertArrayHasKey( 'tags', $first_event );
		$this->assertArrayHasKey( 'params', $first_event['tags'] );

		$params = $first_event['tags']['params'];
		$this->assertArrayHasKey( 'client_name', $params );
		$this->assertEquals( 'test-client', $params['client_name'] );
	}

	public function test_route_request_sanitizes_arguments_in_tool_call(): void {
		DummyObservabilityHandler::reset();

		$this->router->route_request(
			'tools/call',
			array(
				'name'      => 'test-always-allowed',
				'arguments' => array(
					'arg1' => 'value1',
					'arg2' => 'value2',
				),
			),
			1,
			'test-transport'
		);

		// Check that arguments_count and arguments_keys were extracted
		$events = DummyObservabilityHandler::$events;
		$this->assertNotEmpty( $events );

		$mcp_event = array_filter(
			$events,
			static function ( $event ) {
				return 'mcp.request' === $event['event'];
			}
		);
		$this->assertNotEmpty( $mcp_event );

		$first_event = reset( $mcp_event );
		$params      = $first_event['tags']['params'];

		$this->assertArrayHasKey( 'arguments_count', $params );
		$this->assertEquals( 2, $params['arguments_count'] );
		$this->assertArrayHasKey( 'arguments_keys', $params );
		$this->assertContains( 'arg1', $params['arguments_keys'] );
		$this->assertContains( 'arg2', $params['arguments_keys'] );
	}

	public function test_route_request_sanitizes_params_extracts_safe_fields(): void {
		DummyObservabilityHandler::reset();

		$this->router->route_request(
			'resources/read',
			array(
				'uri'       => 'test://resource/path',
				'sensitive' => 'should-not-appear',
			),
			1,
			'test-transport'
		);

		$events = DummyObservabilityHandler::$events;
		$this->assertNotEmpty( $events );

		$mcp_event = array_filter(
			$events,
			static function ( $event ) {
				return 'mcp.request' === $event['event'];
			}
		);
		$this->assertNotEmpty( $mcp_event );

		$first_event = reset( $mcp_event );
		$params      = $first_event['tags']['params'];

		// URI should be extracted (safe field)
		$this->assertArrayHasKey( 'uri', $params );
		$this->assertEquals( 'test://resource/path', $params['uri'] );

		// Sensitive field should not be extracted
		$this->assertArrayNotHasKey( 'sensitive', $params );
	}

	public function test_route_request_with_empty_params(): void {
		DummyObservabilityHandler::reset();

		$this->router->route_request(
			'ping',
			array(),
			1,
			'test-transport'
		);

		$events = DummyObservabilityHandler::$events;
		$this->assertNotEmpty( $events );

		$mcp_event = array_filter(
			$events,
			static function ( $event ) {
				return 'mcp.request' === $event['event'];
			}
		);
		$this->assertNotEmpty( $mcp_event );

		$first_event = reset( $mcp_event );
		$this->assertArrayHasKey( 'tags', $first_event );
		$this->assertArrayHasKey( 'params', $first_event['tags'] );
		$this->assertEmpty( $first_event['tags']['params'] );
	}

	// =========================================================================
	// Error Categorization Tests
	// =========================================================================

	public function test_route_request_records_error_type_in_observability(): void {
		DummyObservabilityHandler::reset();

		// Trigger an error by calling a nonexistent tool
		$this->router->route_request(
			'tools/call',
			array( 'name' => 'nonexistent-tool' ),
			1,
			'test-transport'
		);

		$events = DummyObservabilityHandler::$events;
		$this->assertNotEmpty( $events );

		// Find error event
		$error_event = array_filter(
			$events,
			static function ( $event ) {
				return 'mcp.request' === $event['event'] && isset( $event['tags']['status'] ) && 'error' === $event['tags']['status'];
			}
		);
		$this->assertNotEmpty( $error_event );
	}

	// =========================================================================
	// Nested Params Tests
	// =========================================================================

	public function test_route_request_handles_nested_params_structure(): void {
		DummyObservabilityHandler::reset();

		// Test with params nested under 'params' key (some clients do this)
		$this->router->route_request(
			'tools/call',
			array(
				'params' => array(
					'name'      => 'test-always-allowed',
					'arguments' => array(),
				),
			),
			1,
			'test-transport'
		);

		$events = DummyObservabilityHandler::$events;
		$this->assertNotEmpty( $events );
	}

	public function test_route_request_handles_non_array_nested_params(): void {
		DummyObservabilityHandler::reset();

		// Test with non-array nested params
		$this->router->route_request(
			'tools/call',
			array(
				'params' => 'not-an-array',
				'name'   => 'test-always-allowed',
			),
			1,
			'test-transport'
		);

		$events = DummyObservabilityHandler::$events;
		$this->assertNotEmpty( $events );
	}

	// =========================================================================
	// Additional Edge Case Tests
	// =========================================================================

	public function test_route_request_with_null_request_id(): void {
		$result = $this->router->route_request(
			'ping',
			array(),
			null,
			'test-transport'
		);

		$this->assertIsArray( $result );
		// Ping with null ID should still work
		$this->assertEmpty( $result );
	}

	public function test_route_request_with_string_request_id(): void {
		$result = $this->router->route_request(
			'ping',
			array(),
			'string-request-id',
			'test-transport'
		);

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	public function test_route_request_tools_list_all(): void {
		$result = $this->router->route_request( 'tools/list/all', array(), 1 );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'tools', $result );
		$this->assertIsArray( $result['tools'] );
	}

	public function test_route_request_with_whitespace_tool_name(): void {
		$result = $this->router->route_request(
			'tools/call',
			array(
				'name'      => '   ',
				'arguments' => array(),
			),
			1
		);

		$this->assertIsArray( $result );
		// Whitespace-only tool name should be treated as empty
		$this->assertArrayHasKey( 'error', $result );
	}

	public function test_route_request_with_whitespace_prompt_name(): void {
		$result = $this->router->route_request(
			'prompts/get',
			array(
				'name' => '   ',
			),
			1
		);

		$this->assertIsArray( $result );
		// Whitespace-only prompt name should be treated as empty
		$this->assertArrayHasKey( 'error', $result );
	}

	public function test_route_request_with_whitespace_resource_uri(): void {
		$result = $this->router->route_request(
			'resources/read',
			array(
				'uri' => '   ',
			),
			1
		);

		$this->assertIsArray( $result );
		// Whitespace-only URI should be treated as empty
		$this->assertArrayHasKey( 'error', $result );
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
				'observability_handler' => new DummyObservabilityHandler(),
				'error_handler'         => new DummyErrorHandler(),
			)
		);
	}
}
