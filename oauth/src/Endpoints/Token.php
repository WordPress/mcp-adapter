<?php
/**
 * Token endpoint.
 *
 * @package WP\OAuth
 */

declare( strict_types=1 );

namespace WP\OAuth\Endpoints;

use WP\OAuth\Authentication\Bearer;
use WP\OAuth\Clients\Client;
use WP\OAuth\Clients\ClientRepository;
use WP\OAuth\Grants\AuthorizationCodes;
use WP\OAuth\Grants\Pkce;
use WP\OAuth\Server;
use WP\OAuth\Tokens\AccessToken;
use WP_Application_Passwords;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Exchanges authorization codes for access tokens.
 */
final class Token {

	public const REST_NAMESPACE = 'oauth/v1';
	public const REST_ROUTE     = '/token';

	/**
	 * Most connections one user can have to one client. Signing in again
	 * beyond this removes the oldest, as in wp-media/mcp-oauth.
	 */
	public const MAX_SESSIONS_PER_CLIENT = 5;

	/**
	 * Client registry.
	 *
	 * @var ClientRepository
	 */
	private $clients;

	/**
	 * Authorization code store.
	 *
	 * @var AuthorizationCodes
	 */
	private $codes;

	/**
	 * Constructor.
	 *
	 * @param ClientRepository  $clients Client registry.
	 * @param AuthorizationCodes $codes   Authorization code store.
	 */
	public function __construct( ClientRepository $clients, AuthorizationCodes $codes ) {
		$this->clients = $clients;
		$this->codes   = $codes;
	}

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_filter( 'application_password_is_api_request', array( $this, 'skip_application_passwords' ) );
	}

	/**
	 * Registers the token route.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Stops core reading client credentials sent with Basic auth to the token
	 * and revocation endpoints as a username and Application Password, which
	 * would fail the request before it runs.
	 *
	 * @param bool $is_api_request Whether Application Passwords may be used.
	 */
	public function skip_application_passwords( $is_api_request ): bool {
		return self::is_oauth_endpoint_request() ? false : (bool) $is_api_request;
	}

	/**
	 * Handles a token request.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function handle( WP_REST_Request $request ): WP_REST_Response {
		if ( ! Server::is_available() ) {
			return self::error( 'temporarily_unavailable', __( 'OAuth is not available on this site.', 'mcp-adapter' ), 503 );
		}

		if ( 'authorization_code' !== (string) $request->get_param( 'grant_type' ) ) {
			return self::error( 'unsupported_grant_type', __( 'Only the authorization_code grant is supported.', 'mcp-adapter' ) );
		}

		$code = (string) $request->get_param( 'code' );
		if ( '' === $code ) {
			return self::error( 'invalid_request', __( 'The code parameter is required.', 'mcp-adapter' ) );
		}

		[ $client_id, $client_secret, $used_basic ] = self::client_credentials( $request );
		if ( '' === $client_id ) {
			return self::error( 'invalid_request', __( 'The client_id parameter is required.', 'mcp-adapter' ) );
		}

		// Consume the code first, so a failed attempt cannot be retried.
		$grant = $this->codes->consume( $code );
		if ( null === $grant || ! hash_equals( (string) $grant['client_id'], $client_id ) ) {
			return self::error( 'invalid_grant', __( 'The authorization code is invalid, expired, or was issued to another client.', 'mcp-adapter' ) );
		}

		$client = $this->clients->get( $client_id );
		if ( ! $client instanceof Client || ( $client->is_confidential() && ! $client->verify_secret( $client_secret ) ) ) {
			$response = self::error( 'invalid_client', __( 'Client authentication failed.', 'mcp-adapter' ), 401 );
			if ( $used_basic ) {
				$response->header( 'WWW-Authenticate', 'Basic realm="oauth"' );
			}

			return $response;
		}

		if ( (string) $request->get_param( 'redirect_uri' ) !== (string) $grant['redirect_uri'] ) {
			return self::error( 'invalid_grant', __( 'The redirect_uri does not match the authorization request.', 'mcp-adapter' ) );
		}

		if ( ! Pkce::verify( (string) $request->get_param( 'code_verifier' ), (string) $grant['code_challenge'] ) ) {
			return self::error( 'invalid_grant', __( 'The code_verifier does not match the code challenge.', 'mcp-adapter' ) );
		}

		$resource = (string) $request->get_param( 'resource' );
		if ( '' !== $resource && '' !== (string) $grant['resource'] && untrailingslashit( $resource ) !== untrailingslashit( (string) $grant['resource'] ) ) {
			return self::error( 'invalid_target', __( 'The resource does not match the authorization request.', 'mcp-adapter' ) );
		}

		$user = get_userdata( (int) $grant['user_id'] );
		if ( ! $user instanceof WP_User || ! wp_is_application_passwords_available_for_user( $user ) ) {
			return self::error( 'invalid_grant', __( 'The user can no longer authorize applications.', 'mcp-adapter' ) );
		}

		$this->prune_sessions( $user->ID, $client->get_id() );

		$created = WP_Application_Passwords::create_new_application_password(
			$user->ID,
			array(
				'name'   => $client->get_name(),
				'app_id' => $client->get_id(),
			)
		);
		if ( is_wp_error( $created ) ) {
			return self::error( 'server_error', $created->get_error_message(), 500 );
		}

		[ $password, $item ] = $created;

		return new WP_REST_Response(
			array(
				'access_token' => AccessToken::encode( $user->ID, $item['uuid'], $password ),
				'token_type'   => 'Bearer',
			),
			200,
			// Core's no-cache headers include Cache-Control: no-store (RFC 6749 section 5.1).
			// Last-Modified is false there, meaning "remove", so it is filtered out.
			array_filter( wp_get_nocache_headers() )
		);
	}

	/**
	 * Reads client credentials from Basic auth or the request body.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array{0: string, 1: string, 2: bool} Client ID, secret, and whether Basic auth was used.
	 */
	public static function client_credentials( WP_REST_Request $request ): array {
		$header = Bearer::get_authorization_header();
		if ( null !== $header && 1 === preg_match( '/^Basic\s+(\S+)$/i', $header, $matches ) ) {
			$decoded = base64_decode( $matches[1], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- HTTP Basic credentials.
			if ( false !== $decoded && false !== strpos( $decoded, ':' ) ) {
				[ $id, $secret ] = explode( ':', $decoded, 2 );

				// RFC 6749 section 2.3.1: both parts are form-encoded.
				return array( urldecode( $id ), urldecode( $secret ), true );
			}
		}

		// Client authentication (RFC 6749 section 2.3.1), not user login.
		// phpcs:disable WordPressVIPMinimum.Variables.ServerVariables.BasicAuthentication, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Credentials must not be altered.
		if ( ! empty( $_SERVER['PHP_AUTH_USER'] ) ) {
			return array(
				urldecode( (string) wp_unslash( $_SERVER['PHP_AUTH_USER'] ) ),
				urldecode( isset( $_SERVER['PHP_AUTH_PW'] ) ? (string) wp_unslash( $_SERVER['PHP_AUTH_PW'] ) : '' ),
				true,
			);
		}
		// phpcs:enable

		return array(
			(string) $request->get_param( 'client_id' ),
			(string) $request->get_param( 'client_secret' ),
			false,
		);
	}

	/**
	 * Removes the oldest connections between a user and a client so that one
	 * more stays within the limit.
	 *
	 * Only Application Passwords issued to this client are touched; they are
	 * matched by the app_id set when they were created.
	 *
	 * @param int    $user_id   User ID.
	 * @param string $client_id Client ID.
	 */
	private function prune_sessions( int $user_id, string $client_id ): void {
		/**
		 * Filters how many connections one user can have to one client.
		 *
		 * @param int    $max       Maximum connections. Default 5.
		 * @param string $client_id Client ID.
		 */
		$max = max( 1, (int) apply_filters( 'oauth_server_max_sessions_per_client', self::MAX_SESSIONS_PER_CLIENT, $client_id ) );

		$sessions = array_values(
			array_filter(
				WP_Application_Passwords::get_user_application_passwords( $user_id ),
				static function ( $item ) use ( $client_id ): bool {
					return isset( $item['app_id'] ) && $item['app_id'] === $client_id;
				}
			)
		);
		if ( count( $sessions ) < $max ) {
			return;
		}

		usort(
			$sessions,
			static function ( $a, $b ): int {
				return (int) $a['created'] <=> (int) $b['created'];
			}
		);

		foreach ( array_slice( $sessions, 0, count( $sessions ) - $max + 1 ) as $item ) {
			WP_Application_Passwords::delete_application_password( $user_id, $item['uuid'] );
		}
	}

	/**
	 * Whether the current request is for one of this server's REST endpoints.
	 */
	private static function is_oauth_endpoint_request(): bool {
		$prefix = '/' . self::REST_NAMESPACE . '/';

		if ( isset( $_GET['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only compared.
			return 0 === strpos( '/' . ltrim( sanitize_text_field( wp_unslash( $_GET['rest_route'] ) ), '/' ), $prefix ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only compared.
		}

		$path = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only compared.

		return false !== strpos( $path, '/' . rest_get_url_prefix() . $prefix );
	}

	/**
	 * Builds an RFC 6749 section 5.2 error response.
	 *
	 * @param string $code        OAuth error code.
	 * @param string $description Human-readable description.
	 * @param int    $status      HTTP status.
	 */
	public static function error( string $code, string $description, int $status = 400 ): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'error'             => $code,
				'error_description' => $description,
			),
			$status,
			array_filter( wp_get_nocache_headers() )
		);
	}
}
