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
 * where OAuth discovery starts (RFC 9728 section 5.1).
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
		if ( null === $route || ! Server::is_available() ) {
			return $response;
		}

		$challenge = sprintf( 'Bearer resource_metadata="%s"', $this->resources->metadata_url( $route ) );
		if ( $this->bearer->has_error() ) {
			$challenge .= ', error="invalid_token"';
		}

		$response->header( 'WWW-Authenticate', $challenge );

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
