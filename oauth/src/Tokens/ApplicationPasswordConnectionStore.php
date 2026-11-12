<?php
/**
 * Application Password-backed OAuth connection store.
 *
 * @package WP\OAuth
 */

declare( strict_types=1 );

namespace WP\OAuth\Tokens;

use WP\OAuth\Grants\AuthorizedGrant;
use WP\OAuth\Tokens\Contracts\ConnectionStoreInterface;
use WP_Application_Passwords;
use WP_Error;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Uses Application Password records as visible revocation anchors and stores
 * token-format-neutral connection state in per-connection user metadata.
 */
final class ApplicationPasswordConnectionStore implements ConnectionStoreInterface {

	public const MAX_CONNECTIONS_PER_CLIENT = 5;
	private const META_PREFIX               = 'oauth_server_connection_';

	public function register_hooks(): void {
		add_action( 'wp_delete_application_password', array( $this, 'deleted_application_password' ), 10, 2 );
	}

	/** {@inheritDoc} */
	public function create( AuthorizedGrant $grant ) {
		$user      = $grant->get_user();
		$client_id = $grant->get_client_id();
		$this->prune_connections( $user->ID, $client_id );

		$created = WP_Application_Passwords::create_new_application_password(
			$user->ID,
			array(
				'name'   => $grant->get_client_name(),
				'app_id' => $client_id,
			)
		);
		if ( is_wp_error( $created ) ) {
			return $created;
		}

		// The raw Application Password is deliberately discarded. It is only a
		// revocation anchor and is never an OAuth credential.
		$item       = $created[1];
		$connection = new Connection(
			$user->ID,
			(string) $item['uuid'],
			array(
				'client_id'       => $client_id,
				'client_name'     => $grant->get_client_name(),
				'client_metadata' => $grant->get_client_metadata(),
				'resource'        => $grant->get_resource(),
				'created_at'      => time(),
				'last_used'       => 0,
			)
		);

		if ( ! add_user_meta( $user->ID, self::meta_key( $connection->get_id() ), $connection->get_data(), true ) ) {
			WP_Application_Passwords::delete_application_password( $user->ID, $connection->get_id() );
			return new WP_Error( 'oauth_server_connection_store', __( 'The OAuth connection could not be stored.', 'mcp-adapter' ) );
		}

		return $connection;
	}

	public function get( int $user_id, string $connection_id ): ?Connection {
		$item = WP_Application_Passwords::get_user_application_password( $user_id, $connection_id );
		$data = get_user_meta( $user_id, self::meta_key( $connection_id ), true );
		if ( ! is_array( $item ) || ! is_array( $data ) || array() === $data ) {
			return null;
		}

		return new Connection( $user_id, $connection_id, $data );
	}

	public function compare_and_swap( Connection $expected, Connection $replacement ): bool {
		if ( $expected->get_user_id() !== $replacement->get_user_id() || $expected->get_id() !== $replacement->get_id() ) {
			return false;
		}

		return false !== update_user_meta(
			$expected->get_user_id(),
			self::meta_key( $expected->get_id() ),
			$replacement->get_data(),
			$expected->get_data()
		);
	}

	public function revoke( Connection $connection ): void {
		WP_Application_Passwords::delete_application_password( $connection->get_user_id(), $connection->get_id() );
		delete_user_meta( $connection->get_user_id(), self::meta_key( $connection->get_id() ) );
	}

	public function record_usage( Connection $connection ): void {
		$item = WP_Application_Passwords::get_user_application_password( $connection->get_user_id(), $connection->get_id() );
		WP_Application_Passwords::record_application_password_usage( $connection->get_user_id(), $connection->get_id() );
		$current = $this->get( $connection->get_user_id(), $connection->get_id() );
		if ( null !== $current ) {
			$this->compare_and_swap( $current, $current->with( array( 'last_used' => time() ) ) );
		}

		$user = get_userdata( $connection->get_user_id() );
		if ( ! ( $user instanceof WP_User ) || ! is_array( $item ) ) {
			return;
		}

		/** This action is documented in wp-includes/user.php. */
		do_action( 'application_password_did_authenticate', $user, $item ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook, so Application Password integrations see OAuth logins too.
	}

	public function manages_credential( WP_User $user, array $credential ): bool {
		$uuid = (string) ( $credential['uuid'] ?? '' );
		return '' !== $uuid && is_array( get_user_meta( $user->ID, self::meta_key( $uuid ), true ) );
	}

	/**
	 * Removes connection state when its Application Password anchor is deleted.
	 *
	 * @param int                  $user_id User ID.
	 * @param array<string, mixed> $item    Deleted Application Password.
	 */
	public function deleted_application_password( int $user_id, array $item ): void {
		if ( empty( $item['uuid'] ) ) {
			return;
		}

		delete_user_meta( $user_id, self::meta_key( (string) $item['uuid'] ) );
	}

	private static function meta_key( string $connection_id ): string {
		return self::META_PREFIX . $connection_id;
	}

	private function prune_connections( int $user_id, string $client_id ): void {
		$max   = max( 1, (int) apply_filters( 'oauth_server_max_sessions_per_client', self::MAX_CONNECTIONS_PER_CLIENT, $client_id ) );
		$items = array_values(
			array_filter(
				WP_Application_Passwords::get_user_application_passwords( $user_id ),
				static function ( $item ) use ( $client_id ): bool {
					return isset( $item['app_id'] ) && $item['app_id'] === $client_id;
				}
			)
		);
		usort(
			$items,
			static function ( $a, $b ): int {
				return (int) $a['created'] <=> (int) $b['created'];
			}
		);
		foreach ( array_slice( $items, 0, max( 0, count( $items ) - $max + 1 ) ) as $item ) {
			WP_Application_Passwords::delete_application_password( $user_id, $item['uuid'] );
		}
	}
}
