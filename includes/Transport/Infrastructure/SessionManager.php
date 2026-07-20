<?php
/**
 * MCP Session Manager using User Meta
 *
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Transport\Infrastructure;

use WP_Error;

/**
 * MCP Session Manager
 *
 * Handles session creation, validation, and cleanup using user meta storage.
 * Sessions are tied to authenticated users to prevent anonymous session flooding.
 */
final class SessionManager {

	/**
	 * User meta key for storing sessions.
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
	 * Minimum interval between last_activity writes in seconds.
	 *
	 * @var int
	 */
	private const DEFAULT_ACTIVITY_UPDATE_INTERVAL = 60;

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

			$oldest_session_id = key( $sessions );
			if ( is_string( $oldest_session_id ) ) {
				self::delete_session( $user_id, $oldest_session_id );
			}
		}

		// Create a new session
		$session_id = wp_generate_uuid4();
		$now        = time();

		$session = array(
			'created_at'    => $now,
			'last_activity' => $now,
			'client_params' => $params,
		);

		// Each session has its own row, so concurrent requests cannot overwrite siblings.
		if ( false === add_user_meta( $user_id, self::SESSION_META_KEY, self::prepare_stored_session( $session_id, $session ) ) ) {
			return false;
		}

		return $session_id;
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

			// Session is inactive - remove its independent meta row.
			if ( ! self::delete_stored_session( $user_id, $session_id, $session ) ) {
				continue;
			}

			++$removed;
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

		$stored_sessions = get_user_meta( $user_id, self::SESSION_META_KEY, false );
		self::migrate_legacy_sessions( $user_id, $stored_sessions );

		return self::get_sessions_from_stored_values( get_user_meta( $user_id, self::SESSION_META_KEY, false ) );
	}

	/**
	 * Migrate the released collection-based storage to independent session rows.
	 *
	 * The exact legacy map value is deleted after its independent records are
	 * added, so a concurrent new session row is never removed.
	 *
	 * @param int $user_id The user ID.
	 * @param array $stored_sessions Stored meta values.
	 *
	 * @return void
	 */
	private static function migrate_legacy_sessions( int $user_id, array $stored_sessions ): void {
		foreach ( $stored_sessions as $legacy_sessions ) {
			if ( ! self::is_legacy_session_map( $legacy_sessions ) ) {
				continue;
			}

			foreach ( $legacy_sessions as $session_id => $session ) {
				add_user_meta( $user_id, self::SESSION_META_KEY, self::prepare_stored_session( $session_id, $session ) );
			}

			delete_user_meta( $user_id, self::SESSION_META_KEY, $legacy_sessions );
		}
	}

	/**
	 * Determine whether a stored value is a released collection-based session map.
	 *
	 * @param mixed $stored_session Stored meta value.
	 *
	 * @return bool
	 */
	private static function is_legacy_session_map( $stored_session ): bool {
		if ( ! is_array( $stored_session ) || isset( $stored_session['session_id'] ) ) {
			return false;
		}

		foreach ( $stored_session as $session_id => $session ) {
			if ( ! is_string( $session_id ) || '' === $session_id || ! is_array( $session ) ) {
				return false;
			}
		}

		return ! empty( $stored_session );
	}

	/**
	 * Convert stored session rows to the public session collection shape.
	 *
	 * @param array $stored_sessions Stored meta values.
	 *
	 * @return array
	 */
	private static function get_sessions_from_stored_values( array $stored_sessions ): array {
		$sessions = array();

		foreach ( $stored_sessions as $stored_session ) {
			if ( ! is_array( $stored_session ) || ! isset( $stored_session['session_id'] ) || ! is_string( $stored_session['session_id'] ) ) {
				continue;
			}

			$session_id = $stored_session['session_id'];
			unset( $stored_session['session_id'] );
			$sessions[ $session_id ] = $stored_session;
		}

		return $sessions;
	}

	/**
	 * Add the internal session ID required to target a single meta row.
	 *
	 * @param string $session_id Session ID.
	 * @param array  $session Session data.
	 *
	 * @return array
	 */
	private static function prepare_stored_session( string $session_id, array $session ): array {
		$session['session_id'] = $session_id;

		return $session;
	}

	/**
	 * Delete a session by matching its complete stored meta value.
	 *
	 * @param int    $user_id User ID.
	 * @param string $session_id Session ID.
	 * @param array  $session Session data.
	 *
	 * @return bool
	 */
	private static function delete_stored_session( int $user_id, string $session_id, array $session ): bool {
		return delete_user_meta( $user_id, self::SESSION_META_KEY, self::prepare_stored_session( $session_id, $session ) );
	}

	/**
	 * Get configuration values.
	 *
	 * @return array{max_sessions: int, inactivity_timeout: int, activity_update_interval: int} Configuration array.
	 */
	private static function get_config(): array {
		/**
		 * Filters the maximum number of MCP sessions allowed per user.
		 *
		 * When a user exceeds this limit, the oldest inactive session is
		 * automatically removed to make room for new sessions.
		 *
		 * @since 0.3.0
		 *
		 * @param int $max_sessions Maximum sessions per user. Default 32.
		 */
		$max_sessions = (int) apply_filters( 'mcp_adapter_session_max_per_user', self::DEFAULT_MAX_SESSIONS );

		/**
		 * Filters the session inactivity timeout in seconds.
		 *
		 * Sessions that have been inactive longer than this duration are
		 * considered expired and may be cleaned up automatically.
		 *
		 * @since 0.3.0
		 *
		 * @param int $timeout Inactivity timeout in seconds. Default DAY_IN_SECONDS (86400 / 24 hours).
		 */
		$inactivity_timeout = (int) apply_filters( 'mcp_adapter_session_inactivity_timeout', self::DEFAULT_INACTIVITY_TIMEOUT );

		/**
		 * Filters the minimum interval between session last_activity writes.
		 *
		 * To reduce write amplification, the session manager only updates
		 * `last_activity` if at least this many seconds have elapsed since
		 * the last write.
		 *
		 * @since 0.5.0
		 *
		 * @param int $interval Minimum seconds between writes. Default 60.
		 */
		$activity_update_interval = (int) apply_filters( 'mcp_adapter_session_activity_update_interval', self::DEFAULT_ACTIVITY_UPDATE_INTERVAL );

		// Clamp: interval must be less than inactivity timeout to prevent
		// sessions from expiring despite active use.
		if ( $activity_update_interval >= $inactivity_timeout ) {
			$activity_update_interval = (int) ( $inactivity_timeout / 2 );
		}

		return array(
			'max_sessions'             => $max_sessions,
			'inactivity_timeout'       => $inactivity_timeout,
			'activity_update_interval' => max( 0, $activity_update_interval ),
		);
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
			return new WP_Error( 'mcp_session_invalid_input', 'Invalid user ID or session ID.' );
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

		self::delete_stored_session( $user_id, $session_id, $sessions[ $session_id ] );
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

		// Throttle last_activity writes to reduce write amplification
		$activity_update_interval = $config['activity_update_interval'];
		if ( time() - $session['last_activity'] >= $activity_update_interval ) {
			$session['last_activity'] = time();
			update_user_meta( $user_id, self::SESSION_META_KEY, self::prepare_stored_session( $session_id, $session ), self::prepare_stored_session( $session_id, $sessions[ $session_id ] ) );
		}

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

		self::delete_stored_session( $user_id, $session_id, $sessions[ $session_id ] );

		return true;
	}
}
