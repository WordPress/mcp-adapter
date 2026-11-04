<?php
/**
 * Tests for MCP Session Manager
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Transport;

use WP\MCP\Tests\Fixtures\DummyErrorHandler;
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
	 * Oversized initialization cannot change existing sessions or issue storage writes.
	 *
	 * @since 0.4.2
	 */
	public function test_create_session_rejects_oversized_params_without_writes(): void {
		$session_id = SessionManager::create_session( $this->test_user_id, array( 'name' => 'existing-client' ) );
		$this->assertIsString( $session_id );
		$before   = SessionManager::get_all_user_sessions( $this->test_user_id );
		$params   = array( 'capabilities' => array( 'experimental' => array( 'blob' => str_repeat( 'x', 1024 * 1024 ) ) ) );
		$meta_key = 'mcp_adapter_sessions';
		$writes   = 0;
		$observe  = static function ( string $query ) use ( $meta_key, &$writes ): string {
			if ( false !== strpos( $query, $meta_key ) && preg_match( '/^\s*(INSERT|UPDATE|DELETE)/i', $query ) ) {
				++$writes;
			}

			return $query;
		};

		DummyErrorHandler::reset();
		add_filter( 'query', $observe );
		try {
			$this->assertFalse( SessionManager::create_session( $this->test_user_id, $params, new DummyErrorHandler() ) );
			$this->assertSame( 0, $writes );
			$this->assertSame( $before, SessionManager::get_all_user_sessions( $this->test_user_id ) );
			$this->assertCount( 1, DummyErrorHandler::$logs );
			$this->assertSame( strlen( maybe_serialize( $params ) ), DummyErrorHandler::$logs[0]['context']['bytes'] );
			$this->assertSame( 4096, DummyErrorHandler::$logs[0]['context']['limit'] );
			$this->assertStringNotContainsString( str_repeat( 'x', 100 ), (string) wp_json_encode( DummyErrorHandler::$logs ) );
		} finally {
			remove_filter( 'query', $observe );
			DummyErrorHandler::reset();
		}
	}

	/**
	 * Count serialized storage bytes, including overhead, without truncating accepted data.
	 *
	 * @since 0.4.2
	 * @dataProvider serialized_params_boundary_provider
	 *
	 * @param int  $payload_bytes    String content bytes.
	 * @param int  $serialized_bytes Full stored parameter bytes.
	 * @param bool $accepted         Whether the session can be persisted.
	 */
	public function test_create_session_serialized_params_boundary( int $payload_bytes, int $serialized_bytes, bool $accepted ): void {
		$params = array( 'blob' => str_repeat( 'x', $payload_bytes ) );
		$this->assertSame( $serialized_bytes, strlen( maybe_serialize( $params ) ) );

		DummyErrorHandler::reset();
		try {
			$validation = SessionManager::validate_client_params( $params );
			$session_id = SessionManager::create_session( $this->test_user_id, $params, new DummyErrorHandler() );
			if ( ! $accepted ) {
				$this->assertInstanceOf( \WP_Error::class, $validation );
				$this->assertSame( 'mcp_session_params_too_large', $validation->get_error_code() );
				$this->assertSame(
					array(
						'bytes' => $serialized_bytes,
						'limit' => 4096,
					),
					$validation->get_error_data()
				);
				$this->assertStringNotContainsString( 'xxx', $validation->get_error_message() );
				$this->assertFalse( $session_id );
				$this->assertFalse( metadata_exists( 'user', $this->test_user_id, 'mcp_adapter_sessions' ) );
				return;
			}

			$this->assertTrue( $validation );
			$this->assertIsString( $session_id );
			$sessions = SessionManager::get_all_user_sessions( $this->test_user_id );
			$this->assertSame( $params, $sessions[ $session_id ]['client_params'] );
			$this->assertTrue( SessionManager::validate_session( $this->test_user_id, $session_id ) );
		} finally {
			DummyErrorHandler::reset();
		}
	}

	/**
	 * Exact byte fixtures include the 27-byte PHP array/string serialization overhead.
	 *
	 * @since 0.4.2
	 * @return array<string, array{int, int, bool}>
	 */
	public function serialized_params_boundary_provider(): array {
		return array(
			'below limit' => array( 4068, 4095, true ),
			'at limit'    => array( 4069, 4096, true ),
			'above limit' => array( 4070, 4097, false ),
		);
	}

	/**
	 * A map stored before the limit existed is discarded with one small delete, not rewritten.
	 *
	 * @since 0.4.2
	 */
	public function test_get_all_user_sessions_discards_oversized_legacy_map(): void {
		$meta_key   = 'mcp_adapter_sessions';
		$legacy_id  = wp_generate_uuid4();
		$legacy_map = array(
			$legacy_id => array(
				'created_at'    => time(),
				'last_activity' => time(),
				'client_params' => array( 'capabilities' => array( 'experimental' => array( 'blob' => str_repeat( 'x', 1024 * 1024 ) ) ) ),
			),
		);
		$this->assertNotFalse( update_user_meta( $this->test_user_id, $meta_key, $legacy_map ) );
		wp_cache_delete( $this->test_user_id, 'user_meta' );

		$queries = array();
		$observe = static function ( string $query ) use ( &$queries ): string {
			// The DELETE issued by delete_metadata() selects by meta ID and does not repeat the key.
			if ( false !== strpos( $query, 'usermeta' ) && preg_match( '/^\s*(INSERT|UPDATE|DELETE)\b/i', $query, $matches ) ) {
				$queries[] = strtoupper( $matches[1] );
			}

			return $query;
		};

		DummyErrorHandler::reset();
		add_filter( 'query', $observe );
		try {
			$this->assertSame( array(), SessionManager::get_all_user_sessions( $this->test_user_id, new DummyErrorHandler() ) );
			$this->assertSame( array( 'DELETE' ), $queries );
			$this->assertFalse( metadata_exists( 'user', $this->test_user_id, $meta_key ) );
			$this->assertCount( 1, DummyErrorHandler::$logs );
			$this->assertSame( 1, DummyErrorHandler::$logs[0]['context']['sessions'] );
			$this->assertSame( strlen( maybe_serialize( $legacy_map ) ), DummyErrorHandler::$logs[0]['context']['bytes'] );
			$this->assertSame( 4096 + 256, DummyErrorHandler::$logs[0]['context']['budget'] );
			$this->assertStringNotContainsString( str_repeat( 'x', 100 ), (string) wp_json_encode( DummyErrorHandler::$logs ) );

			// The user is no longer locked out: the next initialize stores a small fresh map.
			$this->assertFalse( SessionManager::validate_session( $this->test_user_id, $legacy_id ) );
			$session_id = SessionManager::create_session( $this->test_user_id, array( 'name' => 'fresh-client' ), new DummyErrorHandler() );
			$this->assertIsString( $session_id );
			$this->assertSame( array( $session_id ), array_keys( SessionManager::get_all_user_sessions( $this->test_user_id ) ) );
			$this->assertSame( array( 'DELETE', 'INSERT' ), $queries );
		} finally {
			remove_filter( 'query', $observe );
			DummyErrorHandler::reset();
		}
	}

	/**
	 * A full map of sessions at the parameter limit survives reads after the session limit is lowered below its count.
	 *
	 * @since 0.4.2
	 */
	public function test_get_all_user_sessions_keeps_full_map_after_lowering_a_raised_limit(): void {
		$params = array( 'blob' => str_repeat( 'x', 4069 ) );
		$this->assertSame( 4096, strlen( maybe_serialize( $params ) ) );

		$limit_64 = static function (): int {
			return 64;
		};
		$limit_32 = static function (): int {
			return 32;
		};
		add_filter( 'mcp_adapter_session_max_per_user', $limit_64 );
		DummyErrorHandler::reset();
		try {
			for ( $i = 0; $i < 64; ++$i ) {
				$this->assertIsString( SessionManager::create_session( $this->test_user_id, $params, new DummyErrorHandler() ) );
			}
			$this->assertCount( 64, SessionManager::get_all_user_sessions( $this->test_user_id, new DummyErrorHandler() ) );

			remove_filter( 'mcp_adapter_session_max_per_user', $limit_64 );
			add_filter( 'mcp_adapter_session_max_per_user', $limit_32 );
			wp_cache_delete( $this->test_user_id, 'user_meta' );
			$sessions = SessionManager::get_all_user_sessions( $this->test_user_id, new DummyErrorHandler() );
			$this->assertCount( 64, $sessions );
			$this->assertTrue( metadata_exists( 'user', $this->test_user_id, 'mcp_adapter_sessions' ) );
			$this->assertSame( array(), DummyErrorHandler::$logs );

			// The lowered limit still applies to new sessions: one eviction per creation.
			$this->assertIsString( SessionManager::create_session( $this->test_user_id, $params, new DummyErrorHandler() ) );
			$this->assertCount( 64, SessionManager::get_all_user_sessions( $this->test_user_id, new DummyErrorHandler() ) );
		} finally {
			remove_filter( 'mcp_adapter_session_max_per_user', $limit_64 );
			remove_filter( 'mcp_adapter_session_max_per_user', $limit_32 );
			DummyErrorHandler::reset();
		}
	}

	/**
	 * Recovery that loses the race to a concurrent request keeps that request's fresh session.
	 *
	 * @since 0.4.2
	 */
	public function test_get_all_user_sessions_recovery_does_not_delete_a_concurrently_replaced_map(): void {
		$meta_key   = 'mcp_adapter_sessions';
		$legacy_map = array(
			wp_generate_uuid4() => array(
				'created_at'    => time(),
				'last_activity' => time(),
				'client_params' => array( 'blob' => str_repeat( 'x', 1024 * 1024 ) ),
			),
		);
		$fresh_map  = array(
			wp_generate_uuid4() => array(
				'created_at'    => time(),
				'last_activity' => time(),
				'client_params' => array( 'name' => 'fresh-client' ),
			),
		);
		$this->assertNotFalse( update_user_meta( $this->test_user_id, $meta_key, $legacy_map ) );
		wp_cache_delete( $this->test_user_id, 'user_meta' );

		// Between the read and the conditional delete, another request replaces the row.
		$user_id = $this->test_user_id;
		$raced   = false;
		$race    = static function ( string $query ) use ( $user_id, $meta_key, $fresh_map, &$raced ): string {
			if ( ! $raced && preg_match( '/^\s*DELETE\b.*LENGTH/is', $query ) ) {
				$raced = true;
				delete_user_meta( $user_id, $meta_key );
				update_user_meta( $user_id, $meta_key, $fresh_map );
			}

			return $query;
		};

		DummyErrorHandler::reset();
		add_filter( 'query', $race );
		try {
			$this->assertSame( $fresh_map, SessionManager::get_all_user_sessions( $this->test_user_id, new DummyErrorHandler() ) );
			$this->assertTrue( $raced );
			$this->assertSame( $fresh_map, get_user_meta( $this->test_user_id, $meta_key, true ) );
			$this->assertSame( array(), DummyErrorHandler::$logs );
		} finally {
			remove_filter( 'query', $race );
			DummyErrorHandler::reset();
		}
	}

	/**
	 * Recovery salts the users query cache like delete_user_meta() does, and only when a row was deleted.
	 *
	 * @since 0.4.2
	 */
	public function test_get_all_user_sessions_recovery_bumps_users_last_changed_only_when_a_row_is_deleted(): void {
		$meta_key   = 'mcp_adapter_sessions';
		$legacy_map = array(
			wp_generate_uuid4() => array(
				'created_at'    => time(),
				'last_activity' => time(),
				'client_params' => array( 'blob' => str_repeat( 'x', 1024 * 1024 ) ),
			),
		);
		$this->assertNotFalse( update_user_meta( $this->test_user_id, $meta_key, $legacy_map ) );
		wp_cache_delete( $this->test_user_id, 'user_meta' );

		$before = wp_cache_get_last_changed( 'users' );
		$this->assertSame( array(), SessionManager::get_all_user_sessions( $this->test_user_id, new DummyErrorHandler() ) );
		$this->assertNotSame( $before, wp_cache_get_last_changed( 'users' ) );

		// A map within budget is served as is and leaves the salt alone.
		$this->assertIsString( SessionManager::create_session( $this->test_user_id, array( 'name' => 'fresh-client' ), new DummyErrorHandler() ) );
		$after_write = wp_cache_get_last_changed( 'users' );
		$this->assertCount( 1, SessionManager::get_all_user_sessions( $this->test_user_id, new DummyErrorHandler() ) );
		$this->assertSame( $after_write, wp_cache_get_last_changed( 'users' ) );
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
