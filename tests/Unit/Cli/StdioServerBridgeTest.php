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
					'protocolVersion' => '2025-06-18',
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
				'params'  => array(),
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
			'protocolVersion' => '2025-06-18',
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
		// Use reflection to access private method
		$reflection            = new \ReflectionClass( $this->bridge );
		$handle_request_method = $reflection->getMethod( 'handle_request' );
		$handle_request_method->setAccessible( true );

		$json_input = wp_json_encode(
			array(
				'jsonrpc' => '2.0',
				'id'      => 2,
				'method'  => 'tools/list',
				'params'  => array(),
			)
		);

		$result = $handle_request_method->invoke( $this->bridge, $json_input );

		$this->assertIsString( $result );
		$response = json_decode( $result, true );
		$this->assertArrayHasKey( 'result', $response );
		$this->assertArrayHasKey( 'tools', $response['result'] );
	}

	public function test_handle_request_with_object_params(): void {
		// Use reflection to access private method
		$reflection            = new \ReflectionClass( $this->bridge );
		$handle_request_method = $reflection->getMethod( 'handle_request' );
		$handle_request_method->setAccessible( true );

		// Test with object params (should be converted to array)
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
		$this->assertArrayHasKey( 'result', $response );
	}

	public function test_handle_request_with_non_array_params(): void {
		// Use reflection to access private method
		$reflection            = new \ReflectionClass( $this->bridge );
		$handle_request_method = $reflection->getMethod( 'handle_request' );
		$handle_request_method->setAccessible( true );

		// Test with string params (should be converted to empty array)
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
		// Should still work since ping doesn't require specific params
		$this->assertArrayHasKey( 'result', $response );
	}

	public function test_handle_request_with_exception_in_routing(): void {
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
				'params'  => array(),
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
}
