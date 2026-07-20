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
	 * Set a session's last activity directly, without rewriting its siblings.
	 *
	 * @param string $session_id Session ID.
	 * @param int    $last_activity Unix timestamp.
	 *
	 * @return void
	 */
	private function set_session_last_activity( string $session_id, int $last_activity ): void {
		$session                  = SessionManager::get_session( $this->test_user_id, $session_id );
		$stored_session           = $session;
		$stored_session['session_id'] = $session_id;
		$session['last_activity'] = $last_activity;
		$updated_session           = $session;
		$updated_session['session_id'] = $session_id;

		update_user_meta( $this->test_user_id, 'mcp_adapter_sessions', $updated_session, $stored_session );
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

		// Manually modify one session to be expired.
		$this->set_session_last_activity( $session_id_1, time() - ( DAY_IN_SECONDS + 3600 ) );

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

		// Directly update the timestamp to simulate time passing beyond throttle window.
		$old_timestamp = time() - 61;
		$this->set_session_last_activity( $session_id, $old_timestamp );

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
		// Manually expire the session by backdating its last_activity.
		$this->set_session_last_activity( $short_session, time() - 3 );

		$is_valid = SessionManager::validate_session( $this->test_user_id, $short_session );
		$this->assertFalse( $is_valid ); // Should be expired

		remove_all_filters( 'mcp_adapter_session_inactivity_timeout' );
	}

	/**
	 * Test validation skips last_activity update within throttle window
	 */
	public function test_validation_skips_last_activity_update_within_throttle_window(): void {
		$session_id = SessionManager::create_session( $this->test_user_id, array() );
		$this->assertIsString( $session_id );

		// Record the last_activity right after creation
		$sessions_before   = SessionManager::get_all_user_sessions( $this->test_user_id );
		$original_activity = $sessions_before[ $session_id ]['last_activity'];

		// Validate immediately (within the 60s throttle window)
		$is_valid = SessionManager::validate_session( $this->test_user_id, $session_id );
		$this->assertTrue( $is_valid );

		// last_activity should remain unchanged
		$sessions_after = SessionManager::get_all_user_sessions( $this->test_user_id );
		$this->assertSame( $original_activity, $sessions_after[ $session_id ]['last_activity'] );
	}

	/**
	 * Test validate_session does not call cleanup
	 */
	public function test_validation_does_not_call_cleanup(): void {
		// Create two sessions
		$valid_session_id   = SessionManager::create_session( $this->test_user_id, array() );
		$expired_session_id = SessionManager::create_session( $this->test_user_id, array() );
		$this->assertIsString( $valid_session_id );
		$this->assertIsString( $expired_session_id );

		// Backdate one session to make it expired.
		$this->set_session_last_activity( $expired_session_id, time() - ( DAY_IN_SECONDS + 3600 ) );

		// Validate the valid session
		$is_valid = SessionManager::validate_session( $this->test_user_id, $valid_session_id );
		$this->assertTrue( $is_valid );

		// The expired session should still exist (cleanup not called)
		$sessions_after = SessionManager::get_all_user_sessions( $this->test_user_id );
		$this->assertArrayHasKey( $expired_session_id, $sessions_after );
	}

	/**
	 * Test session stays alive when inactivity timeout is less than default throttle interval
	 */
	public function test_validation_clamps_throttle_when_inactivity_timeout_is_low(): void {
		// Set inactivity timeout to 30s (less than the default 60s throttle)
		add_filter(
			'mcp_adapter_session_inactivity_timeout',
			static function () {
				return 30;
			}
		);

		$session_id = SessionManager::create_session( $this->test_user_id, array() );
		$this->assertIsString( $session_id );

		// Backdate last_activity by 16s (more than half of 30s timeout = clamped interval of 15s).
		$old_activity = time() - 16;
		$this->set_session_last_activity( $session_id, $old_activity );

		// Validate — should succeed AND update last_activity because 16s > clamped interval (15s)
		$is_valid = SessionManager::validate_session( $this->test_user_id, $session_id );
		$this->assertTrue( $is_valid );

		$sessions_after = SessionManager::get_all_user_sessions( $this->test_user_id );
		$this->assertGreaterThan(
			$old_activity,
			$sessions_after[ $session_id ]['last_activity']
		);

		remove_all_filters( 'mcp_adapter_session_inactivity_timeout' );
	}

	/**
	 * Test legacy collection sessions migrate without invalidating active clients.
	 */
	public function test_legacy_sessions_migrate_and_remain_valid(): void {
		$session_id = 'd1b2c3d4-e5f6-4789-abcd-0123456789ab';
		$session    = array(
			'created_at'    => time() - 10,
			'last_activity' => time() - 10,
			'client_params' => array( 'name' => 'released-client' ),
		);

		update_user_meta( $this->test_user_id, 'mcp_adapter_sessions', array( $session_id => $session ) );

		$this->assertTrue( SessionManager::validate_session( $this->test_user_id, $session_id ) );
		$this->assertSame( $session, SessionManager::get_session( $this->test_user_id, $session_id ) );
		$stored_sessions = get_user_meta( $this->test_user_id, 'mcp_adapter_sessions', false );
		$this->assertCount( 1, $stored_sessions );
		$this->assertSame( $session_id, $stored_sessions[0]['session_id'] );
	}

	/**
	 * Test legacy migration removes only the legacy map, not sibling rows.
	 */
	public function test_legacy_migration_preserves_independent_session_rows(): void {
		$legacy_session_id = 'd1b2c3d4-e5f6-4789-abcd-0123456789ab';
		$new_session_id    = 'e1b2c3d4-e5f6-4789-abcd-0123456789ab';
		$legacy_session    = array(
			'created_at'    => time() - 10,
			'last_activity' => time() - 10,
			'client_params' => array( 'name' => 'released-client' ),
		);
		$new_session       = array(
			'session_id'    => $new_session_id,
			'created_at'    => time() - 10,
			'last_activity' => time() - 10,
			'client_params' => array( 'name' => 'new-client' ),
		);

		add_user_meta( $this->test_user_id, 'mcp_adapter_sessions', array( $legacy_session_id => $legacy_session ) );
		add_user_meta( $this->test_user_id, 'mcp_adapter_sessions', $new_session );

		$sessions = SessionManager::get_all_user_sessions( $this->test_user_id );
		$this->assertArrayHasKey( $legacy_session_id, $sessions );
		$this->assertArrayHasKey( $new_session_id, $sessions );
		$this->assertSame( $new_session['client_params'], $sessions[ $new_session_id ]['client_params'] );
	}

	/**
	 * Test legacy migration reuses rows created before a previous migration completed.
	 */
	public function test_legacy_migration_does_not_duplicate_existing_session_rows(): void {
		$session_id = 'd1b2c3d4-e5f6-4789-abcd-0123456789ab';
		$session    = array(
			'created_at'    => time() - 10,
			'last_activity' => time() - 10,
			'client_params' => array( 'name' => 'released-client' ),
		);

		add_user_meta( $this->test_user_id, 'mcp_adapter_sessions', array( $session_id => $session ) );
		add_user_meta( $this->test_user_id, 'mcp_adapter_sessions', array_merge( array( 'session_id' => $session_id ), $session ) );

		$this->assertSame( array( $session_id => $session ), SessionManager::get_all_user_sessions( $this->test_user_id ) );
		$this->assertCount( 1, get_user_meta( $this->test_user_id, 'mcp_adapter_sessions', false ) );
	}

	/**
	 * Test deletion reports a targeted mutation failure.
	 */
	public function test_delete_session_returns_false_when_targeted_delete_fails(): void {
		$session_id = SessionManager::create_session( $this->test_user_id, array() );
		$this->assertIsString( $session_id );

		add_filter(
			'delete_user_metadata',
			static function ( $check, $user_id, $meta_key ) {
				if ( 'mcp_adapter_sessions' === $meta_key ) {
					return false;
				}

				return $check;
			},
			10,
			3
		);

		$this->assertFalse( SessionManager::delete_session( $this->test_user_id, $session_id ) );
		$this->assertArrayHasKey( $session_id, SessionManager::get_all_user_sessions( $this->test_user_id ) );

		remove_all_filters( 'delete_user_metadata' );
	}

	/**
	 * Test mutations of one session preserve every sibling session.
	 */
	public function test_session_mutations_preserve_siblings(): void {
		$expired_session_id = SessionManager::create_session( $this->test_user_id, array( 'name' => 'expired' ) );
		$updated_session_id = SessionManager::create_session( $this->test_user_id, array( 'name' => 'updated' ) );
		$sibling_session_id = SessionManager::create_session( $this->test_user_id, array( 'name' => 'sibling' ) );
		$this->assertIsString( $expired_session_id );
		$this->assertIsString( $updated_session_id );
		$this->assertIsString( $sibling_session_id );
		$this->assertCount( 3, get_user_meta( $this->test_user_id, 'mcp_adapter_sessions', false ) );

		$sibling = SessionManager::get_session( $this->test_user_id, $sibling_session_id );
		$this->set_session_last_activity( $expired_session_id, time() - DAY_IN_SECONDS - 1 );
		$this->set_session_last_activity( $updated_session_id, time() - 61 );

		$this->assertSame( 1, SessionManager::cleanup_expired_sessions( $this->test_user_id ) );
		$this->assertTrue( SessionManager::validate_session( $this->test_user_id, $updated_session_id ) );
		$this->assertTrue( SessionManager::delete_session( $this->test_user_id, $updated_session_id ) );

		$this->assertFalse( SessionManager::get_session( $this->test_user_id, $expired_session_id ) );
		$this->assertFalse( SessionManager::get_session( $this->test_user_id, $updated_session_id ) );
		$this->assertSame( $sibling, SessionManager::get_session( $this->test_user_id, $sibling_session_id ) );
	}
}
