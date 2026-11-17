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
use WP\OAuth\Grants\AuthorizedGrant;
use WP\OAuth\Grants\Pkce;
use WP\OAuth\Server;
use WP\OAuth\Tokens\Contracts\TokenServiceInterface;
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
	 * Token service.
	 *
	 * @var TokenServiceInterface
	 */
	private $tokens;

	/**
	 * Constructor.
	 *
	 * @param ClientRepository      $clients Client registry.
	 * @param AuthorizationCodes    $codes   Authorization code store.
	 * @param TokenServiceInterface $tokens  Token service.
	 */
	public function __construct( ClientRepository $clients, AuthorizationCodes $codes, TokenServiceInterface $tokens ) {
		$this->clients = $clients;
		$this->codes   = $codes;
		$this->tokens  = $tokens;
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

		$grant_type = (string) $request->get_param( 'grant_type' );
		if ( 'refresh_token' === $grant_type ) {
			return $this->handle_refresh( $request );
		}
		if ( 'authorization_code' !== $grant_type ) {
			return self::error( 'unsupported_grant_type', __( 'Only the authorization_code and refresh_token grants are supported.', 'mcp-adapter' ) );
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
		if ( ! hash_equals( $client->get_generation(), (string) ( $grant['client_generation'] ?? '' ) ) ) {
			return self::error( 'invalid_grant', __( 'The client registration changed after this code was issued.', 'mcp-adapter' ) );
		}

		if ( (string) $request->get_param( 'redirect_uri' ) !== (string) $grant['redirect_uri'] ) {
			return self::error( 'invalid_grant', __( 'The redirect_uri does not match the authorization request.', 'mcp-adapter' ) );
		}

		if ( ! Pkce::verify( (string) $request->get_param( 'code_verifier' ), (string) $grant['code_challenge'] ) ) {
			return self::error( 'invalid_grant', __( 'The code_verifier does not match the code challenge.', 'mcp-adapter' ) );
		}

		$resource           = (string) $request->get_param( 'resource' );
		$granted_resource   = (string) $grant['resource'];
		$resources_mismatch = '' === $resource || '' === $granted_resource
			? ! hash_equals( $granted_resource, $resource )
			: ! hash_equals( untrailingslashit( $granted_resource ), untrailingslashit( $resource ) );
		if ( $resources_mismatch ) {
			return self::error( 'invalid_target', __( 'The resource does not match the authorization request.', 'mcp-adapter' ) );
		}

		$user = get_userdata( (int) $grant['user_id'] );
		if ( ! $user instanceof WP_User || ! wp_is_application_passwords_available_for_user( $user ) ) {
			return self::error( 'invalid_grant', __( 'The user can no longer authorize applications.', 'mcp-adapter' ) );
		}

		$tokens = $this->tokens->issue(
			new AuthorizedGrant(
				$user,
				$client->get_id(),
				$client->get_name(),
				(string) $grant['resource'],
				$client->is_cimd() ? $client->get_metadata() : array(
					'client_id'     => $client->get_id(),
					'client_name'   => $client->get_name(),
					'redirect_uris' => $client->get_redirect_uris(),
					'source'        => 'registered',
				)
			)
		);
		if ( is_wp_error( $tokens ) ) {
			return self::error( 'server_error', $tokens->get_error_message(), 500 );
		}

		return new WP_REST_Response(
			$tokens->to_response_data(),
			200,
			// Core's no-cache headers include Cache-Control: no-store (RFC 6749 section 5.1).
			// Last-Modified is false there, meaning "remove", so it is filtered out.
			array_filter( wp_get_nocache_headers() )
		);
	}

	/**
	 * Handles a refresh-token grant.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	private function handle_refresh( WP_REST_Request $request ): WP_REST_Response {
		$refresh_token = (string) $request->get_param( 'refresh_token' );
		if ( '' === $refresh_token ) {
			return self::error( 'invalid_request', __( 'The refresh_token parameter is required.', 'mcp-adapter' ) );
		}

		[ $client_id, $client_secret, $used_basic ] = self::client_credentials( $request );
		if ( '' === $client_id ) {
			return self::error( 'invalid_request', __( 'The client_id parameter is required.', 'mcp-adapter' ) );
		}

		$client = $this->clients->get( $client_id );
		if ( ! $client instanceof Client || ( $client->is_confidential() && ! $client->verify_secret( $client_secret ) ) ) {
			$response = self::error( 'invalid_client', __( 'Client authentication failed.', 'mcp-adapter' ), 401 );
			if ( $used_basic ) {
				$response->header( 'WWW-Authenticate', 'Basic realm="oauth"' );
			}

			return $response;
		}

		$tokens = $this->tokens->refresh( $refresh_token, $client_id, (string) $request->get_param( 'resource' ) );
		if ( is_wp_error( $tokens ) ) {
			$data   = $tokens->get_error_data();
			$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
			$code   = (string) $tokens->get_error_code();
			return self::error( $code, $tokens->get_error_message(), $status );
		}

		return new WP_REST_Response(
			$tokens->to_response_data(),
			200,
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
