<?php
/**
 * WWW-Authenticate challenge.
 *
 * @package WP\OAuth
 */

declare( strict_types=1 );

namespace WP\OAuth;

use WP\OAuth\Authentication\Bearer;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a Bearer challenge to 401 responses from protected routes.
 *
 * The challenge points clients at the protected resource metadata, which is
 * where OAuth discovery starts (RFC 9728 section 5.1). Error details are only
 * added when a token was sent and rejected (RFC 6750 section 3), as in
 * WP-API/OAuth2.
 */
final class Challenge {

	/**
	 * Protected resource registry.
	 *
	 * @var \WP\OAuth\ProtectedResources
	 */
	private $resources;

	/**
	 * Bearer token authentication.
	 *
	 * @var \WP\OAuth\Authentication\Bearer
	 */
	private $bearer;

	/**
	 * Constructor.
	 *
	 * @param \WP\OAuth\ProtectedResources    $resources Protected resource registry.
	 * @param \WP\OAuth\Authentication\Bearer $bearer    Bearer token authentication.
	 */
	public function __construct( ProtectedResources $resources, Bearer $bearer ) {
		$this->resources = $resources;
		$this->bearer    = $bearer;
	}

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_filter( 'rest_post_dispatch', array( $this, 'add_challenge' ), 10, 3 );
		add_filter( 'rest_exposed_cors_headers', array( $this, 'expose_header' ) );
	}

	/**
	 * Adds the challenge header to 401 responses from protected routes.
	 *
	 * @param mixed            $response Response.
	 * @param \WP_REST_Server  $server   REST server.
	 * @param \WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public function add_challenge( $response, $server, $request ) {
		if ( ! $response instanceof \WP_REST_Response || 401 !== $response->get_status() || ! $request instanceof \WP_REST_Request ) {
			return $response;
		}

		$route = $this->resources->find_by_route( $request->get_route() );
		if ( null === $route || ! Server::is_available() || isset( $response->get_headers()['WWW-Authenticate'] ) ) {
			return $response;
		}

		$params = array();
		$error  = $this->bearer->get_error();
		if ( null !== $error ) {
			$params['error']             = 'invalid_token';
			$params['error_description'] = $error->get_error_message();
		}
		$params['resource_metadata'] = $this->resources->metadata_url( $route );

		$parts = array();
		foreach ( $params as $key => $value ) {
			$parts[] = sprintf( '%s="%s"', $key, addcslashes( $value, '"\\' ) );
		}

		/**
		 * Filters the WWW-Authenticate challenge sent with 401 responses from protected routes.
		 *
		 * @param string                $challenge Header value.
		 * @param array<string, string> $params    Parameters used to build it.
		 * @param string                $route     Protected route.
		 */
		$response->header( 'WWW-Authenticate', apply_filters( 'oauth_server_www_authenticate_challenge', 'Bearer ' . implode( ', ', $parts ), $params, $route ) );

		return $response;
	}

	/**
	 * Lets browser-based clients read the challenge header.
	 *
	 * @param string[] $headers Exposed headers.
	 * @return string[]
	 */
	public function expose_header( $headers ) {
		$headers[] = 'WWW-Authenticate';

		return $headers;
	}
}
