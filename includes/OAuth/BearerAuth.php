<?php
/**
 * Bearer token authentication for the REST API.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\OAuth;

use WP_Error;
use WP_HTTP_Response;
use WP_REST_Request;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Logs a REST request in as the user behind a valid access token, on any route.
 *
 * This works for every MCP server built on the MCP Adapter, whatever its route, and for
 * the rest of the REST API: the token acts as the user, and capabilities decide the rest.
 * It runs on `rest_authentication_errors`, inside the REST server's own dispatch, so the
 * route WordPress actually serves is the one authenticated; nothing is decided from the
 * raw request URL.
 *
 * Every REST 401 also gets a `WWW-Authenticate: Bearer` challenge that points to the
 * protected resource metadata for the requested URL, which is how an MCP client starts
 * discovery. Browsers show a login prompt only for Basic and Digest challenges.
 *
 * @since n.e.x.t
 */
final class BearerAuth {

	/**
	 * Whether this request carried a bearer token that was refused.
	 *
	 * @var bool
	 */
	private static bool $rejected = false;

	/**
	 * Registers the hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		// Before core's Application Password (90) and cookie (100) checks.
		add_filter( 'rest_authentication_errors', array( self::class, 'authenticate' ), 5 );
		add_filter( 'rest_post_dispatch', array( self::class, 'challenge' ), 10, 3 );
	}

	/**
	 * Authenticates a bearer token. Hooked to `rest_authentication_errors`.
	 *
	 * A bearer token this site did not sign is left alone, so another plugin that
	 * authenticates bearer tokens on `determine_current_user`, such as a JWT plugin,
	 * keeps working.
	 *
	 * @param \WP_Error|true|null $result The result so far; anything but null is kept.
	 * @return \WP_Error|true|null True when the token authenticated, a 401 error when one
	 *         of this site's tokens was refused, or the unchanged result otherwise.
	 */
	public static function authenticate( $result ) {
		self::$rejected = false;
		if ( null !== $result ) {
			return $result;
		}

		$token  = self::bearer_token();
		$claims = null === $token ? null : Tokens::verify( Tokens::ACCESS, $token );
		if ( null === $claims ) {
			return $result;
		}

		$user = Module::is_secure_request() ? self::user_for( $claims ) : null;
		if ( null === $user ) {
			self::$rejected = true;

			return new WP_Error( 'invalid_token', __( 'The access token is invalid or has expired.', 'mcp-adapter' ), array( 'status' => 401 ) );
		}

		wp_set_current_user( $user->ID );

		return true;
	}

	/**
	 * Adds the bearer challenge to a 401 response. Hooked to `rest_post_dispatch`.
	 *
	 * @param mixed           $response The response.
	 * @param mixed           $server   The REST server.
	 * @param \WP_REST_Request $request  The request.
	 * @return mixed The response.
	 */
	public static function challenge( $response, $server, $request ) {
		if ( ! $response instanceof WP_HTTP_Response || 401 !== $response->get_status() || ! $request instanceof WP_REST_Request ) {
			return $response;
		}

		$headers = $response->get_headers();
		if ( isset( $headers['WWW-Authenticate'] ) ) {
			return $response;
		}

		$value = sprintf(
			'Bearer resource_metadata="%s", scope="%s"',
			Module::well_known_url( 'oauth-protected-resource', self::resource_for( $request ) ),
			Module::SCOPE
		);
		if ( self::$rejected ) {
			$value .= ', error="invalid_token"';
		}

		$response->header( 'WWW-Authenticate', $value );

		return $response;
	}

	/**
	 * The user a signed access token acts as.
	 *
	 * @param array<string, mixed> $claims The verified access token claims.
	 * @return \WP_User|null The user, or null when the token is not valid now.
	 */
	private static function user_for( array $claims ): ?\WP_User {
		if ( ! is_int( $claims['u'] ?? null ) || ! is_string( $claims['g'] ?? null ) || ! is_int( $claims['x'] ?? null ) || $claims['x'] <= time() ) {
			return null;
		}

		$user = get_userdata( $claims['u'] );
		if ( ! $user instanceof \WP_User || ( is_multisite() && is_user_spammy( $user ) ) ) {
			return null;
		}

		$grant = Grants::find( $user->ID, $claims['g'] );
		if ( null === $grant || (int) ( $grant['data']['gen'] ?? 0 ) < 1 || ! Grants::is_live( $grant['data'], $user ) ) {
			return null;
		}

		$secret = $claims['s'] ?? null;
		if ( ! Tokens::matches( $secret, $grant['data']['access'] ?? null ) && ! Tokens::matches( $secret, $grant['data']['access_prev'] ?? null ) ) {
			return null;
		}

		return $user;
	}

	/**
	 * The bearer token in the Authorization header.
	 *
	 * @return string|null The token, or null when the request carries none.
	 */
	private static function bearer_token(): ?string {
		$header = null;
		foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ) as $key ) {
			if ( isset( $_SERVER[ $key ] ) ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- A credential must stay byte-exact; it is only compared, never output.
				$header = (string) wp_unslash( $_SERVER[ $key ] );
				break;
			}
		}

		if ( null === $header && function_exists( 'getallheaders' ) ) {
			foreach ( (array) getallheaders() as $name => $value ) {
				if ( 0 !== strcasecmp( (string) $name, 'Authorization' ) ) {
					continue;
				}

				$header = (string) $value;
			}
		}

		if ( null === $header || 1 !== preg_match( '/^Bearer\s+(\S+)\s*$/i', $header, $match ) ) {
			return null;
		}

		return $match[1];
	}

	/**
	 * The URL the client requested, for the challenge's metadata link.
	 *
	 * The protected resource metadata must name the exact URL the client used, so the
	 * request path is reused when it is a plain REST path; otherwise the canonical URL
	 * of the matched route is used.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return string The resource URL.
	 */
	private static function resource_for( WP_REST_Request $request ): string {
		$path   = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) : '';
		$prefix = rtrim( (string) wp_parse_url( rest_url(), PHP_URL_PATH ), '/' ) . '/';
		if ( 0 !== strpos( $path, $prefix ) || 1 !== preg_match( '#^[A-Za-z0-9\-._~%!$&\'()*+,;=:@/]+$#', $path ) ) {
			$path = (string) wp_parse_url( rest_url( ltrim( $request->get_route(), '/' ) ), PHP_URL_PATH );
		}

		return Module::origin() . $path;
	}
}
