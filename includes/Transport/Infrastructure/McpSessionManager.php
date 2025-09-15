<?php
/**
 * MCP Session Manager using User Meta
 *
 * Manages MCP sessions using WordPress user meta instead of transients
 * to improve security and prevent DoS attacks.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Transport\Infrastructure;

/**
 * MCP Session Manager
 *
 * Handles session creation, validation, and cleanup using user meta storage.
 * Sessions are tied to authenticated users to prevent anonymous session flooding.
 */
class McpSessionManager {

	/**
	 * User meta key for storing sessions
	 *
	 * @var string
	 */
	private const SESSION_META_KEY = 'mcp_sessions';

	/**
	 * Default maximum sessions per user
	 *
	 * @var int
	 */
	private const DEFAULT_MAX_SESSIONS = 32;

	/**
	 * Default session expiration in seconds (24 hours)
	 *
	 * @var int
	 */
	private const DEFAULT_SESSION_EXPIRATION = DAY_IN_SECONDS;

	/**
	 * Default inactivity timeout in seconds (2 hours)
	 *
	 * @var int
	 */
	private const DEFAULT_INACTIVITY_TIMEOUT = 2 * HOUR_IN_SECONDS;

	/**
	 * Create a new session for a user
	 *
	 * @param int $user_id The user ID.
	 * @param array $client_info Client information from initialize request.
	 *
	 * @return string|false The session ID on success, false on failure.
	 */
	public static function create_session( int $user_id, array $client_info = array() ) {
		if ( ! $user_id || ! get_user_by( 'id', $user_id ) ) {
			return false;
		}

		// Cleanup expired sessions first
		self::cleanup_expired_sessions( $user_id );

		// Get current sessions
		$sessions = self::get_all_user_sessions( $user_id );

		// Check session limit
		$max_sessions = apply_filters( 'mcp_session_max_per_user', self::DEFAULT_MAX_SESSIONS );
		if ( count( $sessions ) >= $max_sessions ) {
			// Remove oldest session (FIFO)
			$oldest_session_id = null;
			$oldest_time       = PHP_INT_MAX;

			foreach ( $sessions as $session_id => $session_data ) {
				if ( $session_data['created_at'] < $oldest_time ) {
					$oldest_time       = $session_data['created_at'];
					$oldest_session_id = $session_id;
				}
			}

			if ( $oldest_session_id ) {
				unset( $sessions[ $oldest_session_id ] );
			}
		}

		// Create new session
		$session_id = wp_generate_uuid4();
		$now        = time();
		$expiration = apply_filters( 'mcp_session_expiration', self::DEFAULT_SESSION_EXPIRATION );

		$sessions[ $session_id ] = array(
			'created_at'    => $now,
			'last_activity' => $now,
			'expires_at'    => $now + $expiration,
			'client_info'   => $client_info,
		);

		// Save sessions
		update_user_meta( $user_id, self::SESSION_META_KEY, $sessions );

		return $session_id;
	}

	/**
	 * Get a specific session for a user
	 *
	 * @param int $user_id The user ID.
	 * @param string $session_id The session ID.
	 *
	 * @return array|false Session data on success, false if not found or expired.
	 */
	public static function get_session( int $user_id, string $session_id ) {
		if ( ! $user_id || ! $session_id ) {
			return false;
		}

		$sessions = self::get_all_user_sessions( $user_id );

		if ( ! isset( $sessions[ $session_id ] ) ) {
			return false;
		}

		$session = $sessions[ $session_id ];

		// Check if session is expired
		if ( $session['expires_at'] < time() ) {
			self::delete_session( $user_id, $session_id );

			return false;
		}

		// Check inactivity timeout
		$inactivity_timeout = apply_filters( 'mcp_session_inactivity_timeout', self::DEFAULT_INACTIVITY_TIMEOUT );
		if ( ( $session['last_activity'] + $inactivity_timeout ) < time() ) {
			self::delete_session( $user_id, $session_id );

			return false;
		}

		return $session;
	}

	/**
	 * Validate a session and update last activity
	 *
	 * @param int $user_id The user ID.
	 * @param string $session_id The session ID.
	 *
	 * @return bool True if valid, false otherwise.
	 */
	public static function validate_session( int $user_id, string $session_id ): bool {
		if ( ! $user_id || ! $session_id ) {
			return false;
		}

		// Opportunistic cleanup
		self::cleanup_expired_sessions( $user_id );

		$sessions = self::get_all_user_sessions( $user_id );

		if ( ! isset( $sessions[ $session_id ] ) ) {
			return false;
		}

		$session = $sessions[ $session_id ];

		// Check if session is expired
		if ( $session['expires_at'] < time() ) {
			self::delete_session( $user_id, $session_id );

			return false;
		}

		// Check inactivity timeout
		$inactivity_timeout = apply_filters( 'mcp_session_inactivity_timeout', self::DEFAULT_INACTIVITY_TIMEOUT );
		if ( ( $session['last_activity'] + $inactivity_timeout ) < time() ) {
			self::delete_session( $user_id, $session_id );

			return false;
		}

		// Update last activity
		$sessions[ $session_id ]['last_activity'] = time();
		update_user_meta( $user_id, self::SESSION_META_KEY, $sessions );

		return true;
	}

	/**
	 * Delete a specific session
	 *
	 * @param int $user_id The user ID.
	 * @param string $session_id The session ID.
	 *
	 * @return bool True on success, false on failure.
	 */
	public static function delete_session( int $user_id, string $session_id ): bool {
		if ( ! $user_id || ! $session_id ) {
			return false;
		}

		$sessions = self::get_all_user_sessions( $user_id );

		if ( ! isset( $sessions[ $session_id ] ) ) {
			return false;
		}

		unset( $sessions[ $session_id ] );

		if ( empty( $sessions ) ) {
			delete_user_meta( $user_id, self::SESSION_META_KEY );
		} else {
			update_user_meta( $user_id, self::SESSION_META_KEY, $sessions );
		}

		return true;
	}

	/**
	 * Cleanup expired sessions for a user
	 *
	 * @param int $user_id The user ID.
	 *
	 * @return int Number of sessions removed.
	 */
	public static function cleanup_expired_sessions( int $user_id ): int {
		if ( ! $user_id ) {
			return 0;
		}

		$sessions           = self::get_all_user_sessions( $user_id );
		$now                = time();
		$removed            = 0;
		$inactivity_timeout = apply_filters( 'mcp_session_inactivity_timeout', self::DEFAULT_INACTIVITY_TIMEOUT );

		foreach ( $sessions as $session_id => $session ) {
			// Check if expired or inactive
			if ( $session['expires_at'] < $now ||
			     ( $session['last_activity'] + $inactivity_timeout ) < $now ) {
				unset( $sessions[ $session_id ] );
				++ $removed;
			}
		}

		if ( $removed > 0 ) {
			if ( empty( $sessions ) ) {
				delete_user_meta( $user_id, self::SESSION_META_KEY );
			} else {
				update_user_meta( $user_id, self::SESSION_META_KEY, $sessions );
			}
		}

		return $removed;
	}

	/**
	 * Get all sessions for a user
	 *
	 * @param int $user_id The user ID.
	 *
	 * @return array Array of sessions.
	 */
	public static function get_all_user_sessions( int $user_id ): array {
		if ( ! $user_id ) {
			return array();
		}

		$sessions = get_user_meta( $user_id, self::SESSION_META_KEY, true );

		if ( ! is_array( $sessions ) ) {
			return array();
		}

		return $sessions;
	}

	/**
	 * Find session by session ID across all users
	 *
	 * This is used when we only have a session ID but not the user ID.
	 * Note: This is less efficient than direct user-based lookup.
	 *
	 * @param string $session_id The session ID to find.
	 *
	 * @return array|false Array with 'user_id' and 'session' keys, or false if not found.
	 */
	public static function find_session( string $session_id ) {
		global $wpdb;

		// Query for users who have MCP sessions
		$users_with_sessions = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s",
				self::SESSION_META_KEY
			)
		);

		foreach ( $users_with_sessions as $user_id ) {
			$sessions = self::get_all_user_sessions( (int) $user_id );
			if ( isset( $sessions[ $session_id ] ) ) {
				return array(
					'user_id' => (int) $user_id,
					'session' => $sessions[ $session_id ],
				);
			}
		}

		return false;
	}
}
