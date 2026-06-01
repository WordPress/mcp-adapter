<?php
/**
 * Tests for HttpRequestHandler class.
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
use WP\MCP\Transport\Infrastructure\HttpRequestHandler;
use WP\MCP\Transport\Infrastructure\McpTransportContext;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Test HttpRequestHandler functionality.
 */
final class HttpRequestHandlerTest extends TestCase {

	private HttpRequestHandler $handler;
	private McpTransportContext $context;

	public function set_up(): void {
		parent::set_up();

		// Set current user for session management
		wp_set_current_user( 1 );

		// Create MCP server
		$server = new McpServer(
			'test-server',
			'mcp/v1',
			'/test-mcp',
			'Test MCP Server',
			'Test server for HTTP request handler',
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
		$this->handler = new HttpRequestHandler( $this->context );
	}

	public function test_handle_request_options(): void {
		$request = new WP_REST_Request( 'OPTIONS', '/test-mcp' );
		$context = new HttpRequestContext( $request );

		$response = $this->handler->handle_request( $context );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertEquals( 405, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'error', $data );
		$this->assertStringContainsString( 'Method not allowed', $data['error']['message'] );
	}

	public function test_handle_request_post_invalid_json(): void {
		$request = new WP_REST_Request( 'POST', '/test-mcp' );
		$request->set_body( 'invalid json' );
		$request->set_header( 'Content-Type', 'application/json' );

		$context = new HttpRequestContext( $request );

		$response = $this->handler->handle_request( $context );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertEquals( 400, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'error', $data );
		$this->assertEquals( McpErrorFactory::PARSE_ERROR, $data['error']['code'] );
	}

	public function test_handle_request_post_initialize(): void {
		$request = $this->createPostRequest(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'initialize',
				'params'  => array(
					'protocolVersion' => '2025-11-25',
					'clientInfo'      => array(
						'name'    => 'test-client',
						'version' => '1.0.0',
					),
				),
			)
		);

		$context = new HttpRequestContext( $request );

		$response = $this->handler->handle_request( $context );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'jsonrpc', $data );
		$this->assertEquals( '2.0', $data['jsonrpc'] );
		$this->assertArrayHasKey( 'result', $data );
	}

	public function test_handle_request_post_initialize_preserves_string_id(): void {
		$request = $this->createPostRequest(
			array(
				'jsonrpc' => '2.0',
				'id'      => 'req-abc-123',
				'method'  => 'initialize',
				'params'  => array(
					'protocolVersion' => '2025-11-25',
					'clientInfo'      => array(
						'name'    => 'test-client',
						'version' => '1.0.0',
					),
				),
			)
		);

		$context  = new HttpRequestContext( $request );
		$response = $this->handler->handle_request( $context );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'id', $data );
		$this->assertSame( 'req-abc-123', $data['id'] );
		$this->assertArrayHasKey( 'result', $data );
	}

	public function test_handle_request_post_invalid_session(): void {
		$request = $this->createPostRequest(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'tools/list',
				'params'  => array(),
			)
		);
		$request->set_header( 'Mcp-Session-Id', 'invalid-session' );

		$context = new HttpRequestContext( $request );

		$response = $this->handler->handle_request( $context );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertEquals( 404, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'error', $data );
		$this->assertStringContainsString( 'Invalid or expired session', $data['error']['message'] );
	}

	public function test_handle_request_post_valid_session(): void {
		// First create a session
		$init_request  = $this->createPostRequest(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'initialize',
				'params'  => array(
					'protocolVersion' => '2025-11-25',
					'clientInfo'      => array(
						'name'    => 'test-client',
						'version' => '1.0.0',
					),
				),
			)
		);
		$init_context  = new HttpRequestContext( $init_request );
		$init_response = $this->handler->handle_request( $init_context );

		// Extract session ID from headers (if available)
		$headers    = $init_response->get_headers();
		$session_id = $headers['Mcp-Session-Id'] ?? 'test-session-id';

		// Test subsequent request with session
		$request = $this->createPostRequest(
			array(
				'jsonrpc' => '2.0',
				'id'      => 2,
				'method'  => 'tools/list',
				'params'  => array(),
			)
		);
		$request->set_header( 'Mcp-Session-Id', $session_id );

		$context = new HttpRequestContext( $request );

		$response = $this->handler->handle_request( $context );

		$this->assertInstanceOf( WP_REST_Response::class, $response );

		$data = $response->get_data();
		// Should either have result (200) or session error (404 per MCP spec)
		$this->assertTrue( isset( $data['result'] ) || isset( $data['error'] ) );
		if ( isset( $data['error'] ) ) {
			$this->assertEquals( 404, $response->get_status() );
		} else {
			$this->assertEquals( 200, $response->get_status() );
		}
	}

	public function test_handle_request_post_batch(): void {
		// First initialize to create session
		$init_request  = $this->createPostRequest(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'initialize',
				'params'  => array(
					'protocolVersion' => '2025-11-25',
					'clientInfo'      => array(
						'name'    => 'test-client',
						'version' => '1.0.0',
					),
				),
			)
		);
		$init_context  = new HttpRequestContext( $init_request );
		$init_response = $this->handler->handle_request( $init_context );
		$headers       = $init_response->get_headers();
		$session_id    = $headers['Mcp-Session-Id'] ?? 'test-session-id';

		// Test batch request
		$batch = array(
			array(
				'jsonrpc' => '2.0',
				'id'      => 2,
				'method'  => 'tools/list',
				'params'  => array(),
			),
			array(
				'jsonrpc' => '2.0',
				'id'      => 3,
				'method'  => 'resources/list',
				'params'  => array(),
			),
		);

		$request = $this->createPostRequest( $batch );
		$request->set_header( 'Mcp-Session-Id', $session_id );

		$context = new HttpRequestContext( $request );

		$response = $this->handler->handle_request( $context );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertCount( 2, $data );
	}

	public function test_handle_request_post_notification(): void {
		// Test notification (no id field)
		$request = $this->createPostRequest(
			array(
				'jsonrpc' => '2.0',
				'method'  => 'notifications/cancelled',
				'params'  => array( 'requestId' => 123 ),
			)
		);

		$context = new HttpRequestContext( $request );

		$response = $this->handler->handle_request( $context );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertEquals( 202, $response->get_status() );
		$this->assertNull( $response->get_data() );
	}

	public function test_handle_request_get_sse(): void {
		$request = new WP_REST_Request( 'GET', '/test-mcp' );
		$request->set_header( 'Accept', 'text/event-stream' );

		$context = new HttpRequestContext( $request );

		$response = $this->handler->handle_request( $context );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertEquals( 405, $response->get_status() );
		// SSE not implemented returns 405 with no body per HTTP standards
		$this->assertNull( $response->get_data() );
	}

	public function test_handle_request_delete_session(): void {
		// First create a session
		$init_request  = $this->createPostRequest(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'initialize',
				'params'  => array(
					'protocolVersion' => '2025-11-25',
					'clientInfo'      => array(
						'name'    => 'test-client',
						'version' => '1.0.0',
					),
				),
			)
		);
		$init_context  = new HttpRequestContext( $init_request );
		$init_response = $this->handler->handle_request( $init_context );
		$headers       = $init_response->get_headers();
		$session_id    = $headers['Mcp-Session-Id'] ?? 'test-session-id';

		// Test session termination
		$request = new WP_REST_Request( 'DELETE', '/test-mcp' );
		$request->set_header( 'Mcp-Session-Id', $session_id );

		$context = new HttpRequestContext( $request );

		$response = $this->handler->handle_request( $context );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertEquals( 200, $response->get_status() );
		$this->assertNull( $response->get_data() );
	}

	public function test_handle_request_unsupported_method(): void {
		$request = new WP_REST_Request( 'PATCH', '/test-mcp' );
		$context = new HttpRequestContext( $request );

		$response = $this->handler->handle_request( $context );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertEquals( 405, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'error', $data );
		$this->assertEquals( McpErrorFactory::INVALID_REQUEST, $data['error']['code'] );
		$this->assertStringContainsString( 'Method not allowed', $data['error']['message'] );
	}

	public function test_handle_request_post_withNoProtocolVersionHeader_acceptsRequest(): void {
		// Create session first.
		$session_id = $this->initializeAndGetSessionId();

		$request = $this->createPostRequest(
			array(
				'jsonrpc' => '2.0',
				'id'      => 2,
				'method'  => 'tools/list',
				'params'  => array(),
			)
		);
		$request->set_header( 'Mcp-Session-Id', $session_id );
		// No MCP-Protocol-Version header set.

		$context  = new HttpRequestContext( $request );
		$response = $this->handler->handle_request( $context );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'result', $data );
		$this->assertArrayNotHasKey( 'error', $data );
	}

	public function test_handle_request_post_withSupportedProtocolVersionHeader_acceptsRequest(): void {
		// Create session first.
		$session_id = $this->initializeAndGetSessionId();

		$request = $this->createPostRequest(
			array(
				'jsonrpc' => '2.0',
				'id'      => 2,
				'method'  => 'tools/list',
				'params'  => array(),
			)
		);
		$request->set_header( 'Mcp-Session-Id', $session_id );
		$request->set_header( 'Mcp-Protocol-Version', '2025-11-25' );

		$context  = new HttpRequestContext( $request );
		$response = $this->handler->handle_request( $context );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'result', $data );
		$this->assertArrayNotHasKey( 'error', $data );
	}

	public function test_handle_request_post_withUnsupportedProtocolVersionHeader_returnsError(): void {
		// Create session first.
		$session_id = $this->initializeAndGetSessionId();

		$request = $this->createPostRequest(
			array(
				'jsonrpc' => '2.0',
				'id'      => 2,
				'method'  => 'tools/list',
				'params'  => array(),
			)
		);
		$request->set_header( 'Mcp-Session-Id', $session_id );
		$request->set_header( 'Mcp-Protocol-Version', '9999-99-99' );

		$context  = new HttpRequestContext( $request );
		$response = $this->handler->handle_request( $context );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		// INVALID_REQUEST (-32600) maps to HTTP 400 via McpErrorFactory::mcp_error_to_http_status().
		$this->assertEquals( 400, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'error', $data );
		$this->assertEquals( 2, $data['id'] );
		$this->assertEquals( McpErrorFactory::INVALID_REQUEST, $data['error']['code'] );
		$this->assertStringContainsString( 'Unsupported protocol version', $data['error']['message'] );
		$this->assertStringContainsString( '9999-99-99', $data['error']['message'] );
	}

	public function test_handle_request_post_initialize_skipsProtocolVersionHeaderValidation(): void {
		$request = $this->createPostRequest(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'initialize',
				'params'  => array(
					'protocolVersion' => '2025-11-25',
					'clientInfo'      => array(
						'name'    => 'test-client',
						'version' => '1.0.0',
					),
				),
			)
		);
		// Set an unsupported protocol version header — should be ignored for initialize.
		$request->set_header( 'Mcp-Protocol-Version', '9999-99-99' );

		$context  = new HttpRequestContext( $request );
		$response = $this->handler->handle_request( $context );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'result', $data );
		$this->assertArrayNotHasKey( 'error', $data );
	}

	// Helper methods

	/**
	 * Initialize a session and return the session ID.
	 *
	 * @return string The session ID.
	 */
	private function initializeAndGetSessionId(): string {
		$init_request  = $this->createPostRequest(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'initialize',
				'params'  => array(
					'protocolVersion' => '2025-11-25',
					'clientInfo'      => array(
						'name'    => 'test-client',
						'version' => '1.0.0',
					),
				),
			)
		);
		$init_context  = new HttpRequestContext( $init_request );
		$init_response = $this->handler->handle_request( $init_context );

		// Verify initialize succeeded.
		$data = $init_response->get_data();
		$this->assertArrayHasKey( 'result', $data, 'Initialize must succeed' );

		// The session header is set via a rest_post_dispatch filter which doesn't
		// fire in unit tests. Read the session ID directly from user meta instead.
		$sessions = get_user_meta( get_current_user_id(), 'mcp_adapter_sessions', true );
		$this->assertNotEmpty( $sessions, 'Initialize must create a session in user meta' );

		// Return the most recently created session ID.
		return (string) array_key_last( $sessions );
	}

	private function createPostRequest( array $body ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/test-mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'Accept', 'application/json, text/event-stream' );
		$request->set_body( json_encode( $body ) );

		return $request;
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
