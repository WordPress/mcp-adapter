<?php
/**
 * Tests for the OAuth server's code exchange and bearer authentication.
 *
 * @package WP\OAuth\Tests
 */

declare( strict_types=1 );

namespace WP\OAuth\Tests;

use WP\OAuth\Clients\Client;
use WP\OAuth\Clients\ClientRepository;
use WP\OAuth\Endpoints\Token;
use WP\OAuth\Server;
use WP\OAuth\Tokens\AccessToken;
use WP_Application_Passwords;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;
use WP_User;

/**
 * Covers the token endpoint, bearer authentication, and revocation.
 */
final class FlowTest extends WP_UnitTestCase {

	private const VERIFIER     = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
	private const REDIRECT_URI = 'https://client.example/callback';

	/**
	 * @var Server
	 */
	private $server;

	/**
	 * @var WP_User
	 */
	private $user;

	/**
	 * @var Client
	 */
	private $client;

	public function set_up(): void {
		parent::set_up();

		add_filter( 'wp_is_application_passwords_available', '__return_true' );
		$_SERVER['REQUEST_URI'] = '/wp-json/mcp/mcp-adapter-default-server';

		$server = Server::instance();
		$this->assertNotNull( $server, 'The OAuth server should start with the plugin.' );
		$this->server = $server;

		$this->user = self::factory()->user->create_and_get( array( 'role' => 'editor' ) );

		$created = $this->server->clients()->create( 'Test Client', array( self::REDIRECT_URI ), false );
		$this->assertIsArray( $created );
		$this->client = $created[0];
	}

	public function tear_down(): void {
		unset( $_SERVER['HTTP_AUTHORIZATION'] );
		parent::tear_down();
	}

	public function test_code_exchange_issues_token_that_authenticates_until_revoked(): void {
		$response = $this->exchange( $this->issue_code(), self::VERIFIER );

		$this->assertSame( 200, $response->get_status() );
		$token = $response->get_data()['access_token'];
		$this->assertStringStartsWith( AccessToken::PREFIX, $token );

		$passwords = WP_Application_Passwords::get_user_application_passwords( $this->user->ID );
		$this->assertCount( 1, $passwords );
		$this->assertSame( $this->client->get_id(), $passwords[0]['app_id'] );

		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
		$this->assertSame( $this->user->ID, $this->server->bearer()->authenticate( false ) );
		$this->assertNotNull( WP_Application_Passwords::get_user_application_password( $this->user->ID, $passwords[0]['uuid'] )['last_used'] );

		WP_Application_Passwords::delete_application_password( $this->user->ID, $passwords[0]['uuid'] );
		$this->assertFalse( $this->server->bearer()->authenticate( false ) );
		$this->assertTrue( $this->server->bearer()->has_error() );
	}

	public function test_failed_pkce_check_burns_the_code(): void {
		$code = $this->issue_code();

		$wrong = $this->exchange( $code, str_repeat( 'x', 43 ) );
		$this->assertSame( 400, $wrong->get_status() );
		$this->assertSame( 'invalid_grant', $wrong->get_data()['error'] );

		$retry = $this->exchange( $code, self::VERIFIER );
		$this->assertSame( 'invalid_grant', $retry->get_data()['error'] );
		$this->assertEmpty( WP_Application_Passwords::get_user_application_passwords( $this->user->ID ) );
	}

	public function test_bearer_token_sets_the_current_user_on_a_fresh_request(): void {
		$token = $this->exchange( $this->issue_code(), self::VERIFIER )->get_data()['access_token'];

		// Start as a real request does: WordPress looks up the current user
		// before init, so the client post type is not registered yet, and
		// nothing has set the current user.
		unregister_post_type( ClientRepository::POST_TYPE );
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
		$GLOBALS['current_user']       = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulates a request before the current user is set.

		try {
			$this->assertSame( $this->user->ID, get_current_user_id() );
		} finally {
			$this->server->clients()->register_post_type();
		}
	}

	public function test_oauth_passwords_are_refused_for_basic_auth(): void {
		$token = $this->exchange( $this->issue_code(), self::VERIFIER )->get_data()['access_token'];
		$parts = AccessToken::decode( $token );
		$this->assertNotNull( $parts );

		add_filter( 'application_password_is_api_request', '__return_true' );
		$result = wp_authenticate_application_password( null, $this->user->user_login, $parts['password'] );

		$this->assertWPError( $result );
		$this->assertContains( 'oauth_server_bearer_only', $result->get_error_codes() );
	}

	public function test_revocation_deletes_the_connection_and_always_succeeds(): void {
		$token = $this->exchange( $this->issue_code(), self::VERIFIER )->get_data()['access_token'];

		$request = new WP_REST_Request( 'POST', '/oauth/v1/revoke' );
		$request->set_body_params(
			array(
				'token'     => $token,
				'client_id' => $this->client->get_id(),
			)
		);

		$this->assertSame( 200, rest_do_request( $request )->get_status() );
		$this->assertEmpty( WP_Application_Passwords::get_user_application_passwords( $this->user->ID ) );
		$this->assertSame( 200, rest_do_request( $request )->get_status(), 'Revoking again still succeeds (RFC 7009).' );
	}

	public function test_signing_in_again_beyond_the_limit_removes_the_oldest_connection(): void {
		for ( $i = 0; $i <= Token::MAX_SESSIONS_PER_CLIENT; $i++ ) {
			$this->assertSame( 200, $this->exchange( $this->issue_code(), self::VERIFIER )->get_status() );
		}

		$this->assertCount( Token::MAX_SESSIONS_PER_CLIENT, WP_Application_Passwords::get_user_application_passwords( $this->user->ID ) );
	}

	private function issue_code(): string {
		$challenge = rtrim( strtr( base64_encode( hash( 'sha256', self::VERIFIER, true ) ), '+/', '-_' ), '=' );

		return $this->server->codes()->issue(
			array(
				'client_id'      => $this->client->get_id(),
				'redirect_uri'   => self::REDIRECT_URI,
				'code_challenge' => $challenge,
				'resource'       => '',
				'user_id'        => $this->user->ID,
			)
		);
	}

	private function exchange( string $code, string $verifier ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/oauth/v1/token' );
		$request->set_body_params(
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'client_id'     => $this->client->get_id(),
				'redirect_uri'  => self::REDIRECT_URI,
				'code_verifier' => $verifier,
			)
		);

		return rest_do_request( $request );
	}
}
