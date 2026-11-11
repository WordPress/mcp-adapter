<?php
/**
 * Integration tests for the bearer challenge.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Integration\OAuth;

use WP\MCP\OAuth\BearerAuth;
use WP\MCP\Tests\TestCase;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Every REST 401 must point the client to the metadata for the URL it requested, which
 * is how an MCP client on any adapter route discovers the authorization server.
 */
final class BearerAuthTest extends TestCase {

	/**
	 * The request URI before the test.
	 *
	 * @var string|null
	 */
	private ?string $request_uri = null;

	/**
	 * Uses pretty permalinks, which OAuth requires.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
		$this->request_uri = $_SERVER['REQUEST_URI'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Saved for restore.
	}

	/**
	 * Restores the request URI.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$_SERVER['REQUEST_URI'] = $this->request_uri;
		parent::tear_down();
	}

	/**
	 * A bearer token this site did not sign, such as another plugin's JWT, is left for
	 * that plugin to authenticate.
	 *
	 * @return void
	 */
	public function test_foreign_bearer_token_is_left_to_other_handlers(): void {
		$_SERVER['HTTPS']              = 'on';
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.c2lnbmF0dXJl';

		$result = BearerAuth::authenticate( null );
		unset( $_SERVER['HTTPS'], $_SERVER['HTTP_AUTHORIZATION'] );

		$this->assertNull( $result );
	}

	/**
	 * A 401 on an arbitrary MCP route carries a challenge naming that route's metadata.
	 *
	 * @return void
	 */
	public function test_unauthorized_response_points_to_the_requested_routes_metadata(): void {
		$_SERVER['REQUEST_URI'] = '/wp-json/mcp/some-other-server';

		// The request carries no token, so authentication leaves it anonymous.
		$this->assertNull( BearerAuth::authenticate( null ) );
		$response = BearerAuth::challenge(
			new WP_REST_Response( null, 401 ),
			rest_get_server(),
			new WP_REST_Request( 'POST', '/mcp/some-other-server' )
		);

		$this->assertSame(
			'Bearer resource_metadata="' . untrailingslashit( home_url() ) . '/.well-known/oauth-protected-resource/wp-json/mcp/some-other-server", scope="general"',
			$response->get_headers()['WWW-Authenticate']
		);
	}
}
