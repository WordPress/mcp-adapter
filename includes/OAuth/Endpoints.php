<?php
/**
 * The token and client registration endpoints.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\OAuth;

use WP_REST_Request;
use WP_REST_Response;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * REST routes for the token endpoint and dynamic client registration.
 *
 * Both are public, as OAuth requires, and answer in OAuth's JSON error format rather
 * than WordPress's. Core's REST handling supplies body parsing and CORS.
 *
 * Authorization codes are single use. Refresh tokens rotate on every use; presenting an
 * already rotated refresh token, or redeeming a code twice, deletes the connection,
 * because it means a token was copied (OAuth 2.1 section 4.3.1). A refresh does not cut
 * the previous access token, so requests in flight during a refresh still succeed.
 *
 * @since n.e.x.t
 */
final class Endpoints {

	/**
	 * Registers the route hook.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
	}

	/**
	 * Registers the routes. Hooked to `rest_api_init`.
	 *
	 * @return void
	 */
	public static function routes(): void {
		register_rest_route(
			Module::REST_NAMESPACE,
			'/oauth/token',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'token' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			Module::REST_NAMESPACE,
			'/oauth/register',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'register_client' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * The token endpoint.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response The token response or an OAuth error.
	 */
	public static function token( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;

		if ( ! Module::is_secure_request() ) {
			return self::error( 'invalid_request', __( 'Token requests must use HTTPS.', 'mcp-adapter' ) );
		}

		// Database drop-ins such as HyperDB and LudicrousDB read from replicas that can lag
		// behind the primary. A code redeemed just after approval, or a refresh just after
		// the previous one, must be checked against the row as last written.
		if ( $wpdb instanceof \wpdb && method_exists( $wpdb, 'send_reads_to_masters' ) ) {
			$wpdb->send_reads_to_masters();
		}

		$params = $request->get_body_params();
		if ( array() === $params ) {
			$params = (array) $request->get_json_params();
		}

		// Public clients only (OAuth 2.1 section 2.1): any attempt to authenticate is refused.
		if ( 0 === stripos( (string) $request->get_header( 'authorization' ), 'basic ' ) ) {
			$response = self::error( 'invalid_client', __( 'This server accepts only public clients, without a client secret.', 'mcp-adapter' ), 401 );
			$response->header( 'WWW-Authenticate', 'Basic realm="' . Module::issuer() . '"' );

			return $response;
		}

		if ( isset( $params['client_secret'] ) || isset( $params['client_assertion'] ) ) {
			return self::error( 'invalid_client', __( 'This server accepts only public clients, without a client secret.', 'mcp-adapter' ) );
		}

		// A registration signed before an auth salt change no longer verifies. invalid_client
		// tells the app to register again; invalid_grant would only make it retry sign-in.
		$client_id = $params['client_id'] ?? null;
		if ( is_string( $client_id ) && 0 === strpos( $client_id, Clients::DCR_PREFIX ) && is_wp_error( Clients::resolve( $client_id ) ) ) {
			return self::error( 'invalid_client', __( 'This app registration is no longer valid. Register the app again.', 'mcp-adapter' ) );
		}

		if ( isset( $params['resource'] ) && ( ! is_string( $params['resource'] ) || ! Module::accepts_resource( $params['resource'] ) ) ) {
			return self::error( 'invalid_target', __( 'The requested resource is not on this site.', 'mcp-adapter' ) );
		}

		$grant_type = $params['grant_type'] ?? '';
		if ( 'authorization_code' === $grant_type ) {
			return self::redeem_code( $params );
		}

		if ( 'refresh_token' === $grant_type ) {
			return self::refresh( $params );
		}

		return self::error( 'unsupported_grant_type', __( 'Use the authorization_code or refresh_token grant type.', 'mcp-adapter' ) );
	}

	/**
	 * The dynamic client registration endpoint (RFC 7591).
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response The registration or an RFC 7591 error.
	 */
	public static function register_client( WP_REST_Request $request ): WP_REST_Response {
		if ( ! Module::is_secure_request() ) {
			return self::error( 'invalid_client_metadata', __( 'Registration requests must use HTTPS.', 'mcp-adapter' ) );
		}

		$metadata = $request->get_json_params();
		if ( ! is_array( $metadata ) ) {
			return self::error( 'invalid_client_metadata', __( 'Send the client metadata as a JSON object.', 'mcp-adapter' ) );
		}

		$client = Clients::register( $metadata );
		if ( is_wp_error( $client ) ) {
			return self::error( (string) $client->get_error_code(), $client->get_error_message() );
		}

		return self::no_store( new WP_REST_Response( $client, 201 ) );
	}

	/**
	 * Redeems an authorization code.
	 *
	 * The code, its client, its return address and the PKCE verifier are all checked
	 * before a second redemption is treated as theft, so someone without the verifier
	 * cannot revoke a connection.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 * @return \WP_REST_Response The token response or an OAuth error.
	 */
	private static function redeem_code( array $params ): WP_REST_Response {
		$claims = Tokens::verify( Tokens::CODE, (string) ( $params['code'] ?? '' ) );
		if ( null === $claims || ! is_int( $claims['x'] ?? null ) || $claims['x'] <= time() ) {
			return self::invalid_grant( __( 'The authorization code is invalid or has expired.', 'mcp-adapter' ) );
		}

		$located = self::locate( $claims );
		if ( null === $located ) {
			return self::invalid_grant( __( 'The authorization code is invalid or has expired.', 'mcp-adapter' ) );
		}

		[ $user, $id, $grant ] = $located;
		$data                  = $grant['data'];
		$redirect              = $params['redirect_uri'] ?? null;
		if ( ( $params['client_id'] ?? null ) !== $data['client_id']
			|| ( null !== $redirect && $redirect !== $data['redirect_uri'] )
			|| ! Tokens::matches( $claims['s'] ?? null, $data['code'] ?? null )
			|| ! self::verifier_matches( $params['code_verifier'] ?? null, (string) ( $data['challenge'] ?? '' ) )
		) {
			return self::invalid_grant( __( 'The authorization code does not match this request.', 'mcp-adapter' ) );
		}

		if ( 0 !== (int) ( $data['gen'] ?? -1 ) ) {
			Grants::delete( $user->ID, $id );

			return self::invalid_grant( __( 'The authorization code was already used, so the connection was revoked.', 'mcp-adapter' ) );
		}

		if ( ! Grants::is_live( $data, $user ) ) {
			return self::invalid_grant( __( 'The authorization code is invalid or has expired.', 'mcp-adapter' ) );
		}

		$access          = Tokens::secret();
		$refresh         = Tokens::secret();
		$data['gen']     = 1;
		$data['access']  = Tokens::digest( $access );
		$data['refresh'] = Tokens::digest( $refresh );
		$data['used']    = time();
		if ( ! Grants::replace( $user->ID, $grant, $data ) ) {
			// Another request redeemed the same code first.
			Grants::delete( $user->ID, $id );

			return self::invalid_grant( __( 'The authorization code was already used, so the connection was revoked.', 'mcp-adapter' ) );
		}

		return self::issue( $user->ID, $id, 1, $access, $refresh );
	}

	/**
	 * Rotates a refresh token.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 * @return \WP_REST_Response The token response or an OAuth error.
	 */
	private static function refresh( array $params ): WP_REST_Response {
		$claims  = Tokens::verify( Tokens::REFRESH, (string) ( $params['refresh_token'] ?? '' ) );
		$located = null === $claims ? null : self::locate( $claims );
		if ( null === $claims || null === $located || ! is_int( $claims['n'] ?? null ) ) {
			return self::invalid_grant( __( 'The refresh token is invalid.', 'mcp-adapter' ) );
		}

		[ $user, $id, $grant ] = $located;
		$data                  = $grant['data'];
		$generation            = (int) ( $data['gen'] ?? 0 );
		if ( isset( $params['client_id'] ) && $params['client_id'] !== $data['client_id'] ) {
			return self::invalid_grant( __( 'The refresh token was issued to another client.', 'mcp-adapter' ) );
		}

		if ( $generation < 1 || ! Grants::is_live( $data, $user ) ) {
			Grants::delete( $user->ID, $id );

			return self::invalid_grant( __( 'The connection has expired. Connect the app again.', 'mcp-adapter' ) );
		}

		if ( $claims['n'] < $generation ) {
			Grants::delete( $user->ID, $id );

			return self::invalid_grant( __( 'The refresh token was already used, so the connection was revoked.', 'mcp-adapter' ) );
		}

		if ( $claims['n'] !== $generation || ! Tokens::matches( $claims['s'] ?? null, $data['refresh'] ?? null ) ) {
			return self::invalid_grant( __( 'The refresh token is invalid.', 'mcp-adapter' ) );
		}

		$access              = Tokens::secret();
		$refresh             = Tokens::secret();
		$data['gen']         = $generation + 1;
		$data['access_prev'] = $data['access'] ?? '';
		$data['access']      = Tokens::digest( $access );
		$data['refresh']     = Tokens::digest( $refresh );
		$data['used']        = time();
		if ( ! Grants::replace( $user->ID, $grant, $data ) ) {
			// Another request rotated the same refresh token first.
			Grants::delete( $user->ID, $id );

			return self::invalid_grant( __( 'The refresh token was already used, so the connection was revoked.', 'mcp-adapter' ) );
		}

		return self::issue( $user->ID, $id, $generation + 1, $access, $refresh );
	}

	/**
	 * Finds the user and connection a code or refresh token points to.
	 *
	 * @param array<string, mixed> $claims The token claims.
	 * @return array{0:\WP_User,1:string,2:array{row:int,raw:string,data:array<string,mixed>}}|null
	 *         The user, the connection ID and the stored row; null when either is gone.
	 */
	private static function locate( array $claims ): ?array {
		if ( ! is_int( $claims['u'] ?? null ) || ! is_string( $claims['g'] ?? null ) ) {
			return null;
		}

		$user = get_userdata( $claims['u'] );
		if ( ! $user instanceof \WP_User ) {
			return null;
		}

		$grant = Grants::find( $user->ID, $claims['g'] );
		if ( null === $grant || ! is_string( $grant['data']['client_id'] ?? null ) ) {
			return null;
		}

		return array( $user, $claims['g'], $grant );
	}

	/**
	 * Issues an access token and a refresh token.
	 *
	 * @param int    $user_id    The user ID.
	 * @param string $id         The connection ID.
	 * @param int    $generation The refresh token's generation.
	 * @param string $access     The access token secret.
	 * @param string $refresh    The refresh token secret.
	 * @return \WP_REST_Response The token response.
	 */
	private static function issue( int $user_id, string $id, int $generation, string $access, string $refresh ): WP_REST_Response {
		$ttl = Module::access_token_ttl();

		return self::no_store(
			new WP_REST_Response(
				array(
					'access_token'  => Tokens::sign(
						Tokens::ACCESS,
						array(
							'u' => $user_id,
							'g' => $id,
							'x' => time() + $ttl,
							's' => $access,
						)
					),
					'token_type'    => 'Bearer',
					'expires_in'    => $ttl,
					'refresh_token' => Tokens::sign(
						Tokens::REFRESH,
						array(
							'u' => $user_id,
							'g' => $id,
							'n' => $generation,
							's' => $refresh,
						)
					),
				),
				200
			)
		);
	}

	/**
	 * Whether a PKCE verifier matches an S256 challenge (RFC 7636).
	 *
	 * @param mixed  $verifier  The `code_verifier` parameter.
	 * @param string $challenge The stored challenge.
	 * @return bool True on a match.
	 */
	private static function verifier_matches( $verifier, string $challenge ): bool {
		return is_string( $verifier )
			&& 1 === preg_match( '/^[A-Za-z0-9\-._~]{43,128}$/', $verifier )
			&& hash_equals( $challenge, Tokens::encode( hash( 'sha256', $verifier, true ) ) );
	}

	/**
	 * An `invalid_grant` error.
	 *
	 * @param string $description What went wrong.
	 * @return \WP_REST_Response The error.
	 */
	private static function invalid_grant( string $description ): WP_REST_Response {
		return self::error( 'invalid_grant', $description );
	}

	/**
	 * An OAuth error response.
	 *
	 * @param string $code        The OAuth error code.
	 * @param string $description What went wrong.
	 * @param int    $status      HTTP status.
	 * @return \WP_REST_Response The error.
	 */
	private static function error( string $code, string $description, int $status = 400 ): WP_REST_Response {
		return self::no_store(
			new WP_REST_Response(
				array(
					'error'             => $code,
					'error_description' => $description,
				),
				$status
			)
		);
	}

	/**
	 * Marks a response as never cacheable, as OAuth requires for tokens.
	 *
	 * @param \WP_REST_Response $response The response.
	 * @return \WP_REST_Response The same response.
	 */
	private static function no_store( WP_REST_Response $response ): WP_REST_Response {
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'Pragma', 'no-cache' );

		return $response;
	}
}
