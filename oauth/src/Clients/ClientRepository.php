<?php
/**
 * Client registry.
 *
 * @package WP\OAuth
 */

declare( strict_types=1 );

namespace WP\OAuth\Clients;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Stores registered clients in a single option.
 */
final class ClientRepository {

	public const OPTION = 'oauth_server_clients';

	/**
	 * Gets all clients, keyed by client ID.
	 *
	 * @return array<string, \WP\OAuth\Clients\Client>
	 */
	public function all(): array {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		$clients = array();
		foreach ( $stored as $data ) {
			$client = Client::from_array( $data );
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
		if ( '' === $id ) {
			return null;
		}

		return $this->all()[ $id ] ?? null;
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

		$secret = $confidential ? wp_generate_password( 40, false ) : null;
		$client = new Client(
			wp_generate_uuid4(),
			$details[0],
			$details[1],
			null === $secret ? null : wp_fast_hash( $secret ),
			time()
		);

		$this->save( array_merge( $this->all(), array( $client->get_id() => $client ) ) );

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
		$client = $this->get( $id );
		if ( null === $client ) {
			return new WP_Error( 'oauth_server_client_missing', __( 'That client no longer exists.', 'mcp-adapter' ) );
		}

		$details = self::validate_details( $name, $redirect_uris );
		if ( is_wp_error( $details ) ) {
			return $details;
		}

		$client = $client->with_details( $details[0], $details[1] );
		$this->put( $client );

		return $client;
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
		$client = $this->get( $id );
		if ( null === $client ) {
			return new WP_Error( 'oauth_server_client_missing', __( 'That client no longer exists.', 'mcp-adapter' ) );
		}

		$secret = $confidential ? wp_generate_password( 40, false ) : null;
		$client = $client->with_secret_hash( null === $secret ? null : wp_fast_hash( $secret ) );
		$this->put( $client );

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
		$clients = $this->all();
		if ( ! isset( $clients[ $id ] ) ) {
			return false;
		}

		unset( $clients[ $id ] );
		$this->save( $clients );

		return true;
	}

	/**
	 * Saves clients.
	 *
	 * @param array<string, \WP\OAuth\Clients\Client> $clients Clients keyed by ID.
	 */
	private function save( array $clients ): void {
		$data = array();
		foreach ( $clients as $client ) {
			$data[] = $client->to_array();
		}

		update_option( self::OPTION, $data, false );
	}

	/**
	 * Saves one client, replacing any stored client with the same ID.
	 *
	 * @param \WP\OAuth\Clients\Client $client Client.
	 */
	private function put( Client $client ): void {
		$clients                      = $this->all();
		$clients[ $client->get_id() ] = $client;
		$this->save( $clients );
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
