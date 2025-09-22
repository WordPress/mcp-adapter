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

	private int $test_user_id;

	public function set_up(): void {
		parent::set_up();

		// Create a test user
		$this->test_user_id = wp_create_user( 'mcp_session_test_user', 'test_password', 'session_test@example.com' );
		$this->assertIsInt( $this->test_user_id );
		$this->assertGreaterThan( 0, $this->test_user_id );
	}

	public function tear_down(): void {
		// Clean up all sessions for test user
		if ( $this->test_user_id ) {
			delete_user_meta( $this->test_user_id, 'mcp_adapter_sessions' );
			wp_delete_user( $this->test_user_id );
		}

		parent::tear_down();
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
		$this->assertEquals( McpErrorFactory::INVALID_PARAMS, $result['error']['code'] );
		$this->assertStringContainsString( 'Invalid or expired session', $result['error']['message'] );
	}
}
