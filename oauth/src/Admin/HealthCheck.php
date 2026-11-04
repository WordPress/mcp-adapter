<?php
/**
 * Site Health check for the discovery documents.
 *
 * @package WP\OAuth
 */

declare( strict_types=1 );

namespace WP\OAuth\Admin;

use WP\OAuth\Server;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Checks that the discovery documents can be fetched, adapted from wp-media/mcp-oauth.
 *
 * Some hosts route the whole /.well-known/ prefix to a directory used for
 * certificate renewal, so these requests never reach WordPress. That shows up
 * as a bare 404 without WordPress's headers.
 */
final class HealthCheck {

	private const TEST       = 'oauth_server_discovery';
	private const CACHE_KEY  = 'oauth_server_health';
	private const CACHE_TIME = 5 * MINUTE_IN_SECONDS;

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_filter( 'site_status_tests', array( $this, 'add_test' ) );
	}

	/**
	 * Adds the test.
	 *
	 * @param array<string, mixed> $tests Site Health tests.
	 * @return array<string, mixed>
	 */
	public function add_test( $tests ) {
		$tests['direct'][ self::TEST ] = array(
			'label' => __( 'OAuth discovery documents', 'mcp-adapter' ),
			'test'  => array( $this, 'run' ),
		);

		return $tests;
	}

	/**
	 * Runs the test.
	 *
	 * @return array<string, mixed> Site Health result.
	 */
	public function run(): array {
		if ( ! Server::is_available() ) {
			return $this->result( 'good', __( 'OAuth sign-in is off because Application Passwords are not available, so the discovery documents are not expected to load.', 'mcp-adapter' ) );
		}

		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			return $this->result( 'recommended', __( 'The OAuth discovery documents under /.well-known/ need pretty permalinks. Choose a permalink structure under Settings > Permalinks.', 'mcp-adapter' ) );
		}

		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$failing     = array();
		$intercepted = false;
		foreach ( array( '/.well-known/oauth-authorization-server', '/.well-known/oauth-protected-resource' ) as $path ) {
			$response = wp_safe_remote_get( home_url( $path ), array( 'timeout' => 3 ) );
			$status   = (int) wp_remote_retrieve_response_code( $response );

			if ( ! is_wp_error( $response ) && 200 === $status && is_array( json_decode( wp_remote_retrieve_body( $response ), true ) ) ) {
				continue;
			}

			$failing[] = $path;

			// A 404 without WordPress's headers means the web server answered before WordPress ran.
			if ( 404 !== $status || self::has_header( $response, 'x-powered-by' ) || self::has_header( $response, 'link' ) ) {
				continue;
			}

			$intercepted = true;
		}

		if ( empty( $failing ) ) {
			$result = $this->result( 'good', __( 'Both OAuth discovery documents loaded.', 'mcp-adapter' ) );
		} elseif ( $intercepted ) {
			$result = $this->result(
				'recommended',
				sprintf(
					/* translators: %s: list of paths. */
					__( 'These paths returned a 404 from the web server before WordPress ran: %s. Hosts often route all of /.well-known/ to a folder used for certificate renewal. Ask your host to pass these two paths to WordPress; the README in the plugin’s oauth folder has Apache and Nginx examples.', 'mcp-adapter' ),
					implode( ', ', $failing )
				)
			);
		} else {
			$result = $this->result(
				'recommended',
				sprintf(
					/* translators: %s: list of paths. */
					__( 'These OAuth discovery documents could not be loaded: %s. Applications will not be able to find this site’s sign-in.', 'mcp-adapter' ),
					implode( ', ', $failing )
				)
			);
		}

		set_transient( self::CACHE_KEY, $result, self::CACHE_TIME );

		return $result;
	}

	/**
	 * Builds a Site Health result.
	 *
	 * @param array<string, mixed>|WP_Error $response HTTP response.
	 * @param string                         $name     Header name.
	 */
	private static function has_header( $response, string $name ): bool {
		$value = wp_remote_retrieve_header( $response, $name );

		return is_array( $value ) ? ! empty( $value ) : '' !== $value;
	}

	/**
	 * Builds a Site Health result.
	 *
	 * @param string $status      'good' or 'recommended'.
	 * @param string $description Description.
	 * @return array<string, mixed>
	 */
	private function result( string $status, string $description ): array {
		return array(
			'label'       => 'good' === $status
				? __( 'OAuth discovery documents are reachable', 'mcp-adapter' )
				: __( 'OAuth discovery documents may not be reachable', 'mcp-adapter' ),
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'Security', 'mcp-adapter' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html( $description ) . '</p>',
			'actions'     => '<p>' . esc_html__( 'This check runs from the server to itself. A CDN or firewall in front of the site can still block these paths for outside visitors.', 'mcp-adapter' ) . '</p>',
			'test'        => self::TEST,
		);
	}
}
