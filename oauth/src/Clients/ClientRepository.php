<?php
/**
 * Client registry.
 *
 * @package WP\OAuth
 */

declare( strict_types=1 );

namespace WP\OAuth\Clients;

use WP_Application_Passwords;
use WP_Error;
use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Stores registered clients as a private post type, as WP-API/OAuth2 does.
 *
 * The client ID is the post slug, so lookups use the indexed post_name column.
 * Redirect URIs and the secret hash are post meta. Only administrators can
 * manage clients, through Settings > OAuth Clients.
 */
final class ClientRepository {

	public const POST_TYPE = 'oauth_server_client';

	/**
	 * Most clients the settings screen lists. Admin-registered clients are few;
	 * Dynamic Client Registration will need paging.
	 */
	private const LIST_LIMIT = 200;

	private const REDIRECT_URIS_KEY = '_oauth_server_redirect_uris';
	private const SECRET_HASH_KEY   = '_oauth_server_secret_hash';
	private const SOURCE_KEY        = '_oauth_server_client_source';
	private const CIMD_ID_KEY       = '_oauth_server_cimd_client_id';
	private const CIMD_STATE_KEY    = '_oauth_server_cimd_state';

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'init', array( $this, 'register_post_type' ) );

		// Remove a client's connections however it is deleted or trashed.
		add_action( 'before_delete_post', array( $this, 'delete_connections' ), 10, 2 );
		add_action( 'wp_trash_post', array( $this, 'delete_connections' ) );
	}

	/**
	 * Registers the client post type.
	 */
	public function register_post_type(): void {
		register_post_type(
			'oauth_server_client',
			array(
				'labels'           => array(
					'name'          => __( 'OAuth Clients', 'mcp-adapter' ),
					'singular_name' => __( 'OAuth Client', 'mcp-adapter' ),
				),
				'public'           => false,
				'rewrite'          => false,
				'query_var'        => false,
				'supports'         => array( 'title' ),
				'can_export'       => true,
				'delete_with_user' => false,
				'capability_type'  => array( 'oauth_server_client', 'oauth_server_clients' ),
				'map_meta_cap'     => true,
				'capabilities'     => array(
					'create_posts'           => 'manage_options',
					'edit_posts'             => 'manage_options',
					'edit_others_posts'      => 'manage_options',
					'edit_published_posts'   => 'manage_options',
					'edit_private_posts'     => 'manage_options',
					'publish_posts'          => 'manage_options',
					'read_private_posts'     => 'manage_options',
					'delete_posts'           => 'manage_options',
					'delete_others_posts'    => 'manage_options',
					'delete_published_posts' => 'manage_options',
					'delete_private_posts'   => 'manage_options',
				),
			)
		);
	}

	/**
	 * Gets all clients, oldest first.
	 *
	 * @return array<string, Client> Clients keyed by client ID.
	 */
	public function all(): array {
		$posts = ( new WP_Query() )->query(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => self::LIST_LIMIT,
				'orderby'        => 'date',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);

		$clients = array();
		foreach ( $posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			$client = self::to_client( $post );
			if ( null === $client ) {
				continue;
			}

			$clients[ $client->get_id() ] = $client;
		}

		return $clients;
	}

	/**
	 * Gets a client by ID.
	 *
	 * @param string $id Client ID.
	 */
	public function get( string $id ): ?Client {
		$post = self::find_post( $id );

		return null === $post ? null : self::to_client( $post );
	}

	/**
	 * Registers a new client.
	 *
	 * @param string   $name          Display name.
	 * @param string[] $redirect_uris Redirect URIs.
	 * @param bool     $confidential  Whether the client gets a secret.
	 * @return array{0: Client, 1: string|null}|WP_Error The client and its plain-text secret, or an error.
	 */
	public function create( string $name, array $redirect_uris, bool $confidential ) {
		$details = self::validate_details( $name, $redirect_uris );
		if ( is_wp_error( $details ) ) {
			return $details;
		}

		$id      = wp_generate_uuid4();
		$secret  = $confidential ? wp_generate_password( 40, false ) : null;
		$post_id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $details[0],
				'post_name'   => $id,
				'meta_input'  => array(
					self::REDIRECT_URIS_KEY => $details[1],
					self::SECRET_HASH_KEY   => null === $secret ? '' : wp_fast_hash( $secret ),
				),
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$client = $this->get( $id );
		if ( null === $client ) {
			return new WP_Error( 'oauth_server_client_save', __( 'The client could not be saved.', 'mcp-adapter' ) );
		}

		return array( $client, $secret );
	}

	/**
	 * Updates a client's name and redirect URIs.
	 *
	 * Tokens already issued keep working; new authorizations use the new URIs.
	 *
	 * @param string   $id            Client ID.
	 * @param string   $name          Display name.
	 * @param string[] $redirect_uris Redirect URIs.
	 * @return Client|WP_Error The updated client, or an error.
	 */
	public function update( string $id, string $name, array $redirect_uris ) {
		$post = self::find_post( $id );
		if ( null === $post ) {
			return new WP_Error( 'oauth_server_client_missing', __( 'That client no longer exists.', 'mcp-adapter' ) );
		}
		if ( 'cimd' === get_post_meta( $post->ID, self::SOURCE_KEY, true ) ) {
			return new WP_Error( 'oauth_server_client_read_only', __( 'Metadata document clients are updated from their publisher.', 'mcp-adapter' ) );
		}

		$details = self::validate_details( $name, $redirect_uris );
		if ( is_wp_error( $details ) ) {
			return $details;
		}

		$result = wp_update_post(
			array(
				'ID'         => $post->ID,
				'post_title' => $details[0],
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		update_post_meta( $post->ID, self::REDIRECT_URIS_KEY, $details[1] );

		return $this->get( $id ) ?? new WP_Error( 'oauth_server_client_save', __( 'The client could not be saved.', 'mcp-adapter' ) );
	}

	/**
	 * Gives a client a new secret, or removes its secret.
	 *
	 * The old secret stops working at once.
	 *
	 * @param string $id           Client ID.
	 * @param bool   $confidential True to generate a new secret, false to make the client public.
	 * @return array{0: Client, 1: string|null}|WP_Error The client and its plain-text secret, or an error.
	 */
	public function reset_secret( string $id, bool $confidential ) {
		$post = self::find_post( $id );
		if ( null === $post ) {
			return new WP_Error( 'oauth_server_client_missing', __( 'That client no longer exists.', 'mcp-adapter' ) );
		}
		if ( 'cimd' === get_post_meta( $post->ID, self::SOURCE_KEY, true ) ) {
			return new WP_Error( 'oauth_server_client_read_only', __( 'Metadata document clients are public and cannot have a secret.', 'mcp-adapter' ) );
		}

		$secret = $confidential ? wp_generate_password( 40, false ) : null;
		update_post_meta( $post->ID, self::SECRET_HASH_KEY, null === $secret ? '' : wp_fast_hash( $secret ) );

		$client = $this->get( $id );
		if ( null === $client ) {
			return new WP_Error( 'oauth_server_client_save', __( 'The client could not be saved.', 'mcp-adapter' ) );
		}

		return array( $client, $secret );
	}

	/**
	 * Deletes a client.
	 *
	 * Its connections are removed too, through delete_connections().
	 *
	 * @param string $id Client ID.
	 */
	public function delete( string $id ): bool {
		$post = self::find_post( $id );

		return null !== $post && false !== wp_delete_post( $post->ID, true );
	}

	/**
	 * Deletes the Application Passwords issued to a client, for every user.
	 *
	 * Runs when a client post is deleted or trashed. Bearer authentication
	 * already rejects tokens for a missing client, but without this the
	 * passwords would stay in users' profiles looking active, and would work
	 * again if a client with the same ID came back.
	 *
	 * @param int           $post_id Post ID.
	 * @param \WP_Post|null $post    Post, when WordPress passes it.
	 */
	public function delete_connections( $post_id, $post = null ): void {
		$post = $post instanceof WP_Post ? $post : get_post( (int) $post_id );
		if ( ! $post instanceof WP_Post || self::POST_TYPE !== $post->post_type || '' === $post->post_name ) {
			return;
		}

		$client_id = 'cimd' === get_post_meta( $post->ID, self::SOURCE_KEY, true )
			? (string) get_post_meta( $post->ID, self::CIMD_ID_KEY, true )
			: $post->post_name;
		$this->revoke_connections_for_client( $client_id );
	}

	/**
	 * Revokes every connection issued to a client ID.
	 *
	 * @param string $client_id Exact OAuth client identifier.
	 */
	public function revoke_connections_for_client( string $client_id ): void {
		if ( '' === $client_id ) {
			return;
		}

		$app_id = Client::application_password_id( $client_id );

		// Narrow to users whose stored passwords mention the application ID. Application
		// Passwords belong to the network-wide user, so search every site.
		$user_ids = get_users(
			array(
				'blog_id'      => 0,
				'fields'       => 'ID',
				'meta_key'     => WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Runs only when a client is deleted.
				'meta_value'   => $app_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Runs only when a client is deleted.
				'meta_compare' => 'LIKE',
			)
		);

		foreach ( $user_ids as $user_id ) {
			foreach ( WP_Application_Passwords::get_user_application_passwords( (int) $user_id ) as $item ) {
				if ( ( $item['app_id'] ?? '' ) !== $app_id ) {
					continue;
				}

				WP_Application_Passwords::delete_application_password( (int) $user_id, $item['uuid'] );
			}
		}
	}

	/**
	 * Saves a verified metadata document as the permanent local client record.
	 *
	 * @param string               $id       Exact metadata document URL.
	 * @param array<string, mixed> $metadata Validated metadata.
	 * @param int                  $expires  Cache expiry timestamp.
	 * @param bool                 $trusted  Whether policy trusts its publisher.
	 * @return Client|WP_Error
	 */
	public function save_cimd( string $id, array $metadata, int $expires, bool $trusted ) {
		$post          = self::find_post( $id );
		$security_hash = self::cimd_security_hash( $metadata );
		$old_state     = $post ? get_post_meta( $post->ID, self::CIMD_STATE_KEY, true ) : array();
		$old_hash      = is_array( $old_state ) ? (string) ( $old_state['security_hash'] ?? '' ) : '';
		$generation    = is_array( $old_state ) ? (string) ( $old_state['generation'] ?? '' ) : '';
		if ( '' === $generation || ! hash_equals( $old_hash, $security_hash ) ) {
			$generation = bin2hex( random_bytes( 16 ) );
			if ( $post ) {
				$this->revoke_connections_for_client( $id );
			}
		}

		$state     = array(
			'metadata'      => $metadata,
			'expires'       => $expires,
			'generation'    => $generation,
			'security_hash' => $security_hash,
			'trusted'       => $trusted,
			'updated'       => time(),
		);
		$post_data = array(
			'post_type'   => self::POST_TYPE,
			'post_status' => 'publish',
			'post_title'  => (string) $metadata['client_name'],
			'post_name'   => self::cimd_slug( $id ),
			'meta_input'  => array(
				self::SOURCE_KEY     => 'cimd',
				self::CIMD_ID_KEY    => $id,
				self::CIMD_STATE_KEY => $state,
			),
		);
		if ( $post ) {
			$post_data['ID'] = $post->ID;
		}
		$result = $post ? wp_update_post( $post_data, true ) : wp_insert_post( $post_data, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->get( $id ) ?? new WP_Error( 'oauth_server_client_save', __( 'The client could not be saved.', 'mcp-adapter' ) );
	}

	/**
	 * Whether verified metadata needs administrator approval before it is saved.
	 *
	 * New registrations and changes to security-relevant fields require approval.
	 * Display-name and cache-only changes do not.
	 *
	 * @param string               $id       Exact metadata document URL.
	 * @param array<string, mixed> $metadata Verified metadata document.
	 * @return bool Whether administrator approval is required.
	 */
	public function cimd_requires_approval( string $id, array $metadata ): bool {
		$post = self::find_post( $id );
		if ( null === $post ) {
			return true;
		}

		$state = get_post_meta( $post->ID, self::CIMD_STATE_KEY, true );
		return ! is_array( $state ) || ! isset( $state['security_hash'] ) || ! hash_equals( (string) $state['security_hash'], self::cimd_security_hash( $metadata ) );
	}

	/**
	 * Finds the post for a client ID.
	 *
	 * Uses get_page_by_path(), which reads the posts table directly and is
	 * object-cached. Unlike WP_Query it never looks up the current user, so it
	 * is safe to call while authentication is still working that out.
	 *
	 * @param string $id Client ID.
	 */
	private static function find_post( string $id ): ?WP_Post {
		$slug = wp_is_uuid( $id, 4 ) ? $id : self::cimd_slug( $id );
		$post = get_page_by_path( $slug, OBJECT, self::POST_TYPE );
		if ( $post instanceof WP_Post && ! wp_is_uuid( $id, 4 ) && get_post_meta( $post->ID, self::CIMD_ID_KEY, true ) !== $id ) {
			return null;
		}

		return $post instanceof WP_Post && 'publish' === $post->post_status ? $post : null;
	}

	/**
	 * Builds a client from its post.
	 *
	 * @param WP_Post $post Client post.
	 * @return Client|null The client, or null if the post is malformed.
	 */
	private static function to_client( WP_Post $post ): ?Client {
		if ( 'cimd' === get_post_meta( $post->ID, self::SOURCE_KEY, true ) ) {
			$id    = (string) get_post_meta( $post->ID, self::CIMD_ID_KEY, true );
			$state = get_post_meta( $post->ID, self::CIMD_STATE_KEY, true );
			$data  = is_array( $state ) && isset( $state['metadata'] ) && is_array( $state['metadata'] ) ? $state['metadata'] : array();
			if ( '' === $id || empty( $data['redirect_uris'] ) || ! is_array( $data['redirect_uris'] ) ) {
				return null;
			}
			return new Client( $id, (string) ( $data['client_name'] ?? $post->post_title ), array_map( 'strval', $data['redirect_uris'] ), null, (int) get_post_time( 'U', true, $post ), $data, (string) ( $state['generation'] ?? '' ), (int) ( $state['expires'] ?? 0 ), (bool) ( $state['trusted'] ?? false ) );
		}
		$redirect_uris = get_post_meta( $post->ID, self::REDIRECT_URIS_KEY, true );
		if ( '' === $post->post_name || ! is_array( $redirect_uris ) || empty( $redirect_uris ) ) {
			return null;
		}

		$secret_hash = (string) get_post_meta( $post->ID, self::SECRET_HASH_KEY, true );

		return new Client(
			$post->post_name,
			$post->post_title,
			array_map( 'strval', $redirect_uris ),
			'' === $secret_hash ? null : $secret_hash,
			(int) get_post_time( 'U', true, $post )
		);
	}

	/**
	 * Gets the collision-resistant post slug for a metadata URL.
	 *
	 * @param string $id Exact client ID metadata document URL.
	 * @return string Private client post slug.
	 */
	private static function cimd_slug( string $id ): string {
		return 'cimd-' . hash( 'sha256', $id );
	}

	/**
	 * Fingerprints the metadata fields that affect OAuth security.
	 *
	 * @param array<string, mixed> $metadata Verified metadata document.
	 * @return string SHA-256 metadata fingerprint.
	 */
	private static function cimd_security_hash( array $metadata ): string {
		$security_data = array_intersect_key( $metadata, array_flip( array( 'client_id', 'redirect_uris', 'grant_types', 'response_types', 'token_endpoint_auth_method', 'token_endpoint_auth_methods' ) ) );

		return hash( 'sha256', wp_json_encode( $security_data ) ?: '' );
	}

	/**
	 * Validates a client name and redirect URIs.
	 *
	 * @param string   $name          Display name.
	 * @param string[] $redirect_uris Redirect URIs.
	 * @return array{0: string, 1: string[]}|WP_Error The cleaned name and URIs, or an error.
	 */
	private static function validate_details( string $name, array $redirect_uris ) {
		$name = sanitize_text_field( $name );
		if ( '' === $name ) {
			return new WP_Error( 'oauth_server_client_name', __( 'Enter a name for the client.', 'mcp-adapter' ) );
		}

		$redirect_uris = array_values( array_unique( array_filter( array_map( 'trim', $redirect_uris ) ) ) );
		if ( empty( $redirect_uris ) ) {
			return new WP_Error( 'oauth_server_client_redirect', __( 'Enter at least one redirect URI.', 'mcp-adapter' ) );
		}

		foreach ( $redirect_uris as $uri ) {
			$valid = Client::validate_redirect_uri( $uri );
			if ( is_wp_error( $valid ) ) {
				return new WP_Error(
					'oauth_server_client_redirect',
					/* translators: 1: redirect URI, 2: reason it was rejected. */
					sprintf( __( '%1$s is not a valid redirect URI. %2$s', 'mcp-adapter' ), $uri, $valid->get_error_message() )
				);
			}
		}

		return array( $name, $redirect_uris );
	}
}
