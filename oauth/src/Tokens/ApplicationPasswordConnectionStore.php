<?php
/**
 * Application Password-backed OAuth connection store.
 *
 * @package WP\OAuth
 */

declare( strict_types=1 );

namespace WP\OAuth\Tokens;

use WP\OAuth\Clients\Client;
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
	private const TYPE_COLUMN               = 'oauth_server_credential_type';

	/**
	 * Registers connection lifecycle and profile-screen hooks.
	 */
	public function register_hooks(): void {
		add_action( 'wp_delete_application_password', array( $this, 'deleted_application_password' ), 10, 2 );
		add_filter( 'manage_application-passwords-user_columns', array( $this, 'add_type_column' ) );
		add_action( 'manage_application-passwords-user_custom_column', array( $this, 'render_type_column' ), 10, 2 );
		add_action( 'manage_application-passwords-user_custom_column_js_template', array( $this, 'render_type_column_template' ) );
	}

	/**
	 * Adds credential type and resource details to the Application Passwords list.
	 *
	 * @param array<string, string> $columns Existing list-table columns.
	 * @return array<string, string> Columns with type and resource after the name.
	 */
	public function add_type_column( array $columns ): array {
		$result = array();
		foreach ( $columns as $key => $label ) {
			$result[ $key ] = $label;
			if ( 'name' !== $key ) {
				continue;
			}

			$result[ self::TYPE_COLUMN ] = __( 'Type / Resource', 'mcp-adapter' );
		}

		return $result;
	}

	/**
	 * Renders the credential type and resource for an existing row.
	 *
	 * @param string               $column_name Custom column name.
	 * @param array<string, mixed> $item        Application Password record.
	 */
	public function render_type_column( string $column_name, array $item ): void {
		if ( self::TYPE_COLUMN !== $column_name ) {
			return;
		}

		global $user_id;
		$uuid       = isset( $item['uuid'] ) ? (string) $item['uuid'] : '';
		$connection = '' === $uuid ? null : $this->get( (int) $user_id, $uuid );
		if ( null === $connection ) {
			esc_html_e( 'Application Password', 'mcp-adapter' );
			return;
		}

		echo '<strong>' . esc_html__( 'OAuth 2.0', 'mcp-adapter' ) . '</strong><br />';
		$resource = $connection->get_resource();
		if ( '' === $resource ) {
			echo '<span class="description">' . esc_html__( 'All REST API resources', 'mcp-adapter' ) . '</span>';
			return;
		}

		echo '<code style="overflow-wrap:anywhere;">' . esc_html( $resource ) . '</code>';
	}

	/**
	 * Renders the credential type in core's new-password JavaScript row template.
	 *
	 * Rows created from that template are ordinary Application Passwords. OAuth
	 * connections are created during token exchange and appear after a reload.
	 *
	 * @param string $column_name Custom column name.
	 */
	public function render_type_column_template( string $column_name ): void {
		if ( self::TYPE_COLUMN !== $column_name ) {
			return;
		}

		esc_html_e( 'Application Password', 'mcp-adapter' );
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
				'app_id' => Client::application_password_id( $client_id ),
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
		$max    = max( 1, (int) apply_filters( 'oauth_server_max_sessions_per_client', self::MAX_CONNECTIONS_PER_CLIENT, $client_id ) );
		$app_id = Client::application_password_id( $client_id );
		$items  = array_values(
			array_filter(
				WP_Application_Passwords::get_user_application_passwords( $user_id ),
				static function ( $item ) use ( $app_id ): bool {
					return isset( $item['app_id'] ) && $item['app_id'] === $app_id;
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
