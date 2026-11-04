<?php
/**
 * Client registry.
 *
 * @package WP\OAuth
 */

declare( strict_types=1 );

namespace WP\OAuth\Clients;

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

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'init', array( $this, 'register_post_type' ) );
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
	 * @return array<string, \WP\OAuth\Clients\Client> Clients keyed by client ID.
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
	 * @return array{0: \WP\OAuth\Clients\Client, 1: string|null}|\WP_Error The client and its plain-text secret, or an error.
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
	 * @return \WP\OAuth\Clients\Client|\WP_Error The updated client, or an error.
	 */
	public function update( string $id, string $name, array $redirect_uris ) {
		$post = self::find_post( $id );
		if ( null === $post ) {
			return new WP_Error( 'oauth_server_client_missing', __( 'That client no longer exists.', 'mcp-adapter' ) );
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
	 * @return array{0: \WP\OAuth\Clients\Client, 1: string|null}|\WP_Error The client and its plain-text secret, or an error.
	 */
	public function reset_secret( string $id, bool $confidential ) {
		$post = self::find_post( $id );
		if ( null === $post ) {
			return new WP_Error( 'oauth_server_client_missing', __( 'That client no longer exists.', 'mcp-adapter' ) );
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
	 * Tokens issued to the client stop working, because bearer authentication
	 * checks that the client still exists.
	 *
	 * @param string $id Client ID.
	 */
	public function delete( string $id ): bool {
		$post = self::find_post( $id );

		return null !== $post && false !== wp_delete_post( $post->ID, true );
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
		if ( ! wp_is_uuid( $id, 4 ) ) {
			return null;
		}

		$post = get_page_by_path( $id, OBJECT, self::POST_TYPE );

		return $post instanceof WP_Post && 'publish' === $post->post_status ? $post : null;
	}

	/**
	 * Builds a client from its post.
	 *
	 * @param \WP_Post $post Client post.
	 * @return \WP\OAuth\Clients\Client|null The client, or null if the post is malformed.
	 */
	private static function to_client( WP_Post $post ): ?Client {
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
	 * Validates a client name and redirect URIs.
	 *
	 * @param string   $name          Display name.
	 * @param string[] $redirect_uris Redirect URIs.
	 * @return array{0: string, 1: string[]}|\WP_Error The cleaned name and URIs, or an error.
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
			if ( ! Client::is_valid_redirect_uri( $uri ) ) {
				return new WP_Error(
					'oauth_server_client_redirect',
					/* translators: %s: redirect URI. */
					sprintf( __( '%s is not a valid redirect URI. Use HTTPS, HTTP on localhost, or an app-specific scheme.', 'mcp-adapter' ), $uri )
				);
			}
		}

		return array( $name, $redirect_uris );
	}
}
