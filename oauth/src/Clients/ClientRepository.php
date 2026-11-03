<?php
/**
 * Client registry.
 *
 * @package WP\OAuth
 */

declare( strict_types=1 );

namespace WP\OAuth\Clients;

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
		$name = sanitize_text_field( $name );
		if ( '' === $name ) {
			return new \WP_Error( 'oauth_server_client_name', __( 'Enter a name for the client.', 'mcp-adapter' ) );
		}

		$redirect_uris = array_values( array_unique( array_filter( array_map( 'trim', $redirect_uris ) ) ) );
		if ( empty( $redirect_uris ) ) {
			return new \WP_Error( 'oauth_server_client_redirect', __( 'Enter at least one redirect URI.', 'mcp-adapter' ) );
		}

		foreach ( $redirect_uris as $uri ) {
			if ( ! Client::is_valid_redirect_uri( $uri ) ) {
				return new \WP_Error(
					'oauth_server_client_redirect',
					/* translators: %s: redirect URI. */
					sprintf( __( '%s is not a valid redirect URI. Use HTTPS, HTTP on localhost, or an app-specific scheme.', 'mcp-adapter' ), $uri )
				);
			}
		}

		$secret = $confidential ? wp_generate_password( 40, false ) : null;
		$client = new Client(
			wp_generate_uuid4(),
			$name,
			$redirect_uris,
			null === $secret ? null : wp_fast_hash( $secret ),
			time()
		);

		$this->save( array_merge( $this->all(), array( $client->get_id() => $client ) ) );

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
}
