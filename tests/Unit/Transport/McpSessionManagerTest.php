<?php
/**
 * Tests for MCP Session Manager
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Transport;

use WP\MCP\Tests\TestCase;
use WP\MCP\Transport\Infrastructure\SessionManager;

/**
 * Test MCP Session Manager functionality
 *
 * Tests cover:
 * - Session creation and validation
 * - User authentication requirements
 * - Session limits and cleanup
 * - Expiration and inactivity timeouts
 * - User meta storage operations
 */
final class McpSessionManagerTest extends TestCase {

	/**
	 * Test user ID for session operations
	 *
	 * @var int
	 */
	private int $test_user_id;

	/**
	 * Set up test user before each test
	 */
	public function set_up(): void {
		parent::set_up();

		// Create a test user
		$this->test_user_id = wp_create_user( 'mcp_test_user', 'test_password', 'mcp_test@example.com' );
		$this->assertIsInt( $this->test_user_id );
		$this->assertGreaterThan( 0, $this->test_user_id );
	}

	/**
	 * Clean up test user after each test
	 */
	public function tear_down(): void {
		// Clean up all sessions for test user
		if ( $this->test_user_id ) {
			delete_user_meta( $this->test_user_id, 'mcp_adapter_sessions' );
			wp_delete_user( $this->test_user_id );
		}

		parent::tear_down();
	}

	/**
	 * Test successful session creation
	 */
	public function test_create_session_success(): void {
		$client_info = array(
			'name'    => 'test-client',
			'version' => '1.0.0',
		);

		$session_id = SessionManager::create_session( $this->test_user_id, $client_info );

		$this->assertIsString( $session_id );
		$this->assertNotEmpty( $session_id );

		// Verify session is stored
		$sessions = SessionManager::get_all_user_sessions( $this->test_user_id );
		$this->assertCount( 1, $sessions );
		$this->assertArrayHasKey( $session_id, $sessions );
		$this->assertSame( $client_info, $sessions[ $session_id ]['client_params'] );
	}

	/**
	 * Test session creation with invalid user ID
	 */
	public function test_create_session_invalid_user(): void {
		$session_id = SessionManager::create_session( 99999, array() );
		$this->assertFalse( $session_id );
	}

	/**
	 * Test session creation with zero user ID
	 */
	public function test_create_session_zero_user_id(): void {
		$session_id = SessionManager::create_session( 0, array() );
		$this->assertFalse( $session_id );
	}

	/**
	 * Test session validation with valid session
	 */
	public function test_validate_session_success(): void {
		$session_id = SessionManager::create_session( $this->test_user_id, array() );
		$this->assertIsString( $session_id );

		$is_valid = SessionManager::validate_session( $this->test_user_id, $session_id );
		$this->assertTrue( $is_valid );
	}

	/**
	 * Test session validation with invalid session ID
	 */
	public function test_validate_session_invalid_id(): void {
		$is_valid = SessionManager::validate_session( $this->test_user_id, 'invalid-session-id' );
		$this->assertFalse( $is_valid );
	}

	/**
	 * Test session validation with invalid user ID
	 */
	public function test_validate_session_invalid_user(): void {
		$session_id = SessionManager::create_session( $this->test_user_id, array() );
		$this->assertIsString( $session_id );

		$is_valid = SessionManager::validate_session( 99999, $session_id );
		$this->assertFalse( $is_valid );
	}

	/**
	 * Test getting session data
	 */
	public function test_get_session(): void {
		$client_info = array( 'name' => 'test-client' );
		$session_id  = SessionManager::create_session( $this->test_user_id, $client_info );
		$this->assertIsString( $session_id );

		$session_data = SessionManager::get_session( $this->test_user_id, $session_id );
		$this->assertIsArray( $session_data );
		$this->assertArrayHasKey( 'created_at', $session_data );
		$this->assertArrayHasKey( 'last_activity', $session_data );
		$this->assertArrayHasKey( 'client_params', $session_data );
		$this->assertSame( $client_info, $session_data['client_params'] );
	}

	/**
	 * Test session deletion
	 */
	public function test_delete_session(): void {
		$session_id = SessionManager::create_session( $this->test_user_id, array() );
		$this->assertIsString( $session_id );

		// Verify session exists
		$sessions = SessionManager::get_all_user_sessions( $this->test_user_id );
		$this->assertCount( 1, $sessions );

		// Delete session
		$deleted = SessionManager::delete_session( $this->test_user_id, $session_id );
		$this->assertTrue( $deleted );

		// Verify session is gone
		$sessions = SessionManager::get_all_user_sessions( $this->test_user_id );
		$this->assertCount( 0, $sessions );
	}

	/**
	 * Test deleting non-existent session
	 */
	public function test_delete_nonexistent_session(): void {
		$deleted = SessionManager::delete_session( $this->test_user_id, 'non-existent-id' );
		$this->assertFalse( $deleted );
	}

	/**
	 * Test session limit enforcement
	 */
	public function test_session_limit_enforcement(): void {
		// Set a lower limit for testing
		add_filter(
			'mcp_adapter_session_max_per_user',
			static function () {
				return 3;
			}
		);

		$session_ids = array();

		// Create sessions up to limit
		for ( $i = 1; $i <= 3; $i++ ) {
			$session_id = SessionManager::create_session( $this->test_user_id, array( 'name' => "client-{$i}" ) );
			$this->assertIsString( $session_id );
			$session_ids[] = $session_id;
		}

		$sessions = SessionManager::get_all_user_sessions( $this->test_user_id );
		$this->assertCount( 3, $sessions );

		// Create one more session (should remove oldest)
		$new_session_id = SessionManager::create_session( $this->test_user_id, array( 'name' => 'client-4' ) );
		$this->assertIsString( $new_session_id );

		$sessions = SessionManager::get_all_user_sessions( $this->test_user_id );
		$this->assertCount( 3, $sessions ); // Still 3 sessions

		// First session should be gone (FIFO)
		$this->assertArrayNotHasKey( $session_ids[0], $sessions );
		$this->assertArrayHasKey( $new_session_id, $sessions );

		// Remove filter
		remove_all_filters( 'mcp_adapter_session_max_per_user' );
	}

	/**
	 * Test session cleanup
	 */
	public function test_cleanup_expired_sessions(): void {
		// Create sessions with different timestamps
		$session_id_1 = SessionManager::create_session( $this->test_user_id, array() );
		$session_id_2 = SessionManager::create_session( $this->test_user_id, array() );
		$this->assertIsString( $session_id_1 );
		$this->assertIsString( $session_id_2 );

		// Manually modify one session to be expired
		$sessions                                   = SessionManager::get_all_user_sessions( $this->test_user_id );
		$sessions[ $session_id_1 ]['last_activity'] = time() - ( DAY_IN_SECONDS + 3600 ); // Over 24 hours ago
		update_user_meta( $this->test_user_id, 'mcp_adapter_sessions', $sessions );

		// Run cleanup
		$removed = SessionManager::cleanup_expired_sessions( $this->test_user_id );
		$this->assertSame( 1, $removed );

		// Verify only valid session remains
		$sessions = SessionManager::get_all_user_sessions( $this->test_user_id );
		$this->assertCount( 1, $sessions );
		$this->assertArrayHasKey( $session_id_2, $sessions );
		$this->assertArrayNotHasKey( $session_id_1, $sessions );
	}

	/**
	 * Test getting all user sessions
	 */
	public function test_get_all_user_sessions(): void {
		// Initially no sessions
		$sessions = SessionManager::get_all_user_sessions( $this->test_user_id );
		$this->assertIsArray( $sessions );
		$this->assertCount( 0, $sessions );

		// Create multiple sessions
		$session_id_1 = SessionManager::create_session( $this->test_user_id, array( 'name' => 'client-1' ) );
		$session_id_2 = SessionManager::create_session( $this->test_user_id, array( 'name' => 'client-2' ) );

		$sessions = SessionManager::get_all_user_sessions( $this->test_user_id );
		$this->assertCount( 2, $sessions );
		$this->assertArrayHasKey( $session_id_1, $sessions );
		$this->assertArrayHasKey( $session_id_2, $sessions );
	}

	/**
	 * Test getting sessions for invalid user
	 */
	public function test_get_all_user_sessions_invalid_user(): void {
		$sessions = SessionManager::get_all_user_sessions( 0 );
		$this->assertIsArray( $sessions );
		$this->assertCount( 0, $sessions );
	}


	/**
	 * Test session validation updates last activity
	 */
	public function test_validation_updates_last_activity(): void {
		$session_id = SessionManager::create_session( $this->test_user_id, array() );
		$this->assertIsString( $session_id );

		// Directly update the timestamp to simulate time passing
		$sessions                                 = \WP\MCP\Transport\Infrastructure\SessionManager::get_all_user_sessions( $this->test_user_id );
		$old_timestamp                            = time() - 2;
		$sessions[ $session_id ]['last_activity'] = $old_timestamp;
		update_user_meta( $this->test_user_id, 'mcp_adapter_sessions', $sessions );

		// Validate session (should update last_activity)
		$is_valid = SessionManager::validate_session( $this->test_user_id, $session_id );
		$this->assertTrue( $is_valid );

		$session_after = SessionManager::get_session( $this->test_user_id, $session_id );
		$this->assertIsArray( $session_after );

		$this->assertGreaterThan( $old_timestamp, $session_after['last_activity'] );
	}

	/**
	 * Test configurable session limits via filters
	 */
	public function test_configurable_limits(): void {
		// Test custom max sessions
		add_filter(
			'mcp_adapter_session_max_per_user',
			static function () {
				return 2;
			}
		);

		SessionManager::create_session( $this->test_user_id, array() );
		SessionManager::create_session( $this->test_user_id, array() );
		SessionManager::create_session( $this->test_user_id, array() );

		$sessions = SessionManager::get_all_user_sessions( $this->test_user_id );
		$this->assertCount( 2, $sessions ); // Limit enforced

		remove_all_filters( 'mcp_adapter_session_max_per_user' );

		// Test custom expiration
		add_filter(
			'mcp_adapter_session_inactivity_timeout',
			static function () {
				return 1;
			}
		); // 1 second

		$short_session = SessionManager::create_session( $this->test_user_id, array() );
		// Manually expire the session by backdating its last_activity
		$sessions                                    = SessionManager::get_all_user_sessions( $this->test_user_id );
		$sessions[ $short_session ]['last_activity'] = time() - 3;
		update_user_meta( $this->test_user_id, 'mcp_adapter_sessions', $sessions );

		$is_valid = SessionManager::validate_session( $this->test_user_id, $short_session );
		$this->assertFalse( $is_valid ); // Should be expired

		remove_all_filters( 'mcp_adapter_session_inactivity_timeout' );
	}
}
