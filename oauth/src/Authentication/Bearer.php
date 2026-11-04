<?php
/**
 * Bearer token authentication.
 *
 * @package WP\OAuth
 */

declare( strict_types=1 );

namespace WP\OAuth\Authentication;

use WP\OAuth\Clients\ClientRepository;
use WP\OAuth\Server;
use WP\OAuth\Tokens\AccessToken;
use WP_Application_Passwords;
use WP_Error;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Authenticates REST requests that carry an OAuth access token.
 */
final class Bearer {

	/**
	 * Client registry.
	 *
	 * @var \WP\OAuth\Clients\ClientRepository
	 */
	private $clients;

	/**
	 * Error from the last attempt, if it failed.
	 *
	 * @var \WP_Error|null
	 */
	private $error = null;

	/**
	 * Whether the last attempt succeeded.
	 *
	 * @var bool
	 */
	private $authenticated = false;

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
		if ( ! empty( $user_id ) ) {
			return $user_id;
		}

		$token = self::get_bearer_token();
		if ( null === $token || ! $this->is_api_request() ) {
			return $user_id;
		}

		$this->error         = null;
		$this->authenticated = false;

		$result = $this->validate( $token );
		if ( is_wp_error( $result ) ) {
			$this->error = $result;

			return $user_id;
		}

		[ $user, $item ] = $result;

		WP_Application_Passwords::record_application_password_usage( $user->ID, $item['uuid'] );
		$this->authenticated = true;

		/** This action is documented in wp-includes/user.php */
		do_action( 'application_password_did_authenticate', $user, $item ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook, so Application Password integrations see OAuth logins too.

		return $user->ID;
	}

	/**
	 * Reports the result to the REST API.
	 *
	 * @param \WP_Error|true|null $result Result from earlier authentication methods.
	 * @return \WP_Error|true|null
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
	 * @param \WP_Error            $error Errors so far.
	 * @param \WP_User             $user  User being authenticated.
	 * @param array<string, mixed> $item  Application Password details.
	 */
	public function block_basic_auth( $error, $user, $item ): void {
		if ( ! $error instanceof WP_Error || ! is_array( $item ) || empty( $item['app_id'] ) ) {
			return;
		}

		if ( null === $this->clients->get( (string) $item['app_id'] ) ) {
			return;
		}

		$error->add( 'oauth_server_bearer_only', __( 'This application password was issued through OAuth and only works as a bearer token.', 'mcp-adapter' ) );
	}

	/**
	 * Validates a token.
	 *
	 * @param string $token Token.
	 * @return array{0: \WP_User, 1: array<string, mixed>}|\WP_Error The user and Application Password, or an error.
	 */
	private function validate( string $token ) {
		$invalid = new WP_Error(
			'oauth_server_invalid_token',
			__( 'The access token is invalid or has been revoked.', 'mcp-adapter' ),
			array( 'status' => 401 )
		);

		$parts = AccessToken::decode( $token );
		if ( null === $parts || ! Server::is_available() ) {
			return $invalid;
		}

		$user = get_userdata( $parts['user_id'] );
		if ( ! $user instanceof WP_User || ! wp_is_application_passwords_available_for_user( $user ) ) {
			return $invalid;
		}

		$item = WP_Application_Passwords::get_user_application_password( $user->ID, $parts['uuid'] );
		if ( ! is_array( $item ) || ! WP_Application_Passwords::check_password( $parts['password'], $item['password'] ) ) {
			return $invalid;
		}

		if ( null === $this->clients->get( (string) $item['app_id'] ) ) {
			return $invalid;
		}

		return array( $user, $item );
	}

	/**
	 * Whether this is a request Application Passwords may be used on.
	 *
	 * The current user can be looked up before `REST_REQUEST` is defined, so the
	 * request path is checked as well.
	 */
	private function is_api_request(): bool {
		$is_api_request = defined( 'REST_REQUEST' ) && REST_REQUEST;

		if ( ! $is_api_request ) {
			$path   = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only compared.
			$prefix = '/' . rest_get_url_prefix() . '/';

			$is_api_request = false !== strpos( $path . '/', $prefix ) || isset( $_GET['rest_route'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only checks presence.
		}

		/** This filter is documented in wp-includes/user.php */
		return (bool) apply_filters( 'application_password_is_api_request', $is_api_request ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
	}

	/**
	 * Gets the bearer token from the request, if it has the access token prefix.
	 *
	 * Bearer tokens in other formats are left for other authentication handlers.
	 */
	public static function get_bearer_token(): ?string {
		$header = self::get_authorization_header();
		if ( null === $header || 1 !== preg_match( '/^Bearer\s+(\S+)\s*$/i', $header, $matches ) ) {
			return null;
		}

		return AccessToken::has_prefix( $matches[1] ) ? $matches[1] : null;
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
