<?php
/**
 * Storage for approved OAuth connections.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\OAuth;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Stores each connection as one user meta row, keyed per site.
 *
 * The key is `{blog prefix}mcp_adapter_oauth_grant_{id}`, so a connection approved
 * on one site of a network is unknown on every other site. The value is JSON with these
 * fields:
 *
 * - `client_id`, `client_name`, `redirect_uri`: the app and the return address it used.
 * - `challenge`: the PKCE S256 code challenge.
 * - `gen`: 0 while the authorization code is unredeemed, then the generation of the one
 *   valid refresh token. Every refresh increments it.
 * - `code`, `access`, `access_prev`, `refresh`: digests of the current secrets
 *   ({@see Tokens}). The previous access digest stays valid, so requests still in flight
 *   with the old access token keep working after a refresh.
 * - `created`, `used`: when the app was approved and last refreshed.
 * - `pw`: binding to the user's password hash.
 *
 * Rows are read and written with direct SQL instead of the user meta API. The meta API
 * unslashes values, which corrupts JSON containing a backslash, and reads through the
 * object cache, which could hand a stale generation to a refresh. Every rotation is a
 * compare-and-swap on the exact stored string, so two refreshes racing on one connection
 * cannot both win. Each write clears the user's meta cache.
 *
 * @since n.e.x.t
 */
final class Grants {

	/**
	 * How long an authorization code stays redeemable, in seconds.
	 *
	 * @var int
	 */
	public const CODE_TTL = 600;

	/**
	 * Meta key stem, after the blog prefix.
	 *
	 * @var string
	 */
	private const KEY = 'mcp_adapter_oauth_grant_';

	/**
	 * Stores a new connection after pruning the user's dead ones on this site.
	 *
	 * @param \WP_User             $user   The approving user.
	 * @param array<string, mixed> $fields The connection fields.
	 * @return string|null The connection ID, or null when the row could not be written.
	 */
	public static function create( \WP_User $user, array $fields ): ?string {
		global $wpdb;

		self::list_for_user( $user );

		$id       = bin2hex( random_bytes( 16 ) );
		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Raw JSON must not pass through the slashing meta API.
			$wpdb->usermeta,
			array(
				'user_id'    => $user->ID,
				'meta_key'   => self::key( $id ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Insert, not a query.
				'meta_value' => (string) wp_json_encode( $fields ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Insert, not a query.
			),
			array( '%d', '%s', '%s' )
		);
		wp_cache_delete( $user->ID, 'user_meta' );

		return false === $inserted ? null : $id;
	}

	/**
	 * Reads one connection.
	 *
	 * @param int    $user_id The user ID.
	 * @param string $id      The connection ID.
	 * @return array{row:int,raw:string,data:array<string,mixed>}|null The row ID, the exact
	 *         stored string and the decoded fields; null when there is no such connection.
	 */
	public static function find( int $user_id, string $id ): ?array {
		global $wpdb;

		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', $id ) ) {
			return null;
		}

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A stale cached generation would break rotation.
			$wpdb->prepare(
				"SELECT umeta_id, meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s LIMIT 1",
				$user_id,
				self::key( $id )
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return null;
		}

		$data = json_decode( (string) $row['meta_value'], true );
		if ( ! is_array( $data ) ) {
			return null;
		}

		return array(
			'row'  => (int) $row['umeta_id'],
			'raw'  => (string) $row['meta_value'],
			'data' => $data,
		);
	}

	/**
	 * Replaces a connection's fields only if nobody changed it since it was read.
	 *
	 * @param int                                                 $user_id The user ID.
	 * @param array{row:int,raw:string,data:array<string,mixed>} $grant   The row as read by {@see find()}.
	 * @param array<string, mixed>                                $data    The new fields.
	 * @return bool True when this call made the change.
	 */
	public static function replace( int $user_id, array $grant, array $data ): bool {
		global $wpdb;

		$updated = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare-and-swap on the exact stored value.
			$wpdb->prepare(
				"UPDATE {$wpdb->usermeta} SET meta_value = %s WHERE umeta_id = %d AND BINARY meta_value = %s",
				(string) wp_json_encode( $data ),
				$grant['row'],
				$grant['raw']
			)
		);
		wp_cache_delete( $user_id, 'user_meta' );

		return 1 === $updated;
	}

	/**
	 * Deletes one connection on this site.
	 *
	 * @param int    $user_id The user ID.
	 * @param string $id      The connection ID.
	 * @return void
	 */
	public static function delete( int $user_id, string $id ): void {
		global $wpdb;

		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Same storage path as every other write.
			$wpdb->usermeta,
			array(
				'user_id'  => $user_id,
				'meta_key' => self::key( $id ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Exact key on an indexed column.
			),
			array( '%d', '%s' )
		);
		wp_cache_delete( $user_id, 'user_meta' );
	}

	/**
	 * The user's working connections on this site, deleting the dead ones.
	 *
	 * A connection is dead when its code expired unredeemed, when it went unused past the
	 * inactivity window, or when the user's password changed.
	 *
	 * @param \WP_User $user The user.
	 * @return array<string, array<string, mixed>> Fields of each redeemed connection, by ID.
	 */
	public static function list_for_user( \WP_User $user ): array {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Same storage path as every other read.
			$wpdb->prepare(
				"SELECT meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key LIKE %s",
				$user->ID,
				$wpdb->esc_like( self::key( '' ) ) . '%'
			),
			ARRAY_A
		);

		$live = array();
		foreach ( (array) $rows as $row ) {
			$id   = substr( (string) $row['meta_key'], strlen( self::key( '' ) ) );
			$data = json_decode( (string) $row['meta_value'], true );
			if ( ! is_array( $data ) || ! self::is_live( $data, $user ) ) {
				self::delete( $user->ID, $id );
				continue;
			}

			if ( 0 === (int) ( $data['gen'] ?? 0 ) ) {
				continue;
			}

			$live[ $id ] = $data;
		}

		return $live;
	}

	/**
	 * Whether a connection can still be used.
	 *
	 * @param array<string, mixed> $data The connection fields.
	 * @param \WP_User             $user The user it belongs to.
	 * @return bool True when it is neither expired nor bound to an old password.
	 */
	public static function is_live( array $data, \WP_User $user ): bool {
		if ( ! hash_equals( Tokens::password_binding( $user ), (string) ( $data['pw'] ?? '' ) ) ) {
			return false;
		}

		if ( 0 === (int) ( $data['gen'] ?? 0 ) ) {
			return (int) ( $data['created'] ?? 0 ) + self::CODE_TTL > time();
		}

		return (int) ( $data['used'] ?? 0 ) + Module::inactivity_ttl() > time();
	}

	/**
	 * Keeps a user's connections when WordPress rehashes an unchanged password. Hooked to
	 * `wp_set_password`.
	 *
	 * Core rehashes the stored password at login when the hashing cost or algorithm
	 * changes, for example after a PHP upgrade. The plaintext is the same, so connections
	 * bound to the old hash move to the new one, on every site. A real password change
	 * leaves them bound to the old hash, which ends them.
	 *
	 * @param string        $password      The plaintext password just set.
	 * @param int           $user_id       The user ID.
	 * @param \WP_User|null $old_user_data The user before the change.
	 * @return void
	 */
	public static function rebind_password( $password, $user_id, $old_user_data = null ): void {
		global $wpdb;

		$user = get_userdata( (int) $user_id );
		if ( ! $old_user_data instanceof \WP_User || ! $user instanceof \WP_User || ! is_string( $password ) || ! wp_check_password( $password, $old_user_data->user_pass, $user->ID ) ) {
			return;
		}

		$old  = Tokens::password_binding( $old_user_data );
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Every site's rows for one user.
			$wpdb->prepare(
				"SELECT umeta_id, meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key LIKE %s",
				$user->ID,
				'%' . $wpdb->esc_like( self::KEY ) . '%'
			),
			ARRAY_A
		);
		foreach ( (array) $rows as $row ) {
			$data = json_decode( (string) $row['meta_value'], true );
			if ( ! is_array( $data ) || ! hash_equals( $old, (string) ( $data['pw'] ?? '' ) ) ) {
				continue;
			}

			$grant      = array(
				'row'  => (int) $row['umeta_id'],
				'raw'  => (string) $row['meta_value'],
				'data' => $data,
			);
			$data['pw'] = Tokens::password_binding( $user );
			self::replace( $user->ID, $grant, $data );
		}
	}

	/**
	 * Deletes every connection a user has on one site. Hooked to `remove_user_from_blog`.
	 *
	 * The Remove link on the network admin's Edit Site screen fires the hook with site ID
	 * 0 after switching to the site, so 0 means the current site.
	 *
	 * @param int $user_id The user ID.
	 * @param int $blog_id The site ID, or 0 for the current site.
	 * @return void
	 */
	public static function delete_for_user_on_site( $user_id, $blog_id ): void {
		global $wpdb;

		$blog_id = 0 === (int) $blog_id ? get_current_blog_id() : (int) $blog_id;
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk delete by key prefix.
			$wpdb->prepare(
				"DELETE FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key LIKE %s",
				(int) $user_id,
				$wpdb->esc_like( $wpdb->get_blog_prefix( $blog_id ) . self::KEY ) . '%'
			)
		);
		wp_cache_delete( (int) $user_id, 'user_meta' );
	}

	/**
	 * The meta key for a connection on the current site.
	 *
	 * @param string $id The connection ID.
	 * @return string The meta key.
	 */
	private static function key( string $id ): string {
		global $wpdb;

		return $wpdb->get_blog_prefix() . self::KEY . $id;
	}
}
