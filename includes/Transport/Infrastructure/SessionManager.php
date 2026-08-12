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
use WP_Error;

/**
 * MCP Session Manager
 *
 * Handles session creation, validation, and cleanup using user meta storage.
 * Sessions are tied to authenticated users to prevent anonymous session flooding.
 * MCP protocol revisions that do not negotiate session IDs should bypass this
 * compatibility storage entirely.
 * Established session maps use compare-and-swap semantics. An older
 * unconditional writer can still overwrite a concurrent newer writer.
 */
final class SessionManager {

	/**
	 * Base user meta key for storing sessions.
	 *
	 * On multisite the stored key is blog-scoped via {@see session_meta_key()}
	 * because user meta is network-global. Without a blog suffix, two site-local
	 * MCP connectors for the same user share one session map. Single-site keeps
	 * the unsuffixed legacy key for upgrade compatibility.
	 *
	 * @var string
	 */
	private const SESSION_META_KEY = 'mcp_adapter_sessions';

	/**
	 * User meta key for the current site's session map.
	 *
	 * Single-site keeps the unsuffixed legacy key so in-flight sessions survive
	 * upgrade. On multisite, user meta is network-global, so the key is suffixed
	 * with the current blog ID. When no positive blog ID is available yet, fall
	 * back to the unsuffixed legacy key so reads/writes do not land under an
	 * orphaned `mcp_adapter_sessions_0` row (see session_meta_key_for_blog()).
	 *
	 * @since 0.6.0
	 */
	private static function session_meta_key(): string {
		if ( ! is_multisite() ) {
			return self::SESSION_META_KEY;
		}

		return self::session_meta_key_for_blog( (int) get_current_blog_id() );
	}

	/**
	 * Resolve the session meta key for a blog ID (multisite).
	 *
	 * @since 0.6.0
	 *
	 * @param int $blog_id Blog ID; values below 1 use the unsuffixed legacy key.
	 */
	private static function session_meta_key_for_blog( int $blog_id ): string {
		if ( $blog_id < 1 ) {
			return self::SESSION_META_KEY;
		}

		return self::SESSION_META_KEY . '_' . $blog_id;
	}

	/**
	 * Maximum sessions per user on the current site.
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
	 * Maximum attempts for a concurrent session mutation.
	 *
	 * @since 0.6.0
	 *
	 * @var int
	 */
	private const MAX_UPDATE_ATTEMPTS = 5;

	/**
	 * Create a new session for a user
	 *
	 * @since 0.6.0 Added the optional error handler.
	 *
	 * @param int                                                   $user_id      The user ID.
	 * @param array                                                 $params       Client parameters from initialize request.
	 * @param \WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface|null $error_handler Error handler for reporting storage failures. Defaults to the standard error-log handler.
	 *
	 * @return string|false The session ID on success, false on failure.
	 */
	public static function create_session( int $user_id, array $params = array(), ?McpErrorHandlerInterface $error_handler = null ) {
		if ( ! $user_id || ! get_user_by( 'id', $user_id ) ) {
			return false;
		}

		$session_id = wp_generate_uuid4();
		$now        = time();
		$config     = self::get_config();

		$created = self::mutate_sessions(
			$user_id,
			static function ( array $sessions ) use ( $config, $now, $params, $session_id ): array {
				foreach ( $sessions as $stored_session_id => $session ) {
					if ( $session['last_activity'] + $config['inactivity_timeout'] >= $now ) {
						continue;
					}

					unset( $sessions[ $stored_session_id ] );
				}

				if ( count( $sessions ) >= $config['max_sessions'] ) {
					uasort(
						$sessions,
						static function ( $a, $b ) {
							return $a['created_at'] <=> $b['created_at'];
						}
					);

					array_shift( $sessions );
				}

				$sessions[ $session_id ] = array(
					'created_at'    => $now,
					'last_activity' => $now,
					'client_params' => $params,
				);

				return $sessions;
			},
			$error_handler
		);

		if ( ! $created ) {
			return false;
		}

		return $session_id;
	}

	/**
	 * Apply a session mutation without overwriting an established session map.
	 *
	 * WordPress ignores an empty $prev_value. Concurrent first connections may
	 * therefore still overwrite each other, but subsequent writes retry when the
	 * previously read non-empty map has changed.
	 *
	 * @since 0.6.0
	 *
	 * @param int                                                   $user_id       The user ID.
	 * @param callable                                              $mutation      Receives the latest sessions and returns the updated sessions.
	 * @param \WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface|null $error_handler Error handler for reporting storage failures. Defaults to the standard error-log handler.
	 * @return bool True when the mutation was stored, false after repeated conflicts.
	 */
	private static function mutate_sessions( int $user_id, callable $mutation, ?McpErrorHandlerInterface $error_handler = null ): bool {
		for ( $attempt = 0; $attempt < self::MAX_UPDATE_ATTEMPTS; ++$attempt ) {
			wp_cache_delete( $user_id, 'user_meta' );
			$previous_sessions = self::get_all_user_sessions( $user_id );
			$updated_sessions  = $mutation( $previous_sessions );

			if ( $updated_sessions === $previous_sessions ) {
				return true;
			}

			$updated = update_user_meta( $user_id, self::session_meta_key(), $updated_sessions, $previous_sessions );
			if ( false !== $updated ) {
				return true;
			}
		}

		$error_handler = $error_handler ?? new ErrorLogMcpErrorHandler();
		$error_handler->log(
			'Failed to persist MCP sessions after exhausting update retries.',
			array(
				'component' => self::class,
				'method'    => 'mutate_sessions',
				'user_id'   => $user_id,
				'attempts'  => self::MAX_UPDATE_ATTEMPTS,
			)
		);

		return false;
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

		$now                = time();
		$removed            = 0;
		$config             = self::get_config();
		$inactivity_timeout = $config['inactivity_timeout'];

		$stored = self::mutate_sessions(
			$user_id,
			static function ( array $sessions ) use ( $inactivity_timeout, $now, &$removed ): array {
				$removed = 0;

				foreach ( $sessions as $session_id => $session ) {
					if ( $session['last_activity'] + $inactivity_timeout >= $now ) {
						continue;
					}

					unset( $sessions[ $session_id ] );
					++$removed;
				}

				return $sessions;
			}
		);

		return $stored ? $removed : 0;
	}

	/**
	 * Get all sessions for a user on the current site.
	 *
	 * @param int $user_id The user ID.
	 *
	 * @return array Array of sessions.
	 */
	public static function get_all_user_sessions( int $user_id ): array {
		if ( ! $user_id ) {
			return array();
		}

		$sessions = get_user_meta( $user_id, self::session_meta_key(), true );

		if ( ! is_array( $sessions ) ) {
			return array();
		}

		return $sessions;
	}

	/**
	 * Get configuration values.
	 *
	 * @return array{max_sessions: int, inactivity_timeout: int, activity_update_interval: int} Configuration array.
	 */
	private static function get_config(): array {
		/**
		 * Filters the maximum number of MCP sessions allowed per user on the current site.
		 *
		 * When a user exceeds this limit on the current site, the oldest inactive
		 * session is automatically removed to make room for new sessions.
		 *
		 * @since 0.3.0
		 *
		 * @param int $max_sessions Maximum sessions per user on the current site. Default 32.
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
		self::mutate_sessions(
			$user_id,
			static function ( array $sessions ) use ( $session_id ): array {
				unset( $sessions[ $session_id ] );

				return $sessions;
			}
		);
	}

	/**
	 * Validate a session and update last activity
	 *
	 * @since 0.6.0 Added the optional error handler.
	 *
	 * @param int                                                   $user_id      The user ID.
	 * @param string                                                $session_id   The session ID.
	 * @param \WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface|null $error_handler Error handler for reporting storage failures. Defaults to the standard error-log handler.
	 *
	 * @return bool True if valid, false otherwise.
	 */
	public static function validate_session( int $user_id, string $session_id, ?McpErrorHandlerInterface $error_handler = null ): bool {
		if ( ! $user_id || ! $session_id ) {
			return false;
		}

		$config   = self::get_config();
		$now      = time();
		$is_valid = false;

		$stored = self::mutate_sessions(
			$user_id,
			static function ( array $sessions ) use ( $config, $now, $session_id, &$is_valid ): array {
				if ( ! isset( $sessions[ $session_id ] ) ) {
					$is_valid = false;
					return $sessions;
				}

				if ( $sessions[ $session_id ]['last_activity'] + $config['inactivity_timeout'] < $now ) {
					$is_valid = false;
					unset( $sessions[ $session_id ] );

					return $sessions;
				}

				$is_valid = true;
				if ( $now - $sessions[ $session_id ]['last_activity'] >= $config['activity_update_interval'] ) {
					$sessions[ $session_id ]['last_activity'] = $now;
				}

				return $sessions;
			},
			$error_handler
		);

		return $stored && $is_valid;
	}

	/**
	 * Delete a specific session
	 *
	 * @since 0.6.0 Added the optional error handler.
	 *
	 * @param int                                                   $user_id      The user ID.
	 * @param string                                                $session_id   The session ID.
	 * @param \WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface|null $error_handler Error handler for reporting storage failures. Defaults to the standard error-log handler.
	 *
	 * @return bool True on success, false on failure.
	 */
	public static function delete_session( int $user_id, string $session_id, ?McpErrorHandlerInterface $error_handler = null ): bool {
		if ( ! $user_id || ! $session_id ) {
			return false;
		}

		$session_found = false;
		$stored        = self::mutate_sessions(
			$user_id,
			static function ( array $sessions ) use ( $session_id, &$session_found ): array {
				if ( ! isset( $sessions[ $session_id ] ) ) {
					$session_found = false;
					return $sessions;
				}

				$session_found = true;
				unset( $sessions[ $session_id ] );

				return $sessions;
			},
			$error_handler
		);

		return $stored && $session_found;
	}
}
