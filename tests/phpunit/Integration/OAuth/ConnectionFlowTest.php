<?php
/**
 * Integration tests for the OAuth connection flow.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Integration\OAuth;

use WP\MCP\OAuth\AuthorizeScreen;
use WP\MCP\OAuth\BearerAuth;
use WP\MCP\OAuth\Endpoints;
use WP\MCP\OAuth\Grants;
use WP\MCP\OAuth\Module;
use WP\MCP\OAuth\ProfileConnections;
use WP\MCP\OAuth\Tokens;
use WP\MCP\Tests\TestCase;
use UnexpectedValueException;
use WP_REST_Request;

/**
 * Drives a connection through its public seams: dynamic registration, the consent
 * screen, the token endpoint, bearer authentication and the profile Revoke button.
 */
final class ConnectionFlowTest extends TestCase {

	/**
	 * The return address the test client registers.
	 *
	 * @var string
	 */
	private const REGISTERED_REDIRECT = 'http://127.0.0.1:43210/callback';

	/**
	 * The return address used, on another loopback port as desktop apps do.
	 *
	 * @var string
	 */
	private const REDIRECT = 'http://127.0.0.1:51515/callback';

	/**
	 * A return address on the app's own scheme, as Cursor registers.
	 *
	 * @var string
	 */
	private const APP_REDIRECT = 'cursor://anysphere.cursor-mcp/oauth/callback';

	/**
	 * A PKCE verifier.
	 *
	 * @var string
	 */
	private const VERIFIER = 'verifier-0123456789-0123456789-0123456789-abcdef';

	/**
	 * A client name with characters the slashing meta API would corrupt.
	 *
	 * @var string
	 */
	private const CLIENT_NAME = 'Agent \\ "Desk" O\'Brien';

	/**
	 * The approving user.
	 *
	 * @var int
	 */
	private int $user_id = 0;

	/**
	 * Registers the OAuth REST routes and serves over HTTPS.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = null;
		add_action( 'rest_api_init', array( Endpoints::class, 'routes' ) );
		rest_get_server();

		$_SERVER['HTTPS'] = 'on';
		$this->user_id    = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $this->user_id );
	}

	/**
	 * Restores the request globals.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		remove_action( 'rest_api_init', array( Endpoints::class, 'routes' ) );
		unset( $_SERVER['HTTPS'], $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REQUEST_METHOD'] );
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();

		parent::tear_down();
	}

	/**
	 * A connection logs in as its approver, refreshes without cutting the previous
	 * access token, and is revoked when a rotated refresh token comes back.
	 *
	 * @return void
	 */
	public function test_connection_authenticates_rotates_and_revokes_on_refresh_reuse(): void {
		$client   = $this->register_client();
		$callback = $this->approve( $client, 'state-123' );

		$this->assertSame( 'state-123', $callback['state'] );
		$this->assertSame( Module::issuer(), $callback['iss'] );

		$first = $this->token(
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $callback['code'],
				'code_verifier' => self::VERIFIER,
				'client_id'     => $client,
				'redirect_uri'  => self::REDIRECT,
			)
		);
		$this->assertSame( 200, $first['status'] );
		$this->assertSame( 'Bearer', $first['body']['token_type'] );
		$this->assertSame( 3600, $first['body']['expires_in'] );
		$this->assertSame( $this->user_id, $this->authenticated_user( $first['body']['access_token'] ) );

		$second = $this->token(
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $first['body']['refresh_token'],
				'client_id'     => $client,
			)
		);
		$this->assertSame( 200, $second['status'] );
		$this->assertSame( $this->user_id, $this->authenticated_user( $second['body']['access_token'] ) );
		$this->assertSame( $this->user_id, $this->authenticated_user( $first['body']['access_token'] ), 'A refresh must not cut the previous access token.' );

		$reused = $this->token(
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $first['body']['refresh_token'],
				'client_id'     => $client,
			)
		);
		$this->assertSame( 400, $reused['status'] );
		$this->assertSame( 'invalid_grant', $reused['body']['error'] );
		$this->assertSame( 0, $this->authenticated_user( $second['body']['access_token'] ), 'Refresh token reuse must revoke the connection.' );
	}

	/**
	 * An app that returns through its own scheme, as Cursor does, completes the flow.
	 *
	 * @return void
	 */
	public function test_app_scheme_return_address_completes_the_flow(): void {
		$client   = $this->register_client();
		$callback = $this->approve( $client, 'state-app', self::APP_REDIRECT );

		$tokens = $this->token(
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $callback['code'],
				'code_verifier' => self::VERIFIER,
				'client_id'     => $client,
				'redirect_uri'  => self::APP_REDIRECT,
			)
		);
		$this->assertSame( 200, $tokens['status'] );
		$this->assertSame( $this->user_id, $this->authenticated_user( $tokens['body']['access_token'] ) );
	}

	/**
	 * A wrong PKCE verifier leaves the code usable; redeeming the code twice revokes.
	 *
	 * @return void
	 */
	public function test_code_replay_revokes_but_wrong_verifier_does_not(): void {
		$client   = $this->register_client();
		$callback = $this->approve( $client, '' );
		$redeem   = array(
			'grant_type'    => 'authorization_code',
			'code'          => $callback['code'],
			'code_verifier' => self::VERIFIER,
			'client_id'     => $client,
		);

		$wrong = $this->token( array_merge( $redeem, array( 'code_verifier' => str_repeat( 'x', 43 ) ) ) );
		$this->assertSame( 'invalid_grant', $wrong['body']['error'] );

		$pair = $this->token( $redeem );
		$this->assertSame( 200, $pair['status'] );

		$replay = $this->token( $redeem );
		$this->assertSame( 'invalid_grant', $replay['body']['error'] );
		$this->assertSame( 0, $this->authenticated_user( $pair['body']['access_token'] ) );
	}

	/**
	 * The profile lists the connection and its Revoke button disconnects it.
	 *
	 * @return void
	 */
	public function test_profile_revoke_disconnects(): void {
		$client   = $this->register_client();
		$callback = $this->approve( $client, '' );
		$pair     = $this->token(
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $callback['code'],
				'code_verifier' => self::VERIFIER,
				'client_id'     => $client,
			)
		);

		ob_start();
		ProfileConnections::render( wp_get_current_user() );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( esc_html( self::CLIENT_NAME ), $html );
		$this->assertSame( 1, preg_match( '/<a class="button" href="([^"]+)">Revoke</', $html, $match ) );

		parse_str( (string) wp_parse_url( html_entity_decode( $match[1] ), PHP_URL_QUERY ), $query );
		$_GET     = $query;
		$_REQUEST = $query;
		$location = $this->redirect_of( array( ProfileConnections::class, 'revoke' ) );

		$this->assertStringEndsWith( 'profile.php#mcp-adapter-oauth', $location );
		$this->assertSame( 0, $this->authenticated_user( $pair['body']['access_token'] ) );
	}

	/**
	 * A password rehash at login keeps connections; a new password ends them.
	 *
	 * @return void
	 */
	public function test_password_rehash_keeps_connection_but_new_password_ends_it(): void {
		$client   = $this->register_client();
		$callback = $this->approve( $client, '' );
		$pair     = $this->token(
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $callback['code'],
				'code_verifier' => self::VERIFIER,
				'client_id'     => $client,
			)
		);

		// What core does at login when the hashing cost changes: same plaintext, new hash.
		wp_set_password( 'password', $this->user_id );
		wp_set_current_user( $this->user_id );
		$this->assertSame( $this->user_id, $this->authenticated_user( $pair['body']['access_token'] ) );

		wp_set_password( 'a-new-password', $this->user_id );
		wp_set_current_user( $this->user_id );
		$this->assertSame( 0, $this->authenticated_user( $pair['body']['access_token'] ) );
	}

	/**
	 * The network admin's Remove link (site ID 0 after switching) ends the connections on
	 * that site and leaves the user's connections on the main site.
	 *
	 * @group multisite
	 * @return void
	 */
	public function test_removal_from_a_site_ends_only_that_sites_connections(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Site removal is multisite-only.' );
		}

		$main = Grants::create( wp_get_current_user(), array( 'gen' => 1, 'created' => time(), 'used' => time(), 'pw' => Tokens::password_binding( wp_get_current_user() ) ) );
		$site = self::factory()->blog->create();
		add_user_to_blog( $site, $this->user_id, 'editor' );
		switch_to_blog( $site );
		$other = Grants::create( wp_get_current_user(), array( 'gen' => 1, 'created' => time(), 'used' => time(), 'pw' => Tokens::password_binding( wp_get_current_user() ) ) );
		remove_user_from_blog( $this->user_id );
		$this->assertNull( Grants::find( $this->user_id, (string) $other ) );
		restore_current_blog();

		$this->assertNotNull( Grants::find( $this->user_id, (string) $main ) );
	}

	/**
	 * Registers a public client through the registration endpoint.
	 *
	 * @return string The client ID.
	 */
	private function register_client(): string {
		$request = new WP_REST_Request( 'POST', '/mcp-adapter/v1/oauth/register' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'client_name'                => self::CLIENT_NAME,
					'redirect_uris'              => array( 'javascript://example.com/%0Aalert(1)', self::APP_REDIRECT, self::REGISTERED_REDIRECT ),
					'token_endpoint_auth_method' => 'client_secret_basic',
				)
			)
		);
		$response = rest_do_request( $request );
		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'none', $response->get_data()['token_endpoint_auth_method'] );
		$this->assertSame( array( self::APP_REDIRECT, self::REGISTERED_REDIRECT ), $response->get_data()['redirect_uris'], 'Browser schemes are dropped, not fatal.' );

		return $response->get_data()['client_id'];
	}

	/**
	 * Loads the consent screen, then approves it as the current user.
	 *
	 * @param string $client   The client ID.
	 * @param string $state    The state to send.
	 * @param string $redirect The return address to ask for.
	 * @return array<string, string> The callback's query parameters.
	 */
	private function approve( string $client, string $state, string $redirect = self::REDIRECT ): array {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET                      = wp_slash(
			array(
				'response_type'         => 'code',
				'client_id'             => $client,
				'redirect_uri'          => $redirect,
				'code_challenge'        => rtrim( strtr( base64_encode( hash( 'sha256', self::VERIFIER, true ) ), '+/', '-_' ), '=' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- PKCE challenge.
				'code_challenge_method' => 'S256',
				'state'                 => $state,
				'resource'              => home_url( '/wp-json/mcp/any-server' ),
				'scope'                 => 'general offline_access',
			)
		);
		AuthorizeScreen::load();
		ob_start();
		AuthorizeScreen::render();
		$html = (string) ob_get_clean();

		preg_match_all( '/<input type="hidden"(?: id="[^"]*")? name="([^"]+)" value="([^"]*)"/', $html, $fields, PREG_SET_ORDER );
		$form = array( 'approve' => '1' );
		foreach ( $fields as $field ) {
			$form[ $field[1] ] = html_entity_decode( $field[2], ENT_QUOTES );
		}
		$this->assertArrayHasKey( '_wpnonce', $form, 'The consent form must render.' );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = wp_slash( $form );
		$location                  = $this->redirect_of( array( AuthorizeScreen::class, 'load' ) );

		$this->assertStringStartsWith( $redirect . '?', $location );
		parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $query );

		return $query;
	}

	/**
	 * Runs a handler that ends in a redirect and returns where it redirected.
	 *
	 * @param callable $handler The handler.
	 * @return string The redirect location.
	 */
	private function redirect_of( callable $handler ): string {
		$capture = static function ( string $location ): void {
			throw new UnexpectedValueException( $location ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test capture.
		};
		add_filter( 'wp_redirect', $capture );
		try {
			$handler();
			$this->fail( 'The handler must redirect.' );
		} catch ( UnexpectedValueException $redirect ) {
			return $redirect->getMessage();
		} finally {
			remove_filter( 'wp_redirect', $capture );
		}
	}

	/**
	 * Calls the token endpoint.
	 *
	 * @param array<string, string> $params The form parameters.
	 * @return array{status:int,body:array<string,mixed>} The response.
	 */
	private function token( array $params ): array {
		$request = new WP_REST_Request( 'POST', '/mcp-adapter/v1/oauth/token' );
		$request->set_body_params( $params );
		$response = rest_do_request( $request );

		return array(
			'status' => $response->get_status(),
			'body'   => $response->get_data(),
		);
	}

	/**
	 * The user a bearer token logs a REST request in as.
	 *
	 * @param string $token The access token.
	 * @return int The user ID, or 0 when the token is refused.
	 */
	private function authenticated_user( string $token ): int {
		wp_set_current_user( 0 );
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
		$result                        = BearerAuth::authenticate( null );
		$user_id                       = true === $result ? get_current_user_id() : 0;
		wp_set_current_user( $this->user_id );

		return $user_id;
	}
}
