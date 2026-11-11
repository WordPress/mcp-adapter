<?php
/**
 * The OAuth metadata documents and the authorization endpoint's entry point.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\OAuth;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Answers the three front-end URLs of the authorization server before WordPress routes
 * the request, so no rewrite rules are needed:
 *
 * - `/.well-known/oauth-protected-resource{REST path}`: protected resource metadata
 *   (RFC 9728) for any URL under the REST API, naming that exact URL as the resource,
 *   so whatever MCP route a client connects to passes its identity check.
 * - `/.well-known/oauth-authorization-server/mcp-adapter/oauth`: authorization
 *   server metadata (RFC 8414).
 * - `/mcp-adapter/oauth/authorize`: forwards the request to the consent screen in
 *   wp-admin ({@see AuthorizeScreen}), where WordPress handles login.
 *
 * @since n.e.x.t
 */
final class Discovery {

	/**
	 * Registers the request hook.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'do_parse_request', array( self::class, 'route' ), 0 );
	}

	/**
	 * Serves a metadata document or forwards to the consent screen; otherwise lets
	 * WordPress parse the request as usual. Hooked to `do_parse_request`.
	 *
	 * @param bool $parse Whether WordPress should parse the request.
	 * @return bool The unchanged value when the request is not one of these URLs.
	 */
	public static function route( $parse ) {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		$path   = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) : '';
		if ( ( 'GET' !== $method && 'HEAD' !== $method ) || '' === $path || strlen( $path ) > 2048 ) {
			return $parse;
		}

		if ( Module::ISSUER_PATH . '/authorize' === $path ) {
			self::forward_to_consent();
		}

		if ( (string) wp_parse_url( Module::well_known_url( 'oauth-authorization-server', Module::issuer() ), PHP_URL_PATH ) === $path ) {
			self::send( self::authorization_server(), 'HEAD' !== $method );
		}

		$prefix    = '/.well-known/oauth-protected-resource';
		$rest_path = rtrim( (string) wp_parse_url( rest_url(), PHP_URL_PATH ), '/' );
		if ( 0 === strpos( $path, $prefix . $rest_path . '/' ) ) {
			self::send( self::protected_resource( Module::origin() . substr( $path, strlen( $prefix ) ) ), 'HEAD' !== $method );
		}

		return $parse;
	}

	/**
	 * Protected resource metadata for one URL of the REST API.
	 *
	 * @param string $url The URL the client connects to.
	 * @return array<string, mixed> The document.
	 */
	public static function protected_resource( string $url ): array {
		return array(
			'resource'                 => $url,
			'authorization_servers'    => array( Module::issuer() ),
			'scopes_supported'         => array( Module::SCOPE ),
			'bearer_methods_supported' => array( 'header' ),
		);
	}

	/**
	 * Authorization server metadata.
	 *
	 * `offline_access` is listed because Claude asks for it only when it is advertised;
	 * refresh tokens are issued either way.
	 *
	 * @return array<string, mixed> The document.
	 */
	public static function authorization_server(): array {
		$document = array(
			'issuer'                                => Module::issuer(),
			'authorization_endpoint'                => Module::issuer() . '/authorize',
			'token_endpoint'                        => rest_url( Module::REST_NAMESPACE . '/oauth/token' ),
			'registration_endpoint'                 => rest_url( Module::REST_NAMESPACE . '/oauth/register' ),
			'scopes_supported'                      => array( Module::SCOPE, 'offline_access' ),
			'response_types_supported'              => array( 'code' ),
			'response_modes_supported'              => array( 'query' ),
			'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
			'token_endpoint_auth_methods_supported' => array( 'none' ),
			'code_challenge_methods_supported'      => array( 'S256' ),
			'authorization_response_iss_parameter_supported' => true,
		);
		if ( Clients::can_fetch_metadata() ) {
			$document['client_id_metadata_document_supported'] = true;
		}

		return $document;
	}

	/**
	 * Redirects to the consent screen with the authorization request's parameters.
	 *
	 * @return never
	 */
	private static function forward_to_consent(): void {
		$query = array();
		if ( isset( $_SERVER['QUERY_STRING'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Protocol values are validated on the consent screen; sanitizing would alter them.
			wp_parse_str( wp_unslash( $_SERVER['QUERY_STRING'] ), $query );
		}
		unset( $query['page'] );

		$target = add_query_arg( 'page', AuthorizeScreen::SLUG, admin_url( 'admin.php' ) );
		if ( array() !== $query ) {
			$target .= '&' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
		}

		nocache_headers();
		wp_safe_redirect( $target );
		exit;
	}

	/**
	 * Sends a JSON document and ends the request.
	 *
	 * @param array<string, mixed> $document  The document.
	 * @param bool                 $with_body False for a HEAD request.
	 * @return never
	 */
	private static function send( array $document, bool $with_body ): void {
		status_header( 200 );
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Cache-Control: no-store' );
		header( 'X-Content-Type-Options: nosniff' );
		if ( $with_body ) {
			echo wp_json_encode( $document, JSON_UNESCAPED_SLASHES );
		}
		exit;
	}
}
