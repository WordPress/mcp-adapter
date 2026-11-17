<?php
/**
 * Protected resource registry.
 *
 * @package WP\OAuth
 */

declare( strict_types=1 );

namespace WP\OAuth;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the REST routes that OAuth clients are told to authorize for.
 *
 * Other code declares resources through the `oauth_server_protected_resources`
 * filter, so this module needs no knowledge of what the routes do.
 */
final class ProtectedResources {

	/**
	 * Gets the registered REST routes, such as `/mcp/mcp-adapter-default-server`.
	 *
	 * @return string[]
	 */
	public function routes(): array {
		/**
		 * Filters the REST routes protected by the OAuth server.
		 *
		 * These routes are listed in the protected resource metadata, accepted as
		 * the `resource` parameter, and get a `WWW-Authenticate` challenge on 401.
		 *
		 * @param string[] $routes REST routes, for example `/my-namespace/my-route`.
		 */
		$routes = apply_filters( 'oauth_server_protected_resources', array() );
		if ( ! is_array( $routes ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $routes as $route ) {
			if ( ! is_string( $route ) || '' === trim( $route, '/' ) ) {
				continue;
			}
			$normalized[] = '/' . trim( $route, '/' );
		}

		return array_values( array_unique( $normalized ) );
	}

	/**
	 * Gets the resource URL for a route.
	 *
	 * @param string $route REST route.
	 */
	public function url_for( string $route ): string {
		return untrailingslashit( rest_url( ltrim( $route, '/' ) ) );
	}

	/**
	 * Finds the route whose resource URL matches.
	 *
	 * @param string $url Resource URL supplied by a client.
	 * @return string|null The route, or null if no resource matches.
	 */
	public function find_by_url( string $url ): ?string {
		$url = untrailingslashit( $url );
		foreach ( $this->routes() as $route ) {
			if ( $this->url_for( $route ) === $url ) {
				return $route;
			}
		}

		return null;
	}

	/**
	 * Finds the registered route a REST request was made to.
	 *
	 * @param string $request_route Route from the REST request.
	 * @return string|null The route, or null if it is not protected.
	 */
	public function find_by_route( string $request_route ): ?string {
		$request_route = '/' . trim( $request_route, '/' );
		foreach ( $this->routes() as $route ) {
			if ( $route === $request_route ) {
				return $route;
			}
		}

		return null;
	}

	/**
	 * Finds the route whose resource URL has the given path.
	 *
	 * @param string $path URL path, such as `/wp-json/mcp/mcp-adapter-default-server`.
	 * @return string|null The route, or null if no resource matches.
	 */
	public function find_by_path( string $path ): ?string {
		$path = untrailingslashit( $path );
		foreach ( $this->routes() as $route ) {
			if ( untrailingslashit( (string) wp_parse_url( $this->url_for( $route ), PHP_URL_PATH ) ) === $path ) {
				return $route;
			}
		}

		return null;
	}

	/**
	 * Gets the RFC 9728 metadata URL for a route.
	 *
	 * The well-known segment goes between the host and the resource path.
	 *
	 * @param string $route REST route.
	 */
	public function metadata_url( string $route ): string {
		$parts = wp_parse_url( $this->url_for( $route ) );
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) ) {
			return untrailingslashit( home_url( '/.well-known/oauth-protected-resource' ) );
		}

		$origin = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		$path   = isset( $parts['query'] ) ? '' : ( $parts['path'] ?? '' );

		return $origin . '/.well-known/oauth-protected-resource' . untrailingslashit( $path );
	}
}
