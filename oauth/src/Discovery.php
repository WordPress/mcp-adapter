<?php
/**
 * Discovery documents.
 *
 * @package WP\OAuth
 */

declare( strict_types=1 );

namespace WP\OAuth;

defined( 'ABSPATH' ) || exit;

/**
 * Serves the RFC 9728 and RFC 8414 metadata documents under `/.well-known/`.
 *
 * Requests are matched on the raw path before WordPress parses them, so no
 * rewrite rules need flushing. Requests only reach WordPress when pretty
 * permalinks are on and the web server passes `/.well-known/` paths through.
 */
final class Discovery {

	private const PROTECTED_RESOURCE   = 'oauth-protected-resource';
	private const AUTHORIZATION_SERVER = 'oauth-authorization-server';

	/**
	 * Protected resource registry.
	 *
	 * @var \WP\OAuth\ProtectedResources
	 */
	private $resources;

	/**
	 * Constructor.
	 *
	 * @param \WP\OAuth\ProtectedResources $resources Protected resource registry.
	 */
	public function __construct( ProtectedResources $resources ) {
		$this->resources = $resources;
	}

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_filter( 'do_parse_request', array( $this, 'maybe_serve' ), 0 );
		add_filter( 'rest_index', array( $this, 'add_to_rest_index' ) );
	}

	/**
	 * Lists the server in the REST API index, as WP-API/OAuth2 does, next to
	 * core's Application Passwords entry.
	 *
	 * @param \WP_REST_Response $response Index response.
	 * @return \WP_REST_Response
	 */
	public function add_to_rest_index( $response ) {
		if ( ! $response instanceof \WP_REST_Response || ! Server::is_available() ) {
			return $response;
		}

		$data                             = $response->get_data();
		$data['authentication']['oauth2'] = array(
			'endpoints' => array(
				'authorization' => Server::authorization_endpoint(),
				'token'         => Server::token_endpoint(),
				'revocation'    => Server::revocation_endpoint(),
			),
			'metadata'  => untrailingslashit( home_url( '/.well-known/oauth-authorization-server' ) ),
		);
		$response->set_data( $data );

		return $response;
	}

	/**
	 * Serves a discovery document if the request is for one.
	 *
	 * @param bool $do_parse Whether WordPress should parse the request.
	 * @return bool Unchanged when the request is not for a discovery document.
	 */
	public function maybe_serve( $do_parse ) {
		$match = $this->match_request();
		if ( null === $match || ! Server::is_available() ) {
			return $do_parse;
		}

		[ $document, $suffix ] = $match;

		header( 'Access-Control-Allow-Origin: *' );
		header( 'Access-Control-Allow-Methods: GET, OPTIONS' );
		header( 'Access-Control-Allow-Headers: MCP-Protocol-Version' );

		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'OPTIONS' === $_SERVER['REQUEST_METHOD'] ) {
			status_header( 204 );
			exit;
		}

		if ( self::AUTHORIZATION_SERVER === $document ) {
			wp_send_json( $this->authorization_server_metadata() );
		}

		$route = '' === $suffix ? ( $this->resources->routes()[0] ?? null ) : $this->resources->find_by_path( $suffix );
		if ( null === $route ) {
			wp_send_json( array( 'error' => 'not_found' ), 404 );
		}

		wp_send_json( $this->protected_resource_metadata( $route ) );

		return $do_parse; // @phpstan-ignore deadCode.unreachable
	}

	/**
	 * Builds the RFC 8414 authorization server metadata.
	 *
	 * @return array<string, mixed>
	 */
	public function authorization_server_metadata(): array {
		$metadata = array(
			'issuer'                                     => Server::issuer(),
			'authorization_endpoint'                     => Server::authorization_endpoint(),
			'token_endpoint'                             => Server::token_endpoint(),
			'revocation_endpoint'                        => Server::revocation_endpoint(),
			'response_types_supported'                   => array( 'code' ),
			'grant_types_supported'                      => array( 'authorization_code' ),
			'code_challenge_methods_supported'           => array( 'S256' ),
			'token_endpoint_auth_methods_supported'      => array( 'none', 'client_secret_post', 'client_secret_basic' ),
			'revocation_endpoint_auth_methods_supported' => array( 'none', 'client_secret_post', 'client_secret_basic' ),
			'authorization_response_iss_parameter_supported' => true,
		);

		/**
		 * Filters the authorization server metadata document.
		 *
		 * @param array<string, mixed> $metadata RFC 8414 metadata.
		 */
		return apply_filters( 'oauth_server_authorization_server_metadata', $metadata );
	}

	/**
	 * Builds the RFC 9728 protected resource metadata for a route.
	 *
	 * @param string $route REST route.
	 * @return array<string, mixed>
	 */
	public function protected_resource_metadata( string $route ): array {
		$metadata = array(
			'resource'                 => $this->resources->url_for( $route ),
			'authorization_servers'    => array( Server::issuer() ),
			'bearer_methods_supported' => array( 'header' ),
			'resource_name'            => get_bloginfo( 'name' ),
		);

		/**
		 * Filters the protected resource metadata document.
		 *
		 * @param array<string, mixed> $metadata RFC 9728 metadata.
		 * @param string               $route    REST route being described.
		 */
		return apply_filters( 'oauth_server_protected_resource_metadata', $metadata, $route );
	}

	/**
	 * Matches the current request against the discovery paths.
	 *
	 * Accepts the document at the origin root, with an optional path suffix as
	 * RFC 8414 and RFC 9728 describe, and under the site's home path.
	 * Tolerates a trailing slash, as WP-API/OAuth2 does, because some hosts
	 * redirect extensionless paths to their trailing-slash form first.
	 *
	 * @return array{0: string, 1: string}|null Document name and path suffix, or null.
	 */
	private function match_request(): ?array {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return null;
		}

		$path = untrailingslashit( (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only compared against fixed paths.
		if ( false === strpos( $path, '/.well-known/oauth-' ) ) {
			return null;
		}

		$home_path = trailingslashit( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) );
		$prefixes  = array_unique( array( '/.well-known/', $home_path . '.well-known/' ) );

		foreach ( $prefixes as $prefix ) {
			foreach ( array( self::PROTECTED_RESOURCE, self::AUTHORIZATION_SERVER ) as $document ) {
				$base = $prefix . $document;
				if ( $path === $base ) {
					return array( $document, '' );
				}
				if ( 0 === strpos( $path, $base . '/' ) ) {
					return array( $document, substr( $path, strlen( $base ) ) );
				}
			}
		}

		return null;
	}
}
