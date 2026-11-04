<?php
/**
 * Revocation endpoint.
 *
 * @package WP\OAuth
 */

declare( strict_types=1 );

namespace WP\OAuth\Endpoints;

use WP\OAuth\Clients\ClientRepository;
use WP\OAuth\Server;
use WP\OAuth\Tokens\AccessToken;
use WP_Application_Passwords;
use WP_REST_Request;
use WP_REST_Response;
use stdClass;

defined( 'ABSPATH' ) || exit;

/**
 * Revokes tokens (RFC 7009), following wp-media/mcp-oauth.
 *
 * Revoking deletes the Application Password behind the token. Unknown or
 * already-revoked tokens still get a 200, so the response says nothing about
 * which tokens exist.
 */
final class Revoke {

	public const REST_ROUTE = '/revoke';

	/**
	 * Client registry.
	 *
	 * @var \WP\OAuth\Clients\ClientRepository
	 */
	private $clients;

	/**
	 * Constructor.
	 *
	 * @param \WP\OAuth\Clients\ClientRepository $clients Client registry.
	 */
	public function __construct( ClientRepository $clients ) {
		$this->clients = $clients;
	}

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the revocation route.
	 */
	public function register_routes(): void {
		register_rest_route(
			Token::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Handles a revocation request.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function handle( WP_REST_Request $request ): WP_REST_Response {
		$token = (string) $request->get_param( 'token' );
		if ( '' === $token ) {
			return Token::error( 'invalid_request', __( 'The token parameter is required.', 'mcp-adapter' ) );
		}

		[ $client_id, $client_secret ] = Token::client_credentials( $request );

		// Confidential clients must authenticate (RFC 7009 section 2.1).
		$client = '' === $client_id ? null : $this->clients->get( $client_id );
		if ( null !== $client && $client->is_confidential() && ! $client->verify_secret( $client_secret ) ) {
			return Token::error( 'invalid_client', __( 'Client authentication failed.', 'mcp-adapter' ), 401 );
		}

		$parts = AccessToken::decode( $token );

		if ( null !== $parts && Server::is_available() ) {
			$item = WP_Application_Passwords::get_user_application_password( $parts['user_id'], $parts['uuid'] );

			// Only revoke for whoever holds the token, and only if it belongs to the
			// calling client when one is named (RFC 7009 section 2.1).
			$holds_token = is_array( $item ) && WP_Application_Passwords::check_password( $parts['password'], $item['password'] );
			$is_oauth    = $holds_token && null !== $this->clients->get( (string) $item['app_id'] );
			$same_client = '' === $client_id || ( $holds_token && hash_equals( (string) $item['app_id'], $client_id ) );

			if ( $is_oauth && $same_client ) {
				WP_Application_Passwords::delete_application_password( $parts['user_id'], $parts['uuid'] );
			}
		}

		return new WP_REST_Response( new stdClass(), 200, Token::no_store_headers() );
	}
}
