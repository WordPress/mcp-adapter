<?php
/**
 * MCP Session Manager using User Meta
 *
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
final class SessionManager {

	/**
	 * User meta key for storing sessions
	 *
	 * @var string
	 */
	private const SESSION_META_KEY = 'mcp_adapter_sessions';

	/**
	 * Maximum sessions per user.
	 *
	 * @var int
	 */
	private const DEFAULT_MAX_SESSIONS = 32;

	/**
	 * Session inactivity timeout in seconds (24 hours).
	 *
	 * @var int
	 */
	private const DEFAULT_INACTIVITY_TIMEOUT = DAY_IN_SECONDS;

	/**
	 * Get configuration values.
	 *
	 * @return array<string, int> Configuration array.
	 */
	private static function get_config(): array {
		return array(
			'max_sessions'       => (int) apply_filters( 'mcp_adapter_session_max_per_user', self::DEFAULT_MAX_SESSIONS ),
			'inactivity_timeout' => (int) apply_filters( 'mcp_adapter_session_inactivity_timeout', self::DEFAULT_INACTIVITY_TIMEOUT ),
		);
	}

	/**
	 * Clear an inactive session (internal cleanup).
	 *
	 * @param int $user_id The user ID.
	 * @param string $session_id The session ID to clear.
	 *
	 * @return void
	 */
	private static function clear_session( int $user_id, string $session_id ): void {
		$sessions = self::get_all_user_sessions( $user_id );

		if ( ! isset( $sessions[ $session_id ] ) ) {
			return;
		}

		unset( $sessions[ $session_id ] );
		update_user_meta( $user_id, self::SESSION_META_KEY, $sessions );
	}

	/**
	 * Create a new session for a user
	 *
	 * @param int $user_id The user ID.
	 * @param array $params Client parameters from initialize request.
	 *
	 * @return string|false The session ID on success, false on failure.
	 */
	public static function create_session( int $user_id, array $params = array() ) {
		if ( ! $user_id || ! get_user_by( 'id', $user_id ) ) {
			return false;
		}

		// Cleanup inactive sessions first
		self::cleanup_expired_sessions( $user_id );

		// Get current sessions
		$sessions = self::get_all_user_sessions( $user_id );

		// Check session limit - remove oldest if over limit
		$config       = self::get_config();
		$max_sessions = $config['max_sessions'];
		if ( count( $sessions ) >= $max_sessions ) {
			// Remove oldest session (FIFO) - sort by created_at and remove first
			uasort(
				$sessions,
				static function ( $a, $b ) {
					return $a['created_at'] <=> $b['created_at'];
				}
			);

			array_shift( $sessions );
		}

		// Create a new session
		$session_id = wp_generate_uuid4();
		$now        = time();

		$sessions[ $session_id ] = array(
			'created_at'    => $now,
			'last_activity' => $now,
			'client_params' => $params,
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
	 * @return array|\WP_Error|false Session data on success, WP_Error on invalid input, false if not found or inactive.
	 */
	public static function get_session( int $user_id, string $session_id ) {
		if ( ! $user_id || ! $session_id ) {
			return new \WP_Error( 403, 'Invalid user ID or session ID.' );
		}

		$sessions = self::get_all_user_sessions( $user_id );

		if ( ! isset( $sessions[ $session_id ] ) ) {
			return false;
		}

		$session = $sessions[ $session_id ];

		// Check inactivity timeout
		$config             = self::get_config();
		$inactivity_timeout = $config['inactivity_timeout'];
		if ( $session['last_activity'] + $inactivity_timeout < time() ) {
			self::clear_session( $user_id, $session_id );

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

		// Check inactivity timeout
		$config             = self::get_config();
		$inactivity_timeout = $config['inactivity_timeout'];
		if ( $session['last_activity'] + $inactivity_timeout < time() ) {
			self::clear_session( $user_id, $session_id );

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
	 * Cleanup inactive sessions for a user
	 *
	 * @param int $user_id The user ID.
	 *
	 * @return int Number of sessions removed.
	 */
	public static function cleanup_expired_sessions( int $user_id ): int {
		if ( ! $user_id ) {
			return 0;
		}

		$sessions = self::get_all_user_sessions( $user_id );
		$now      = time();
		$removed  = 0;

		$config             = self::get_config();
		$inactivity_timeout = $config['inactivity_timeout'];

		foreach ( $sessions as $session_id => $session ) {
			// Check if still active - skip if valid
			if ( $session['last_activity'] + $inactivity_timeout >= $now ) {
				continue;
			}

			// Session is inactive - remove it
			unset( $sessions[ $session_id ] );
			++$removed;
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
}
