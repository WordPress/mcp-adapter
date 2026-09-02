<?php
/**
 * Tests for HttpSessionValidator class.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Transport\Infrastructure;

use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;
use WP\MCP\Tests\TestCase;
use WP\MCP\Transport\Infrastructure\HttpRequestContext;
use WP\MCP\Transport\Infrastructure\HttpSessionValidator;
use WP\MCP\Transport\Infrastructure\SessionManager;
use WP_REST_Request;

/**
 * Test HttpSessionValidator functionality.
 */
final class HttpSessionValidatorTest extends TestCase {

	private int $test_user_id = 0;

	public function setUp(): void {
		parent::setUp();

		// Create a test user.
		$this->test_user_id = $this->factory()->user->create(
			array(
				'user_login' => 'mcp_session_test_user',
				'user_pass'  => 'test_password',
				'user_email' => 'session_test@example.com',
			)
		);
	}

	public function tearDown(): void {
		// Clean up all sessions for test user
		if ( $this->test_user_id ) {
			delete_user_meta( $this->test_user_id, self::session_meta_key() );
			wp_delete_user( $this->test_user_id );
		}

		parent::tearDown();
	}

	public function test_validate_session_header_with_valid_session(): void {
		$request = new WP_REST_Request( 'POST', '/test' );
		$request->set_header( 'Mcp-Session-Id', 'test-session-123' );

		$context = new HttpRequestContext( $request );

		$result = HttpSessionValidator::validate_session_header( $context );

		$this->assertIsString( $result );
		$this->assertEquals( 'test-session-123', $result );
	}

	public function test_validate_session_header_with_missing_session(): void {
		$request = new WP_REST_Request( 'POST', '/test' );
		$context = new HttpRequestContext( $request );

		$result = HttpSessionValidator::validate_session_header( $context );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertEquals( McpErrorFactory::INVALID_REQUEST, $result['error']['code'] );
		$this->assertStringContainsString( 'Missing Mcp-Session-Id header', $result['error']['message'] );
	}

	public function test_create_session_with_valid_user(): void {
		wp_set_current_user( $this->test_user_id );

		$client_info = array(
			'name'    => 'test-client',
			'version' => '1.0.0',
		);

		$result = HttpSessionValidator::create_session( $client_info );

		$this->assertIsString( $result );
		$this->assertNotEmpty( $result );

		// Verify session was actually created
		$sessions = SessionManager::get_all_user_sessions( $this->test_user_id );
		$this->assertCount( 1, $sessions );
		$this->assertArrayHasKey( $result, $sessions );
	}

	public function test_create_session_with_no_user(): void {
		wp_set_current_user( 0 );

		$result = HttpSessionValidator::create_session( array() );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertEquals( McpErrorFactory::UNAUTHORIZED, $result['error']['code'] );
		$this->assertStringContainsString( 'User authentication required', $result['error']['message'] );
	}

	public function test_terminate_session_with_valid_session(): void {
		wp_set_current_user( $this->test_user_id );

		// Create a session first
		$session_id = SessionManager::create_session( $this->test_user_id, array() );
		$this->assertIsString( $session_id );

		// Create request with session header
		$request = new WP_REST_Request( 'DELETE', '/test' );
		$request->set_header( 'Mcp-Session-Id', $session_id );

		$context = new HttpRequestContext( $request );

		$result = HttpSessionValidator::terminate_session( $context );

		$this->assertTrue( $result );

		// Verify session was deleted
		$sessions = SessionManager::get_all_user_sessions( $this->test_user_id );
		$this->assertCount( 0, $sessions );
	}

	public function test_terminate_session_with_missing_session(): void {
		wp_set_current_user( $this->test_user_id );

		$request = new WP_REST_Request( 'DELETE', '/test' );
		$context = new HttpRequestContext( $request );

		$result = HttpSessionValidator::terminate_session( $context );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertEquals( McpErrorFactory::INVALID_REQUEST, $result['error']['code'] );
		$this->assertStringContainsString( 'Missing Mcp-Session-Id header', $result['error']['message'] );
	}

	public function test_validate_session_complete_flow(): void {
		wp_set_current_user( $this->test_user_id );

		// Create a session
		$session_id = SessionManager::create_session( $this->test_user_id, array() );
		$this->assertIsString( $session_id );

		// Create request with valid session
		$request = new WP_REST_Request( 'POST', '/test' );
		$request->set_header( 'Mcp-Session-Id', $session_id );

		$context = new HttpRequestContext( $request );

		$result = HttpSessionValidator::validate_session( $context );

		$this->assertTrue( $result );
	}

	public function test_validate_session_with_invalid_user(): void {
		wp_set_current_user( 0 ); // No user

		$request = new WP_REST_Request( 'POST', '/test' );
		$request->set_header( 'Mcp-Session-Id', 'some-session-id' );

		$context = new HttpRequestContext( $request );

		$result = HttpSessionValidator::validate_session( $context );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertEquals( McpErrorFactory::UNAUTHORIZED, $result['error']['code'] );
		$this->assertStringContainsString( 'User not authenticated', $result['error']['message'] );
	}

	public function test_validate_session_with_expired_session(): void {
		wp_set_current_user( $this->test_user_id );

		$request = new WP_REST_Request( 'POST', '/test' );
		$request->set_header( 'Mcp-Session-Id', 'expired-session-id' );

		$context = new HttpRequestContext( $request );

		$result = HttpSessionValidator::validate_session( $context );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertEquals( McpErrorFactory::SESSION_NOT_FOUND, $result['error']['code'] );
		$this->assertStringContainsString( 'Invalid or expired session', $result['error']['message'] );
	}

	/**
	 * Test that error responses use null ID per JSON-RPC 2.0 spec.
	 *
	 * JSON-RPC 2.0 spec: When request ID cannot be determined, use null.
	 * Session validation errors occur before we can parse the request ID.
	 */
	public function test_validate_session_header_error_returns_null_id(): void {
		$request = new WP_REST_Request( 'POST', '/test' );
		$context = new HttpRequestContext( $request );

		$result = HttpSessionValidator::validate_session_header( $context );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'id', $result );
		$this->assertNull( $result['id'] );
	}

	/** A transport that has decoded a safe request ID preserves it on preflight errors. */
	public function test_validate_session_header_error_preserves_readable_id(): void {
		$request = new WP_REST_Request( 'POST', '/test' );
		$context = new HttpRequestContext( $request );

		$result = HttpSessionValidator::validate_session_header( $context, 'request-17' );

		$this->assertIsArray( $result );
		$this->assertSame( 'request-17', $result['id'] );
	}

	/** Request metadata retains only protocol routing headers. */
	public function test_http_context_does_not_copy_credentials_into_request_metadata(): void {
		$request = new WP_REST_Request( 'POST', '/test' );
		$request->set_header( 'Authorization', 'Bearer secret' );
		$request->set_header( 'Cookie', 'session=secret' );
		$request->set_header( 'Origin', 'https://example.org' );
		$request->set_header( 'Mcp-Method', 'tools/call' );
		$request->set_header( 'Mcp-Param-Region', 'eu' );

		$context = new HttpRequestContext( $request );

		$this->assertSame( 'https://example.org', $context->origin_header );
		$this->assertCount( 2, $context->headers );
		$this->assertSame( 'tools/call', $context->headers['mcp-method'] );
		$this->assertSame( 'eu', $context->headers['mcp-param-region'] );
	}

	/**
	 * Test that validate_session authentication error returns null ID.
	 *
	 * JSON-RPC 2.0 spec: When request ID cannot be determined, use null.
	 */
	public function test_validate_session_auth_error_returns_null_id(): void {
		wp_set_current_user( 0 ); // No user

		$request = new WP_REST_Request( 'POST', '/test' );
		$request->set_header( 'Mcp-Session-Id', 'some-session-id' );

		$context = new HttpRequestContext( $request );

		$result = HttpSessionValidator::validate_session( $context );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertArrayHasKey( 'id', $result );
		$this->assertNull( $result['id'] );
	}

	/**
	 * Test that validate_session invalid/expired session error returns null ID.
	 *
	 * JSON-RPC 2.0 spec: When request ID cannot be determined, use null.
	 */
	public function test_validate_session_invalid_session_error_returns_null_id(): void {
		wp_set_current_user( $this->test_user_id );

		$request = new WP_REST_Request( 'POST', '/test' );
		$request->set_header( 'Mcp-Session-Id', 'invalid-session-id' );

		$context = new HttpRequestContext( $request );

		$result = HttpSessionValidator::validate_session( $context );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertArrayHasKey( 'id', $result );
		$this->assertNull( $result['id'] );
	}

	/**
	 * Test that create_session authentication error returns null ID.
	 *
	 * JSON-RPC 2.0 spec: When request ID cannot be determined, use null.
	 */
	public function test_create_session_auth_error_returns_null_id(): void {
		wp_set_current_user( 0 );

		$result = HttpSessionValidator::create_session( array() );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertArrayHasKey( 'id', $result );
		$this->assertNull( $result['id'] );
	}

	/**
	 * Test that terminate_session missing header error returns null ID.
	 *
	 * JSON-RPC 2.0 spec: When request ID cannot be determined, use null.
	 */
	public function test_terminate_session_missing_header_error_returns_null_id(): void {
		wp_set_current_user( $this->test_user_id );

		$request = new WP_REST_Request( 'DELETE', '/test' );
		$context = new HttpRequestContext( $request );

		$result = HttpSessionValidator::terminate_session( $context );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertArrayHasKey( 'id', $result );
		$this->assertNull( $result['id'] );
	}

	/**
	 * Test that terminate_session unauthenticated error returns null ID.
	 *
	 * JSON-RPC 2.0 spec: When request ID cannot be determined, use null.
	 */
	public function test_terminate_session_unauth_error_returns_null_id(): void {
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'DELETE', '/test' );
		$request->set_header( 'Mcp-Session-Id', 'some-session-id' );
		$context = new HttpRequestContext( $request );

		$result = HttpSessionValidator::terminate_session( $context );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertArrayHasKey( 'id', $result );
		$this->assertNull( $result['id'] );
	}
}
