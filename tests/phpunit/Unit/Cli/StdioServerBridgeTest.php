<?php
/**
 * Tests for StdioServerBridge class.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Cli;

use WP\MCP\Cli\StdioServerBridge;
use WP\MCP\Core\McpServer;
use WP\MCP\Core\McpVersionNegotiator;
use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\Fixtures\DummyObservabilityHandler;
use WP\MCP\Tests\TestCase;
use WP\MCP\Transport\HttpTransport;

/**
 * Test StdioServerBridge functionality.
 *
 * Note: These tests focus on the bridge logic rather than actual STDIO communication.
 */
final class StdioServerBridgeTest extends TestCase {

	private StdioServerBridge $bridge;
	private McpServer $server;

	public function set_up(): void {
		parent::set_up();

		// Set current user for session management
		wp_set_current_user( 1 );

		// Create MCP server
		$this->server = new McpServer(
			'stdio-test-server',
			'mcp/v1',
			'/stdio-mcp',
			'STDIO Test Server',
			'Test server for STDIO bridge',
			'1.0.0',
			array( HttpTransport::class ),
			DummyErrorHandler::class,
			DummyObservabilityHandler::class,
			array( 'test/always-allowed' ),
			array( 'test/resource' ),
			array( 'test/prompt' )
		);

		$this->bridge = new StdioServerBridge( $this->server );
	}

	public function test_bridge_constructor(): void {
		$this->assertInstanceOf( StdioServerBridge::class, $this->bridge );
		$this->assertSame( $this->server, $this->bridge->get_server() );
	}

	public function test_get_server(): void {
		$server = $this->bridge->get_server();

		$this->assertInstanceOf( McpServer::class, $server );
		$this->assertEquals( 'stdio-test-server', $server->get_server_id() );
		$this->assertEquals( 'STDIO Test Server', $server->get_server_name() );
	}

	public function test_handle_request_with_valid_json_rpc(): void {
		// Use reflection to access private method
		$reflection            = new \ReflectionClass( $this->bridge );
		$handle_request_method = $reflection->getMethod( 'handle_request' );
		$handle_request_method->setAccessible( true );

		$json_input = wp_json_encode(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'initialize',
				'params'  => array(
					'protocolVersion' => '2025-11-25',
					'capabilities'    => new \stdClass(),
					'clientInfo'      => array(
						'name'    => 'test-client',
						'version' => '1.0.0',
					),
				),
			)
		);

		$result = $handle_request_method->invoke( $this->bridge, $json_input );

		$this->assertIsString( $result );
		$this->assertNotEmpty( $result );

		$response = json_decode( $result, true );
		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'jsonrpc', $response );
		$this->assertEquals( '2.0', $response['jsonrpc'] );
		$this->assertArrayHasKey( 'id', $response );
		$this->assertEquals( 1, $response['id'] );
		$this->assertArrayHasKey( 'result', $response );
	}

	public function test_handle_request_with_notification(): void {
		// Use reflection to access private method
		$reflection            = new \ReflectionClass( $this->bridge );
		$handle_request_method = $reflection->getMethod( 'handle_request' );
		$handle_request_method->setAccessible( true );

		$json_input = wp_json_encode(
			array(
				'jsonrpc' => '2.0',
				'method'  => 'notifications/cancelled',
				'params'  => array( 'requestId' => 123 ),
			// No 'id' field - this is a notification
			)
		);

		$result = $handle_request_method->invoke( $this->bridge, $json_input );

		$this->assertEquals( '', $result ); // Notifications return empty string
	}

	public function test_handle_request_with_invalid_json(): void {
		// Use reflection to access private method
		$reflection            = new \ReflectionClass( $this->bridge );
		$handle_request_method = $reflection->getMethod( 'handle_request' );
		$handle_request_method->setAccessible( true );

		$result = $handle_request_method->invoke( $this->bridge, 'invalid json' );

		$this->assertIsString( $result );
		$this->assertNotEmpty( $result );

		$response = json_decode( $result, true );
		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'error', $response );
		$this->assertEquals( -32700, $response['error']['code'] ); // Parse error
		$this->assertStringContainsString( 'Parse error', $response['error']['message'] );
	}

	public function test_handle_request_with_invalid_jsonrpc_version(): void {
		// Use reflection to access private method
		$reflection            = new \ReflectionClass( $this->bridge );
		$handle_request_method = $reflection->getMethod( 'handle_request' );
		$handle_request_method->setAccessible( true );

		$json_input = wp_json_encode(
			array(
				'jsonrpc' => '1.0', // Invalid version
				'id'      => 1,
				'method'  => 'initialize',
				'params'  => new \stdClass(),
			)
		);

		$result = $handle_request_method->invoke( $this->bridge, $json_input );

		$this->assertIsString( $result );
		$response = json_decode( $result, true );
		$this->assertArrayHasKey( 'error', $response );
		$this->assertEquals( -32600, $response['error']['code'] ); // Invalid Request
	}

	public function test_handle_request_with_missing_method(): void {
		// Use reflection to access private method
		$reflection            = new \ReflectionClass( $this->bridge );
		$handle_request_method = $reflection->getMethod( 'handle_request' );
		$handle_request_method->setAccessible( true );

		$json_input = wp_json_encode(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'params'  => array(),
			// Missing 'method' field
			)
		);

		$result = $handle_request_method->invoke( $this->bridge, $json_input );

		$this->assertIsString( $result );
		$response = json_decode( $result, true );
		$this->assertArrayHasKey( 'error', $response );
		$this->assertEquals( -32600, $response['error']['code'] ); // Invalid Request
	}

	public function test_format_response_with_success_result(): void {
		// Use reflection to access private method
		$reflection             = new \ReflectionClass( $this->bridge );
		$format_response_method = $reflection->getMethod( 'format_response' );
		$format_response_method->setAccessible( true );

		$result = array(
			'protocolVersion' => '2025-11-25',
			'serverInfo'      => array(
				'name'    => 'Test Server',
				'version' => '1.0.0',
			),
		);

		$response = $format_response_method->invoke( $this->bridge, $result, 123 );

		$this->assertIsString( $response );
		$response_data = json_decode( $response, true );
		$this->assertArrayHasKey( 'jsonrpc', $response_data );
		$this->assertEquals( '2.0', $response_data['jsonrpc'] );
		$this->assertArrayHasKey( 'id', $response_data );
		$this->assertEquals( 123, $response_data['id'] );
		$this->assertArrayHasKey( 'result', $response_data );
		$this->assertEquals( $result, (array) $response_data['result'] );
	}

	public function test_format_response_with_error_result(): void {
		// Use reflection to access private method
		$reflection             = new \ReflectionClass( $this->bridge );
		$format_response_method = $reflection->getMethod( 'format_response' );
		$format_response_method->setAccessible( true );

		$result = array(
			'error' => array(
				'code'    => -32602,
				'message' => 'Invalid params',
				'data'    => array( 'details' => 'Missing parameter' ),
			),
		);

		$response = $format_response_method->invoke( $this->bridge, $result, 456 );

		$this->assertIsString( $response );
		$response_data = json_decode( $response, true );
		$this->assertArrayHasKey( 'jsonrpc', $response_data );
		$this->assertEquals( '2.0', $response_data['jsonrpc'] );
		$this->assertArrayHasKey( 'id', $response_data );
		$this->assertEquals( 456, $response_data['id'] );
		$this->assertArrayHasKey( 'error', $response_data );

		$error = $response_data['error'];
		$this->assertEquals( -32602, $error['code'] );
		$this->assertEquals( 'Invalid params', $error['message'] );
		$this->assertArrayHasKey( 'data', $error );
	}

	public function test_create_error_response(): void {
		// Use reflection to access private method
		$reflection          = new \ReflectionClass( $this->bridge );
		$create_error_method = $reflection->getMethod( 'create_error_response' );
		$create_error_method->setAccessible( true );

		$response = $create_error_method->invoke(
			$this->bridge,
			789,
			-32603,
			'Internal error',
			'Additional error data'
		);

		$this->assertIsString( $response );
		$response_data = json_decode( $response, true );
		$this->assertArrayHasKey( 'jsonrpc', $response_data );
		$this->assertEquals( '2.0', $response_data['jsonrpc'] );
		$this->assertArrayHasKey( 'id', $response_data );
		$this->assertEquals( 789, $response_data['id'] );
		$this->assertArrayHasKey( 'error', $response_data );

		$error = $response_data['error'];
		$this->assertEquals( -32603, $error['code'] );
		$this->assertEquals( 'Internal error', $error['message'] );
		$this->assertEquals( 'Additional error data', $error['data'] );
	}

	public function test_create_error_response_without_data(): void {
		// Use reflection to access private method
		$reflection          = new \ReflectionClass( $this->bridge );
		$create_error_method = $reflection->getMethod( 'create_error_response' );
		$create_error_method->setAccessible( true );

		$response = $create_error_method->invoke(
			$this->bridge,
			999,
			-32600,
			'Invalid Request'
		);

		$this->assertIsString( $response );
		$response_data = json_decode( $response, true );
		$this->assertArrayHasKey( 'error', $response_data );

		$error = $response_data['error'];
		$this->assertEquals( -32600, $error['code'] );
		$this->assertEquals( 'Invalid Request', $error['message'] );
		$this->assertArrayNotHasKey( 'data', $error );
	}

	public function test_bridge_creates_request_router(): void {
		// Use reflection to access private property
		$reflection      = new \ReflectionClass( $this->bridge );
		$router_property = $reflection->getProperty( 'request_router' );
		$router_property->setAccessible( true );

		$router = $router_property->getValue( $this->bridge );

		$this->assertInstanceOf( \WP\MCP\Transport\Infrastructure\RequestRouter::class, $router );
	}

	public function test_stop_method(): void {
		// Use reflection to access private property
		$reflection          = new \ReflectionClass( $this->bridge );
		$is_running_property = $reflection->getProperty( 'is_running' );
		$is_running_property->setAccessible( true );

		// Initially should be false
		$this->assertFalse( $is_running_property->getValue( $this->bridge ) );

		// Call stop method
		$this->bridge->stop();

		// Should still be false (stop sets it to false)
		$this->assertFalse( $is_running_property->getValue( $this->bridge ) );
	}

	public function test_handle_request_with_tools_list(): void {
		$this->initialize_legacy();

		// Use reflection to access private method
		$reflection            = new \ReflectionClass( $this->bridge );
		$handle_request_method = $reflection->getMethod( 'handle_request' );
		$handle_request_method->setAccessible( true );

		$json_input = wp_json_encode(
			array(
				'jsonrpc' => '2.0',
				'id'      => 2,
				'method'  => 'tools/list',
				'params'  => new \stdClass(),
			)
		);

		$result = $handle_request_method->invoke( $this->bridge, $json_input );

		$this->assertIsString( $result );
		$response = json_decode( $result, true );
		$this->assertArrayHasKey( 'result', $response );
		$this->assertArrayHasKey( 'tools', $response['result'] );
	}

	public function test_handle_request_with_object_params(): void {
		$this->initialize_legacy();

		// Use reflection to access private method
		$reflection            = new \ReflectionClass( $this->bridge );
		$handle_request_method = $reflection->getMethod( 'handle_request' );
		$handle_request_method->setAccessible( true );

		// Object params are decoded to an array, then validated against the exact request schema.
		$json_input = wp_json_encode(
			array(
				'jsonrpc' => '2.0',
				'id'      => 3,
				'method'  => 'tools/list',
				'params'  => (object) array( 'filter' => 'test' ),
			)
		);

		$result = $handle_request_method->invoke( $this->bridge, $json_input );

		$this->assertIsString( $result );
		$response = json_decode( $result, true );
		$this->assertSame( McpErrorFactory::INVALID_PARAMS, $response['error']['code'] );
	}

	public function test_handle_request_with_non_array_params(): void {
		$this->initialize_legacy();

		// Use reflection to access private method
		$reflection            = new \ReflectionClass( $this->bridge );
		$handle_request_method = $reflection->getMethod( 'handle_request' );
		$handle_request_method->setAccessible( true );

		// Scalar params violate the exact request schema.
		$json_input = wp_json_encode(
			array(
				'jsonrpc' => '2.0',
				'id'      => 4,
				'method'  => 'ping',
				'params'  => 'invalid-params',
			)
		);

		$result = $handle_request_method->invoke( $this->bridge, $json_input );

		$this->assertIsString( $result );
		$response = json_decode( $result, true );
		$this->assertSame( McpErrorFactory::INVALID_PARAMS, $response['error']['code'] );
	}

	public function test_handle_request_with_exception_in_routing(): void {
		$this->initialize_legacy();

		// Use reflection to access private method
		$reflection            = new \ReflectionClass( $this->bridge );
		$handle_request_method = $reflection->getMethod( 'handle_request' );
		$handle_request_method->setAccessible( true );

		// Test with a method that might cause issues
		$json_input = wp_json_encode(
			array(
				'jsonrpc' => '2.0',
				'id'      => 5,
				'method'  => 'tools/call',
				'params'  => array( 'name' => 'nonexistent-tool' ),
			)
		);

		$result = $handle_request_method->invoke( $this->bridge, $json_input );

		$this->assertIsString( $result );
		$response = json_decode( $result, true );
		// Should either have result or error (handler should handle the nonexistent tool gracefully)
		$this->assertTrue( isset( $response['result'] ) || isset( $response['error'] ) );
	}

	public function test_serve_method_checks_stdio_transport_filter(): void {
		// Test that serve() checks the filter and throws RuntimeException when disabled
		add_filter( 'mcp_adapter_enable_stdio_transport', '__return_false' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'The STDIO transport is disabled. Enable it by setting the "mcp_adapter_enable_stdio_transport" filter to true.' );

		$this->bridge->serve();

		// Clean up filter
		remove_filter( 'mcp_adapter_enable_stdio_transport', '__return_false' );
	}

	public function test_bridge_handles_request_ids(): void {
		$this->initialize_legacy();

		// Use reflection to access private method
		$reflection            = new \ReflectionClass( $this->bridge );
		$handle_request_method = $reflection->getMethod( 'handle_request' );
		$handle_request_method->setAccessible( true );

		// Test with numeric ID
		$json_input = wp_json_encode(
			array(
				'jsonrpc' => '2.0',
				'id'      => 42,
				'method'  => 'ping',
				'params'  => new \stdClass(),
			)
		);

		$result = $handle_request_method->invoke( $this->bridge, $json_input );
		$this->assertIsString( $result );
		$this->assertNotEmpty( $result );

		$response = json_decode( $result, true );
		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'jsonrpc', $response );
		$this->assertEquals( '2.0', $response['jsonrpc'] );

		// The response should have either result or error
		$this->assertTrue( isset( $response['result'] ) || isset( $response['error'] ) );
	}

	public function test_handle_request_with_non_array_json(): void {
		// Use reflection to access private method
		$reflection            = new \ReflectionClass( $this->bridge );
		$handle_request_method = $reflection->getMethod( 'handle_request' );
		$handle_request_method->setAccessible( true );

		// Test with a JSON string that parses to a non-array (e.g., a string)
		$json_input = '"just a string"';

		$result = $handle_request_method->invoke( $this->bridge, $json_input );

		$this->assertIsString( $result );
		$response = json_decode( $result, true );
		$this->assertArrayHasKey( 'error', $response );
		$this->assertEquals( -32600, $response['error']['code'] ); // Invalid Request
		$this->assertStringContainsString( 'not a valid Request object', $response['error']['data'] );
	}

	public function test_handle_request_with_null_json(): void {
		// Use reflection to access private method
		$reflection            = new \ReflectionClass( $this->bridge );
		$handle_request_method = $reflection->getMethod( 'handle_request' );
		$handle_request_method->setAccessible( true );

		// Test with JSON null
		$json_input = 'null';

		$result = $handle_request_method->invoke( $this->bridge, $json_input );

		$this->assertIsString( $result );
		$response = json_decode( $result, true );
		$this->assertArrayHasKey( 'error', $response );
		$this->assertEquals( -32600, $response['error']['code'] ); // Invalid Request
	}

	public function test_handle_request_with_missing_jsonrpc_version(): void {
		// Use reflection to access private method
		$reflection            = new \ReflectionClass( $this->bridge );
		$handle_request_method = $reflection->getMethod( 'handle_request' );
		$handle_request_method->setAccessible( true );

		$json_input = wp_json_encode(
			array(
				'id'     => 1,
				'method' => 'ping',
				'params' => array(),
				// Missing 'jsonrpc' field
			)
		);

		$result = $handle_request_method->invoke( $this->bridge, $json_input );

		$this->assertIsString( $result );
		$response = json_decode( $result, true );
		$this->assertArrayHasKey( 'error', $response );
		$this->assertEquals( -32600, $response['error']['code'] ); // Invalid Request
		$this->assertStringContainsString( '2.0', $response['error']['data'] );
	}

	public function test_handle_request_with_non_string_method(): void {
		// Use reflection to access private method
		$reflection            = new \ReflectionClass( $this->bridge );
		$handle_request_method = $reflection->getMethod( 'handle_request' );
		$handle_request_method->setAccessible( true );

		$json_input = wp_json_encode(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 12345, // Non-string method
				'params'  => new \stdClass(),
			)
		);

		$result = $handle_request_method->invoke( $this->bridge, $json_input );

		$this->assertIsString( $result );
		$response = json_decode( $result, true );
		$this->assertArrayHasKey( 'error', $response );
		$this->assertEquals( -32600, $response['error']['code'] ); // Invalid Request
		$this->assertStringContainsString( 'Method must be a string', $response['error']['data'] );
	}

	public function test_format_response_with_error_missing_fields(): void {
		// Use reflection to access private method
		$reflection             = new \ReflectionClass( $this->bridge );
		$format_response_method = $reflection->getMethod( 'format_response' );
		$format_response_method->setAccessible( true );

		// Test error result with missing optional fields (code and message)
		$result = array(
			'error' => array(
				// Missing 'code' and 'message' - should use defaults
			),
		);

		$response = $format_response_method->invoke( $this->bridge, $result, 999 );

		$this->assertIsString( $response );
		$response_data = json_decode( $response, true );
		$this->assertArrayHasKey( 'error', $response_data );
		$this->assertEquals( -32603, $response_data['error']['code'] ); // Default internal error
		$this->assertEquals( 'Internal error', $response_data['error']['message'] ); // Default message
	}

	public function test_handle_request_with_string_id(): void {
		$this->initialize_legacy();

		// Use reflection to access private method
		$reflection            = new \ReflectionClass( $this->bridge );
		$handle_request_method = $reflection->getMethod( 'handle_request' );
		$handle_request_method->setAccessible( true );

		// Test with string ID (JSON-RPC allows string IDs)
		$json_input = wp_json_encode(
			array(
				'jsonrpc' => '2.0',
				'id'      => 'string-request-id',
				'method'  => 'ping',
				'params'  => array(),
			)
		);

		$result = $handle_request_method->invoke( $this->bridge, $json_input );

		$this->assertIsString( $result );
		$response = json_decode( $result, true );
		$this->assertArrayHasKey( 'id', $response );
		$this->assertEquals( 'string-request-id', $response['id'] );
	}

	public function test_handle_request_with_null_id(): void {
		// Use reflection to access private method
		$reflection            = new \ReflectionClass( $this->bridge );
		$handle_request_method = $reflection->getMethod( 'handle_request' );
		$handle_request_method->setAccessible( true );

		// Test with null ID (should be treated as notification but with explicit null)
		$json_input = wp_json_encode(
			array(
				'jsonrpc' => '2.0',
				'id'      => null,
				'method'  => 'ping',
				'params'  => array(),
			)
		);

		$result = $handle_request_method->invoke( $this->bridge, $json_input );

		// Null ID is treated as notification, so empty response
		$this->assertEquals( '', $result );
	}

	public function test_handle_request_with_resources_list(): void {
		$this->initialize_legacy();

		// Use reflection to access private method
		$reflection            = new \ReflectionClass( $this->bridge );
		$handle_request_method = $reflection->getMethod( 'handle_request' );
		$handle_request_method->setAccessible( true );

		$json_input = wp_json_encode(
			array(
				'jsonrpc' => '2.0',
				'id'      => 3,
				'method'  => 'resources/list',
				'params'  => new \stdClass(),
			)
		);

		$result = $handle_request_method->invoke( $this->bridge, $json_input );

		$this->assertIsString( $result );
		$response = json_decode( $result, true );
		$this->assertArrayHasKey( 'result', $response );
		$this->assertArrayHasKey( 'resources', $response['result'] );
	}

	public function test_handle_request_with_prompts_list(): void {
		$this->initialize_legacy();

		// Use reflection to access private method
		$reflection            = new \ReflectionClass( $this->bridge );
		$handle_request_method = $reflection->getMethod( 'handle_request' );
		$handle_request_method->setAccessible( true );

		$json_input = wp_json_encode(
			array(
				'jsonrpc' => '2.0',
				'id'      => 4,
				'method'  => 'prompts/list',
				'params'  => new \stdClass(),
			)
		);

		$result = $handle_request_method->invoke( $this->bridge, $json_input );

		$this->assertIsString( $result );
		$response = json_decode( $result, true );
		$this->assertArrayHasKey( 'result', $response );
		$this->assertArrayHasKey( 'prompts', $response['result'] );
	}

	public function test_handle_request_with_unknown_method(): void {
		$this->initialize_legacy();

		// Use reflection to access private method
		$reflection            = new \ReflectionClass( $this->bridge );
		$handle_request_method = $reflection->getMethod( 'handle_request' );
		$handle_request_method->setAccessible( true );

		$json_input = wp_json_encode(
			array(
				'jsonrpc' => '2.0',
				'id'      => 5,
				'method'  => 'unknown/method',
				'params'  => array(),
			)
		);

		$result = $handle_request_method->invoke( $this->bridge, $json_input );

		$this->assertIsString( $result );
		$response = json_decode( $result, true );
		$this->assertArrayHasKey( 'error', $response );
		$this->assertEquals( -32601, $response['error']['code'] ); // Method not found
	}

	public function test_handle_request_with_missing_params(): void {
		$this->initialize_legacy();

		// Use reflection to access private method
		$reflection            = new \ReflectionClass( $this->bridge );
		$handle_request_method = $reflection->getMethod( 'handle_request' );
		$handle_request_method->setAccessible( true );

		// Test request without params field - should default to empty array
		$json_input = wp_json_encode(
			array(
				'jsonrpc' => '2.0',
				'id'      => 6,
				'method'  => 'ping',
				// Missing 'params' field
			)
		);

		$result = $handle_request_method->invoke( $this->bridge, $json_input );

		$this->assertIsString( $result );
		$response = json_decode( $result, true );
		// Should succeed since ping doesn't need params
		$this->assertArrayHasKey( 'result', $response );
	}

	public function test_legacy_stdio_requires_initialize_before_requests(): void {
		$response = $this->handle(
			array(
				'jsonrpc' => '2.0',
				'id'      => 80,
				'method'  => 'tools/list',
				'params'  => array(),
			)
		);

		$this->assertSame( McpErrorFactory::INVALID_REQUEST, $response['error']['code'] );
		$this->assertStringContainsString( 'must initialize', $response['error']['message'] );
	}

	public function test_legacy_stdio_initialize_falls_back_then_enables_session_flow(): void {
		$initialize = $this->handle(
			array(
				'jsonrpc' => '2.0',
				'id'      => 81,
				'method'  => 'initialize',
				'params'  => array(
					'protocolVersion' => '2024-11-05',
					'capabilities'    => array(),
					'clientInfo'      => array(
						'name'    => 'legacy-stdio-client',
						'version' => '1.0.0',
					),
				),
			)
		);

		$this->assertSame( McpVersionNegotiator::LEGACY_PROTOCOL_VERSION, $initialize['result']['protocolVersion'] );

		$ping = $this->handle(
			array(
				'jsonrpc' => '2.0',
				'id'      => 82,
				'method'  => 'ping',
			)
		);
		$this->assertSame( array(), $ping['result'] );
	}

	public function test_modern_stdio_discovery_is_stateless(): void {
		$params = $this->modern_params();
		$params['_meta']['io.modelcontextprotocol/clientInfo'] = array(
			'name'    => 'modern-stdio-client',
			'version' => '1.0.0',
		);
		$response = $this->handle(
			array(
				'jsonrpc' => '2.0',
				'id'      => 83,
				'method'  => 'server/discover',
				'params'  => $params,
			)
		);

		$this->assertSame( 'complete', $response['result']['resultType'] );
		$this->assertSame( McpVersionNegotiator::SUPPORTED_PROTOCOL_VERSIONS, $response['result']['supportedVersions'] );
		$this->assertSame( 0, $response['result']['ttlMs'] );
		$this->assertSame( 'private', $response['result']['cacheScope'] );
	}

	public function test_modern_stdio_request_does_not_require_initialize(): void {
		$response = $this->handle(
			array(
				'jsonrpc' => '2.0',
				'id'      => 84,
				'method'  => 'tools/list',
				'params'  => $this->modern_params(),
			)
		);

		$this->assertSame( 'complete', $response['result']['resultType'] );
		$this->assertArrayHasKey( 'tools', $response['result'] );
	}

	public function test_modern_stdio_rejects_unadvertised_revision(): void {
		$params = $this->modern_params();
		$params['_meta']['io.modelcontextprotocol/protocolVersion'] = '2099-01-01';
		$response = $this->handle(
			array(
				'jsonrpc' => '2.0',
				'id'      => 85,
				'method'  => 'tools/list',
				'params'  => $params,
			)
		);

		$this->assertSame( McpErrorFactory::UNSUPPORTED_PROTOCOL_VERSION, $response['error']['code'] );
		$this->assertSame( '2099-01-01', $response['error']['data']['requested'] );
	}

	public function test_modern_stdio_does_not_expose_legacy_ping(): void {
		$response = $this->handle(
			array(
				'jsonrpc' => '2.0',
				'id'      => 86,
				'method'  => 'ping',
				'params'  => $this->modern_params(),
			)
		);

		$this->assertSame( McpErrorFactory::METHOD_NOT_FOUND, $response['error']['code'] );
	}

	public function test_raw_stdio_rejects_legacy_capabilities_list(): void {
		$response = $this->handleRaw(
			'{"jsonrpc":"2.0","id":87,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":[],"clientInfo":{"name":"raw-client","version":"1.0.0"}}}'
		);

		$this->assertSame( McpErrorFactory::INVALID_PARAMS, $response['error']['code'] );
	}

	public function test_raw_stdio_accepts_modern_client_capabilities_object(): void {
		$response = $this->handleRaw(
			'{"jsonrpc":"2.0","id":88,"method":"tools/list","params":{"_meta":{"io.modelcontextprotocol/protocolVersion":"2026-07-28","io.modelcontextprotocol/clientCapabilities":{}}}}'
		);

		$this->assertArrayHasKey( 'result', $response );
		$this->assertSame( 'complete', $response['result']['resultType'] );
	}

	public function test_raw_stdio_rejects_modern_object_fields_encoded_as_lists(): void {
		$client_capabilities = $this->handleRaw(
			'{"jsonrpc":"2.0","id":89,"method":"tools/list","params":{"_meta":{"io.modelcontextprotocol/protocolVersion":"2026-07-28","io.modelcontextprotocol/clientCapabilities":[]}}}'
		);
		$arguments           = $this->handleRaw(
			'{"jsonrpc":"2.0","id":90,"method":"tools/call","params":{"_meta":{"io.modelcontextprotocol/protocolVersion":"2026-07-28","io.modelcontextprotocol/clientCapabilities":{}},"name":"test-always-allowed","arguments":[]}}'
		);
		$input_responses     = $this->handleRaw(
			'{"jsonrpc":"2.0","id":91,"method":"tools/call","params":{"_meta":{"io.modelcontextprotocol/protocolVersion":"2026-07-28","io.modelcontextprotocol/clientCapabilities":{}},"name":"test-always-allowed","arguments":{},"inputResponses":[],"requestState":"opaque"}}'
		);

		$this->assertSame( McpErrorFactory::INVALID_PARAMS, $client_capabilities['error']['code'] );
		$this->assertSame( McpErrorFactory::INVALID_PARAMS, $arguments['error']['code'] );
		$this->assertSame( McpErrorFactory::INVALID_PARAMS, $input_responses['error']['code'] );
	}

	public function test_modern_request_does_not_replace_initialized_legacy_revision(): void {
		$this->initialize_legacy();
		$modern = $this->handleRaw(
			'{"jsonrpc":"2.0","id":92,"method":"tools/list","params":{"_meta":{"io.modelcontextprotocol/protocolVersion":"2026-07-28","io.modelcontextprotocol/clientCapabilities":{}}}}'
		);
		$legacy = $this->handle(
			array(
				'jsonrpc' => '2.0',
				'id'      => 93,
				'method'  => 'ping',
			)
		);

		$this->assertSame( 'complete', $modern['result']['resultType'] );
		$this->assertSame( array(), $legacy['result'] );
	}

	public function test_modern_stdio_response_preserves_numeric_key_objects(): void {
		$filter = static fn() => array(
			'numeric' => (object) array( 0 => 'zero' ),
		);
		add_filter( 'mcp_adapter_tool_call_result', $filter );

		try {
			$reflection = new \ReflectionClass( $this->bridge );
			$method     = $reflection->getMethod( 'handle_request' );
			$method->setAccessible( true );
			$response = $method->invoke(
				$this->bridge,
				'{"jsonrpc":"2.0","id":94,"method":"tools/call","params":{"_meta":{"io.modelcontextprotocol/protocolVersion":"2026-07-28","io.modelcontextprotocol/clientCapabilities":{}},"name":"test-always-allowed","arguments":{}}}'
			);

			$this->assertIsString( $response );
			$this->assertStringContainsString( '"numeric":{"0":"zero"}', $response );
			$this->assertStringNotContainsString( '"numeric":["zero"]', $response );
		} finally {
			remove_filter( 'mcp_adapter_tool_call_result', $filter );
		}
	}

	/** @param array<string, mixed> $request */
	private function handle( array $request ): array {
		$reflection = new \ReflectionClass( $this->bridge );
		$method     = $reflection->getMethod( 'handle_request' );
		$method->setAccessible( true );
		$response = $method->invoke( $this->bridge, wp_json_encode( $this->normalizeTestWireObjects( $request ) ) );
		$decoded  = json_decode( $response, true );

		$this->assertIsArray( $decoded );

		return $decoded;
	}

	/** @return array<string,mixed> */
	private function handleRaw( string $json ): array {
		$reflection = new \ReflectionClass( $this->bridge );
		$method     = $reflection->getMethod( 'handle_request' );
		$method->setAccessible( true );
		$response = $method->invoke( $this->bridge, $json );
		$decoded  = json_decode( $response, true );

		$this->assertIsArray( $decoded );

		return $decoded;
	}

	/** @param mixed $value @return mixed */
	private function normalizeTestWireObjects( $value, ?string $key = null ) {
		$object_keys = array(
			'_meta',
			'arguments',
			'capabilities',
			'io.modelcontextprotocol/clientCapabilities',
			'params',
		);
		if ( array() === $value && null !== $key && in_array( $key, $object_keys, true ) ) {
			return new \stdClass();
		}
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$result = array();
		foreach ( $value as $item_key => $item ) {
			$result[ $item_key ] = $this->normalizeTestWireObjects( $item, (string) $item_key );
		}

		return $result;
	}

	/** @return array<string, mixed> */
	private function modern_params(): array {
		return array(
			'_meta' => array(
				'io.modelcontextprotocol/protocolVersion' => McpVersionNegotiator::MODERN_PROTOCOL_VERSION,
				'io.modelcontextprotocol/clientCapabilities' => array(),
			),
		);
	}

	private function initialize_legacy(): void {
		$response = $this->handle(
			array(
				'jsonrpc' => '2.0',
				'id'      => 'test-initialize',
				'method'  => 'initialize',
				'params'  => array(
					'protocolVersion' => McpVersionNegotiator::LEGACY_PROTOCOL_VERSION,
					'capabilities'    => array(),
					'clientInfo'      => array(
						'name'    => 'legacy-test-client',
						'version' => '1.0.0',
					),
				),
			)
		);

		$this->assertArrayHasKey( 'result', $response );
	}
}
