<?php
/**
 * Bearer token authentication.
 *
 * @package WP\OAuth
 */

declare( strict_types=1 );

namespace WP\OAuth\Authentication;

use WP\OAuth\ProtectedResources;
use WP\OAuth\Tokens\AuthenticatedToken;
use WP\OAuth\Tokens\Contracts\ConnectionStoreInterface;
use WP\OAuth\Tokens\Contracts\TokenServiceInterface;
use WP_Error;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Authenticates REST requests that carry an OAuth access token.
 */
final class Bearer {

	/**
	 * Token service.
	 *
	 * @var TokenServiceInterface
	 */
	private $tokens;

	/**
	 * OAuth connection persistence.
	 *
	 * @var ConnectionStoreInterface
	 */
	private $connections;

	/**
	 * Protected resource registry.
	 *
	 * @var ProtectedResources
	 */
	private $resources;

	/**
	 * Error from the last attempt, if it failed.
	 *
	 * @var WP_Error|null
	 */
	private $error = null;

	/**
	 * Whether the last attempt succeeded.
	 *
	 * @var bool
	 */
	private $authenticated = false;

	/**
	 * Whether a token is being checked right now.
	 *
	 * Checking a token runs queries, and anything that asks for the current
	 * user during them would start authentication again. WP-API/OAuth2 uses
	 * the same lock.
	 *
	 * @var bool
	 */
	private $checking = false;

	/**
	 * Constructor.
	 *
	 * @param TokenServiceInterface    $tokens      Token service.
	 * @param ConnectionStoreInterface $connections Connection persistence.
	 * @param ProtectedResources       $resources   Protected resource registry.
	 */
	public function __construct( TokenServiceInterface $tokens, ConnectionStoreInterface $connections, ProtectedResources $resources ) {
		$this->tokens      = $tokens;
		$this->connections = $connections;
		$this->resources   = $resources;
	}

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		// Between the auth cookie (10) and the logged-in cookie (20), so an explicit
		// bearer token wins over a logged-in cookie the browser also sent. The
		// auth cookie is only sent to wp-admin, so it does not affect REST requests.
		add_filter( 'determine_current_user', array( $this, 'authenticate' ), 15 );
		add_filter( 'rest_authentication_errors', array( $this, 'report' ), 85 );
		add_action( 'wp_authenticate_application_password_errors', array( $this, 'block_basic_auth' ), 10, 3 );
	}

	/**
	 * Sets the current user from a bearer token.
	 *
	 * @param int|false $user_id User ID found so far.
	 * @return int|false
	 */
	public function authenticate( $user_id ) {
		if ( ! empty( $user_id ) || $this->checking ) {
			return $user_id;
		}

		$token = $this->get_bearer_token();
		if ( null === $token || ! $this->is_api_request() ) {
			return $user_id;
		}

		$this->error         = null;
		$this->authenticated = false;

		$this->checking = true;
		try {
			$result = $this->validate( $token );
		} finally {
			$this->checking = false;
		}

		if ( is_wp_error( $result ) ) {
			$this->error = $result;

			return $user_id;
		}
		if ( ! $this->token_allows_request( $result ) ) {
			$this->error = new WP_Error(
				'oauth_server_invalid_token',
				__( 'The access token is not valid for this resource.', 'mcp-adapter' ),
				array( 'status' => 401 )
			);

			return $user_id;
		}

		$user = $result->get_user();

		$this->tokens->record_usage( $result );
		$this->authenticated = true;

		return $user->ID;
	}

	/**
	 * Reports the result to the REST API.
	 *
	 * @param WP_Error|true|null $result Result from earlier authentication methods.
	 * @return WP_Error|true|null
	 */
	public function report( $result ) {
		if ( ! empty( $result ) ) {
			return $result;
		}

		if ( null !== $this->error ) {
			return $this->error;
		}

		return $this->authenticated ? true : $result;
	}

	/**
	 * Whether the last attempt failed.
	 */
	public function has_error(): bool {
		return null !== $this->error;
	}

	/**
	 * Gets the error from the last attempt, if it failed.
	 */
	public function get_error(): ?WP_Error {
		return $this->error;
	}

	/**
	 * Stops OAuth-issued Application Passwords from working with Basic auth.
	 *
	 * @param WP_Error            $error Errors so far.
	 * @param mixed               $user  User being authenticated.
	 * @param array<string, mixed> $item  Application Password details.
	 */
	public function block_basic_auth( $error, $user, $item ): void {
		if ( ! $error instanceof WP_Error || ! $user instanceof WP_User || ! is_array( $item ) || empty( $item['app_id'] ) ) {
			return;
		}

		if ( ! $this->connections->manages_credential( $user, $item ) ) {
			return;
		}

		$error->add( 'oauth_server_bearer_only', __( 'This application password was issued through OAuth and only works as a bearer token.', 'mcp-adapter' ) );
	}

	/**
	 * Validates a token.
	 *
	 * @param string $token Token.
	 * @return AuthenticatedToken|WP_Error Validated token context, or an error.
	 */
	private function validate( string $token ) {
		return $this->tokens->authenticate_access_token( $token );
	}

	/**
	 * Whether a token is unrestricted or is being used on its exact resource.
	 *
	 * @param AuthenticatedToken $token Validated token context.
	 */
	private function token_allows_request( AuthenticatedToken $token ): bool {
		$resource = $token->get_resource();
		if ( '' === $resource ) {
			return true;
		}

		$route = self::current_rest_route();
		if ( null === $route ) {
			return false;
		}

		$registered = $this->resources->find_by_route( $route );
		return null !== $registered && hash_equals( untrailingslashit( $resource ), $this->resources->url_for( $registered ) );
	}

	/**
	 * Gets the REST route targeted by the current request.
	 *
	 * Supports pretty, index.php, and plain permalink styles before the REST
	 * server has populated the request object.
	 */
	private static function current_rest_route(): ?string {
		$path = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only compared with registered routes.
		$mark = '/' . rest_get_url_prefix() . '/';
		$at   = strrpos( $path . '/', $mark );
		if ( false !== $at ) {
			return '/' . trim( rawurldecode( substr( $path, $at + strlen( $mark ) ) ), '/' );
		}

		if ( isset( $_GET['rest_route'] ) && is_string( $_GET['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only identifies the request route.
			$route = wp_unslash( $_GET['rest_route'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only compared with registered routes.
			return '/' . trim( $route, '/' );
		}

		return null;
	}

	/**
	 * Whether this is a request Application Passwords may be used on.
	 *
	 * The current user can be looked up before `REST_REQUEST` is defined, so a
	 * request that is clearly headed for the REST API counts as well.
	 */
	private function is_api_request(): bool {
		$is_api_request = ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || self::is_early_rest_request();

		/** This filter is documented in wp-includes/user.php */
		return (bool) apply_filters( 'application_password_is_api_request', $is_api_request ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
	}

	/**
	 * Whether a request is headed for the REST API, judged before WordPress
	 * defines `REST_REQUEST`.
	 *
	 * Only the site's front controller (index.php) serves REST requests, so
	 * AJAX, wp-admin, XML-RPC, and other scripts are ruled out first. A
	 * `rest_route` parameter on admin-ajax.php, or a REST-looking path after
	 * another script such as `/wp-login.php/wp-json/`, does not count.
	 * Pretty, `/index.php/wp-json/`, and plain `?rest_route=` permalinks all
	 * still work.
	 */
	private static function is_early_rest_request(): bool {
		if ( wp_doing_ajax() || is_admin() || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {
			return false;
		}

		$script = isset( $_SERVER['SCRIPT_NAME'] ) ? basename( (string) wp_unslash( $_SERVER['SCRIPT_NAME'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only compared.
		if ( 'index.php' !== $script ) {
			return false;
		}

		if ( isset( $_GET['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only checks presence.
			return true;
		}

		$path = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only compared.

		return false !== strpos( $path . '/', '/' . rest_get_url_prefix() . '/' );
	}

	/**
	 * Gets the bearer token from the request, if it has the access token prefix.
	 *
	 * Bearer tokens in other formats are left for other authentication handlers.
	 */
	public function get_bearer_token(): ?string {
		$header = self::get_authorization_header();
		if ( null === $header || 1 !== preg_match( '/^Bearer\s+(\S+)\s*$/i', $header, $matches ) ) {
			return null;
		}

		return $this->tokens->supports( $matches[1] ) ? $matches[1] : null;
	}

	/**
	 * Gets the Authorization header.
	 *
	 * Some Apache setups only pass it through as `REDIRECT_HTTP_AUTHORIZATION`.
	 */
	public static function get_authorization_header(): ?string {
		foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ) as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				return trim( (string) wp_unslash( $_SERVER[ $key ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Credentials must not be altered.
			}
		}

		if ( function_exists( 'getallheaders' ) ) {
			foreach ( (array) getallheaders() as $name => $value ) {
				if ( 'authorization' === strtolower( (string) $name ) ) {
					return trim( (string) $value );
				}
			}
		}

		return null;
	}
}
