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

	public function test_handle_request_post_empty_batch_is_invalid_request(): void {
		$request  = $this->createPostRequest( array() );
		$context  = new HttpRequestContext( $request );
		$response = $this->handler->handle_request( $context );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( McpErrorFactory::INVALID_REQUEST, $response->get_data()['error']['code'] );
	}

	public function test_handle_request_post_initialize(): void {
		$request = $this->createPostRequest(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'initialize',
				'params'  => array(
					'protocolVersion' => '2025-11-25',
					'capabilities'    => array(),
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
					'capabilities'    => array(),
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
					'capabilities'    => array(),
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
					'capabilities'    => array(),
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
					'capabilities'    => array(),
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

	public function test_handle_request_post_with_no_protocol_version_header_rejects_legacy_request(): void {
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
		$this->assertEquals( 400, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame( McpErrorFactory::INVALID_REQUEST, $data['error']['code'] );
		$this->assertStringContainsString( 'Missing MCP-Protocol-Version header', $data['error']['message'] );
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

	public function test_handle_request_post_with_unsupported_protocol_version_returns_exact_error(): void {
		$params = $this->modern_params();
		$params['_meta']['io.modelcontextprotocol/protocolVersion'] = '9999-99-99';
		$request = $this->createPostRequest(
			array(
				'jsonrpc' => '2.0',
				'id'      => 2,
				'method'  => 'tools/list',
				'params'  => $params,
			)
		);
		$request->set_header( 'Mcp-Protocol-Version', '9999-99-99' );

		$context  = new HttpRequestContext( $request );
		$response = $this->handler->handle_request( $context );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertEquals( 400, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'error', $data );
		$this->assertEquals( 2, $data['id'] );
		$this->assertEquals( McpErrorFactory::UNSUPPORTED_PROTOCOL_VERSION, $data['error']['code'] );
		$this->assertStringContainsString( 'Unsupported protocol version', $data['error']['message'] );
		$this->assertSame( '9999-99-99', $data['error']['data']['requested'] );
	}

	public function test_modern_discovery_is_stateless_when_header_and_body_match(): void {
		$request = $this->createPostRequest(
			array(
				'jsonrpc' => '2.0',
				'id'      => 70,
				'method'  => 'server/discover',
				'params'  => $this->modern_params( true ),
			)
		);
		$request->set_header( 'Mcp-Protocol-Version', '2026-07-28' );
		$context  = new HttpRequestContext( $request );
		$response = $this->handler->handle_request( $context );
		$data     = $response->get_data();
		$result   = (array) $data['result'];

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 70, $data['id'] );
		$this->assertSame( 'complete', $result['resultType'] );
		$this->assertSame( array( '2026-07-28', '2025-11-25' ), $result['supportedVersions'] );
		$this->assertSame( 0, $result['ttlMs'] );
		$this->assertSame( 'private', $result['cacheScope'] );
	}

	public function test_modern_request_rejects_missing_protocol_header(): void {
		$request  = $this->createPostRequest(
			array(
				'jsonrpc' => '2.0',
				'id'      => 71,
				'method'  => 'tools/list',
				'params'  => $this->modern_params(),
			)
		);
		$response = $this->handler->handle_request( new HttpRequestContext( $request ) );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( McpErrorFactory::HEADER_MISMATCH, $data['error']['code'] );
		$this->assertSame( 71, $data['id'] );
	}

	public function test_modern_request_rejects_header_body_version_mismatch(): void {
		$request = $this->createPostRequest(
			array(
				'jsonrpc' => '2.0',
				'id'      => 72,
				'method'  => 'tools/list',
				'params'  => $this->modern_params(),
			)
		);
		$request->set_header( 'Mcp-Protocol-Version', '2025-11-25' );
		$response = $this->handler->handle_request( new HttpRequestContext( $request ) );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( McpErrorFactory::HEADER_MISMATCH, $data['error']['code'] );
		$this->assertSame( '2026-07-28', $data['error']['data']['expected'] );
		$this->assertSame( '2025-11-25', $data['error']['data']['actual'] );
	}

	public function test_modern_request_rejects_missing_body_metadata_even_with_header(): void {
		$request = $this->createPostRequest(
			array(
				'jsonrpc' => '2.0',
				'id'      => 75,
				'method'  => 'tools/list',
				'params'  => array(),
			)
		);
		$request->set_header( 'Mcp-Protocol-Version', '2026-07-28' );
		$response = $this->handler->handle_request( new HttpRequestContext( $request ) );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( McpErrorFactory::INVALID_PARAMS, $data['error']['code'] );
		$this->assertSame( 75, $data['id'] );
	}

	public function test_modern_request_does_not_require_legacy_session(): void {
		$request = $this->createPostRequest(
			array(
				'jsonrpc' => '2.0',
				'id'      => 73,
				'method'  => 'tools/list',
				'params'  => $this->modern_params(),
			)
		);
		$request->set_header( 'Mcp-Protocol-Version', '2026-07-28' );
		$response = $this->handler->handle_request( new HttpRequestContext( $request ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'result', $data );
		$this->assertArrayNotHasKey( 'error', $data );
	}

	public function test_legacy_request_rejects_header_that_differs_from_session_revision(): void {
		$session_id = $this->initializeAndGetSessionId();
		$request    = $this->createPostRequest(
			array(
				'jsonrpc' => '2.0',
				'id'      => 74,
				'method'  => 'tools/list',
				'params'  => array(),
			)
		);
		$request->set_header( 'Mcp-Session-Id', $session_id );
		$request->set_header( 'Mcp-Protocol-Version', '2026-07-28' );
		$response = $this->handler->handle_request( new HttpRequestContext( $request ) );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( McpErrorFactory::INVALID_REQUEST, $data['error']['code'] );
		$this->assertStringContainsString( 'does not match', $data['error']['message'] );
	}

	public function test_handle_request_post_initialize_skipsProtocolVersionHeaderValidation(): void {
		$request = $this->createPostRequest(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'initialize',
				'params'  => array(
					'protocolVersion' => '2025-11-25',
					'capabilities'    => array(),
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

	public function test_raw_legacy_initialize_rejects_capabilities_list(): void {
		$response = $this->handleRawRequest(
			'{"jsonrpc":"2.0","id":80,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":[],"clientInfo":{"name":"raw-client","version":"1.0.0"}}}'
		);
		$data     = $response->get_data();

		$this->assertSame( McpErrorFactory::INVALID_PARAMS, $data['error']['code'] );
	}

	public function test_raw_modern_request_accepts_client_capabilities_object(): void {
		$response = $this->handleRawRequest(
			'{"jsonrpc":"2.0","id":81,"method":"tools/list","params":{"_meta":{"io.modelcontextprotocol/protocolVersion":"2026-07-28","io.modelcontextprotocol/clientCapabilities":{}}}}',
			'2026-07-28'
		);
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'result', $data );
	}

	public function test_raw_modern_request_rejects_client_capabilities_list(): void {
		$response = $this->handleRawRequest(
			'{"jsonrpc":"2.0","id":82,"method":"tools/list","params":{"_meta":{"io.modelcontextprotocol/protocolVersion":"2026-07-28","io.modelcontextprotocol/clientCapabilities":[]}}}',
			'2026-07-28'
		);
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( McpErrorFactory::INVALID_PARAMS, $data['error']['code'] );
	}

	public function test_raw_modern_tool_arguments_list_is_not_coerced_to_object(): void {
		$response = $this->handleRawRequest(
			'{"jsonrpc":"2.0","id":83,"method":"tools/call","params":{"_meta":{"io.modelcontextprotocol/protocolVersion":"2026-07-28","io.modelcontextprotocol/clientCapabilities":{}},"name":"test-always-allowed","arguments":[]}}',
			'2026-07-28'
		);
		$data     = $response->get_data();

		$this->assertSame( McpErrorFactory::INVALID_PARAMS, $data['error']['code'] );
	}

	public function test_raw_modern_prompt_arguments_list_is_not_coerced_to_object(): void {
		$response = $this->handleRawRequest(
			'{"jsonrpc":"2.0","id":84,"method":"prompts/get","params":{"_meta":{"io.modelcontextprotocol/protocolVersion":"2026-07-28","io.modelcontextprotocol/clientCapabilities":{}},"name":"test/prompt","arguments":[]}}',
			'2026-07-28'
		);
		$data     = $response->get_data();

		$this->assertSame( McpErrorFactory::INVALID_PARAMS, $data['error']['code'] );
	}

	public function test_raw_modern_continuation_response_list_is_not_coerced_to_map(): void {
		$response = $this->handleRawRequest(
			'{"jsonrpc":"2.0","id":85,"method":"tools/call","params":{"_meta":{"io.modelcontextprotocol/protocolVersion":"2026-07-28","io.modelcontextprotocol/clientCapabilities":{}},"name":"test-always-allowed","arguments":{},"inputResponses":[],"requestState":"opaque"}}',
			'2026-07-28'
		);
		$data     = $response->get_data();

		$this->assertSame( McpErrorFactory::INVALID_PARAMS, $data['error']['code'] );
	}

	public function test_modern_http_response_preserves_numeric_key_objects(): void {
		$filter = static fn() => array(
			'numeric' => (object) array( 0 => 'zero' ),
		);
		add_filter( 'mcp_adapter_tool_call_result', $filter );

		try {
			$response = $this->handleRawRequest(
				'{"jsonrpc":"2.0","id":86,"method":"tools/call","params":{"_meta":{"io.modelcontextprotocol/protocolVersion":"2026-07-28","io.modelcontextprotocol/clientCapabilities":{}},"name":"test-always-allowed","arguments":{}}}',
				'2026-07-28'
			);
			$data     = $response->get_data();
			$result   = (array) $data['result'];

			$this->assertSame( 200, $response->get_status() );
			$this->assertInstanceOf( \stdClass::class, $result['structuredContent']['numeric'] );
			$this->assertStringContainsString( '"numeric":{"0":"zero"}', wp_json_encode( $data ) );
		} finally {
			remove_filter( 'mcp_adapter_tool_call_result', $filter );
		}
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
					'capabilities'    => array(),
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
		$sessions = get_user_meta( get_current_user_id(), self::session_meta_key(), true );
		$this->assertNotEmpty( $sessions, 'Initialize must create a session in user meta' );

		// Return the most recently created session ID.
		return (string) array_key_last( $sessions );
	}

	/** @return array<string, mixed> */
	private function modern_params( bool $include_client_info = false ): array {
		$metadata = array(
			'io.modelcontextprotocol/protocolVersion'    => '2026-07-28',
			'io.modelcontextprotocol/clientCapabilities' => array(),
		);
		if ( $include_client_info ) {
			$metadata['io.modelcontextprotocol/clientInfo'] = array(
				'name'    => 'modern-http-client',
				'version' => '1.0.0',
			);
		}

		return array( '_meta' => $metadata );
	}

	private function createPostRequest( array $body ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/test-mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'Accept', 'application/json, text/event-stream' );
		$request->set_body( wp_json_encode( $this->normalizeTestWireObjects( $body ) ) );

		return $request;
	}

	private function handleRawRequest( string $json, ?string $protocol_version = null ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/test-mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'Accept', 'application/json, text/event-stream' );
		if ( null !== $protocol_version ) {
			$request->set_header( 'Mcp-Protocol-Version', $protocol_version );
		}
		$request->set_body( $json );

		return $this->handler->handle_request( new HttpRequestContext( $request ) );
	}

	/**
	 * Express empty object fields accurately in fixtures assembled as PHP arrays.
	 *
	 * Raw identity regressions set the JSON body directly and bypass this helper.
	 *
	 * @param mixed       $value Fixture value.
	 * @param string|null $key Parent key.
	 * @return mixed
	 */
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
