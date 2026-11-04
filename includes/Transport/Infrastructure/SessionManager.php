<?php
/**
 * MCP Session Manager using User Meta
 *
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Transport\Infrastructure;

use WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface;
use WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler;

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
	 * Maximum serialized initialization parameters stored in one session.
	 *
	 * Adapter storage policy: MCP does not bound initialization parameter
	 * sizes, and the whole session map is one user meta row rewritten on every
	 * activity update. Without a
	 * ceiling, one user could grow that row past the database packet limit and
	 * lock themselves out while every failed write is logged in full. A real
	 * initialize serializes to a few hundred bytes; 4 KiB keeps the default
	 * 32-session row under 140 KB and its write under 540 KB after escaping,
	 * inside the 1 MB packet limit of the oldest supported database servers.
	 *
	 * @since 0.4.2
	 *
	 * @var int
	 */
	private const MAX_CLIENT_PARAMS_BYTES = 4096;

	/**
	 * Upper bound on the serialized bytes one session entry adds around its client params.
	 *
	 * Covers the UUID key, the entry array header and both timestamps. A real
	 * entry measures about 140 bytes; the slack also absorbs the outer map header.
	 *
	 * @since 0.4.2
	 *
	 * @var int
	 */
	private const SESSION_ENVELOPE_BYTES = 256;

	/**
	 * Check initialization parameters against the per-session storage limit.
	 *
	 * The limit applies to the PHP serialized form, which is what user meta
	 * stores. Accepted parameters are stored unchanged.
	 *
	 * @since 0.4.2
	 *
	 * @param array<string, mixed> $params Client parameters from the initialize request.
	 *
	 * @return true|\WP_Error True when the parameters fit, `mcp_session_params_too_large` with `bytes` and `limit` data otherwise.
	 */
	public static function validate_client_params( array $params ) {
		$params_bytes = strlen( maybe_serialize( $params ) );
		if ( $params_bytes <= self::MAX_CLIENT_PARAMS_BYTES ) {
			return true;
		}

		return new \WP_Error(
			'mcp_session_params_too_large',
			sprintf( 'Initialization parameters exceed the %d byte session storage limit.', self::MAX_CLIENT_PARAMS_BYTES ),
			array(
				'bytes' => $params_bytes,
				'limit' => self::MAX_CLIENT_PARAMS_BYTES,
			)
		);
	}

	/**
	 * Create a new session for a user
	 *
	 * @param int $user_id The user ID.
	 * @param array $params Client parameters from initialize request.
	 * @param \WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface|null $error_handler Error handler for rejected parameters.
	 *
	 * @return string|false The session ID on success, false on failure.
	 */
	public static function create_session( int $user_id, array $params = array(), ?McpErrorHandlerInterface $error_handler = null ) {
		if ( ! $user_id || ! get_user_by( 'id', $user_id ) ) {
			return false;
		}

		$validation = self::validate_client_params( $params );
		if ( is_wp_error( $validation ) ) {
			$error_handler = $error_handler ?? new ErrorLogMcpErrorHandler();
			$error_handler->log(
				'MCP session initialization parameters exceed the storage limit.',
				array_merge(
					array(
						'component' => self::class,
						'method'    => 'create_session',
						'user_id'   => $user_id,
					),
					(array) $validation->get_error_data()
				)
			);

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
	 * @param \WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface|null $error_handler Error handler for oversized stored maps.
	 *
	 * @return array Array of sessions.
	 */
	public static function get_all_user_sessions( int $user_id, ?McpErrorHandlerInterface $error_handler = null ): array {
		if ( ! $user_id ) {
			return array();
		}

		$sessions = get_user_meta( $user_id, self::SESSION_META_KEY, true );

		if ( ! is_array( $sessions ) ) {
			return array();
		}

		$stored_bytes = strlen( maybe_serialize( $sessions ) );
		$budget       = self::session_map_budget_bytes( count( $sessions ) );
		if ( $stored_bytes <= $budget ) {
			return $sessions;
		}

		if ( ! self::delete_oversized_session_map( $user_id, $budget ) ) {
			// A concurrent request already replaced the row; serve the current map.
			$current = get_user_meta( $user_id, self::SESSION_META_KEY, true );

			return is_array( $current ) && strlen( maybe_serialize( $current ) ) <= self::session_map_budget_bytes( count( $current ) )
				? $current
				: array();
		}

		$error_handler = $error_handler ?? new ErrorLogMcpErrorHandler();
		$error_handler->log(
			'Discarded an MCP session map that exceeds the storage budget.',
			array(
				'component' => self::class,
				'method'    => 'get_all_user_sessions',
				'user_id'   => $user_id,
				'sessions'  => count( $sessions ),
				'bytes'     => $stored_bytes,
				'budget'    => $budget,
			)
		);

		return array();
	}

	/**
	 * Largest serialized map a given number of accepted sessions can occupy.
	 *
	 * @since 0.4.2
	 *
	 * @param int $session_count Sessions in the stored map.
	 */
	private static function session_map_budget_bytes( int $session_count ): int {
		return max( 1, $session_count ) * ( self::MAX_CLIENT_PARAMS_BYTES + self::SESSION_ENVELOPE_BYTES );
	}

	/**
	 * Delete the user's session row only while it still exceeds the budget.
	 *
	 * The meta API offers no conditional delete that avoids sending the
	 * oversized value back in the predicate, so this is one small direct query.
	 * The length predicate makes the delete atomic: a concurrent request that
	 * already replaced the row with a small map leaves nothing to delete. The
	 * caches delete_metadata() maintains are cleared here the same way: the
	 * user's meta cache, and the users last_changed salt once a row is gone.
	 * The delete_user_meta and deleted_user_meta actions do not fire.
	 *
	 * @since 0.4.2
	 *
	 * @param int $user_id The user ID.
	 * @param int $budget  Serialized byte budget the stored row must exceed.
	 *
	 * @return bool True when a row was deleted.
	 */
	private static function delete_oversized_session_map( int $user_id, int $budget ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The meta API cannot delete conditionally without repeating the oversized value; the cache is cleared below.
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s AND LENGTH( meta_value ) > %d",
				$user_id,
				self::SESSION_META_KEY,
				$budget
			)
		);
		wp_cache_delete( $user_id, 'user_meta' );

		if ( ! is_int( $deleted ) || $deleted < 1 ) {
			return false;
		}

		wp_cache_set_users_last_changed();

		return true;
	}
}
