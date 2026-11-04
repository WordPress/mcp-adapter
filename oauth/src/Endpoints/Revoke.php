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
	 * @var ClientRepository
	 */
	private $clients;

	/**
	 * Constructor.
	 *
	 * @param ClientRepository $clients Client registry.
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
	 * @param WP_REST_Request $request Request.
	 */
	public function handle( WP_REST_Request $request ): WP_REST_Response {
		$token = (string) $request->get_param( 'token' );
		if ( '' === $token ) {
			return Token::error( 'invalid_request', __( 'The token parameter is required.', 'mcp-adapter' ) );
		}

		[ $client_id, $client_secret, $used_basic ] = Token::client_credentials( $request );

		// Find the token's Application Password and the client it was issued to.
		$parts        = Server::is_available() ? AccessToken::decode( $token ) : null;
		$item         = null === $parts ? null : WP_Application_Passwords::get_user_application_password( $parts['user_id'], $parts['uuid'] );
		$token_client = is_array( $item ) && WP_Application_Passwords::check_password( $parts['password'], $item['password'] )
			? $this->clients->get( (string) $item['app_id'] )
			: null;

		// The client asking is the one named, or else the one the token was issued
		// to. Confidential clients must authenticate either way (RFC 7009 section 2.1).
		$client = '' === $client_id ? $token_client : $this->clients->get( $client_id );
		if ( ( '' !== $client_id && null === $client ) || ( null !== $client && $client->is_confidential() && ! $client->verify_secret( $client_secret ) ) ) {
			$response = Token::error( 'invalid_client', __( 'Client authentication failed.', 'mcp-adapter' ), 401 );
			if ( $used_basic ) {
				$response->header( 'WWW-Authenticate', 'Basic realm="oauth"' );
			}

			return $response;
		}

		// Only revoke a token issued to the client asking. Unknown, invalid, and
		// already-revoked tokens still get a 200 (RFC 7009 section 2.2).
		if ( null !== $token_client && null !== $client && $token_client->get_id() === $client->get_id() ) {
			WP_Application_Passwords::delete_application_password( $parts['user_id'], $parts['uuid'] );
		}

		return new WP_REST_Response( new stdClass(), 200, array_filter( wp_get_nocache_headers() ) );
	}
}
