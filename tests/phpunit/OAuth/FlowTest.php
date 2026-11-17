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
use WP\OAuth\Server;
use WP\OAuth\Tokens\ApplicationPasswordConnectionStore;
use WP\OAuth\Tokens\OpaqueTokenService;
use WP_Application_Passwords;
use WP_Post;
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
		// A REST request as the front controller receives it.
		$_SERVER['SCRIPT_NAME'] = '/index.php';
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
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['SCRIPT_NAME'], $_GET['rest_route'] );
		parent::tear_down();
	}

	public function test_code_exchange_issues_token_that_authenticates_until_revoked(): void {
		$response = $this->exchange( $this->issue_code(), self::VERIFIER );

		$this->assertSame( 200, $response->get_status() );
		$token = $response->get_data()['access_token'];
		$this->assertStringStartsWith( OpaqueTokenService::ACCESS_PREFIX, $token );
		$this->assertStringStartsWith( OpaqueTokenService::REFRESH_PREFIX, $response->get_data()['refresh_token'] );
		$this->assertSame( OpaqueTokenService::ACCESS_LIFETIME, $response->get_data()['expires_in'] );

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

	/** Tests that the profile list distinguishes OAuth connections. */
	public function test_application_password_list_marks_oauth_connections(): void {
		$resource = untrailingslashit( rest_url( 'mcp/mcp-adapter-default-server' ) );
		$this->exchange( $this->issue_code( null, null, $resource ), self::VERIFIER, null, null, $resource );
		$oauth_item = WP_Application_Passwords::get_user_application_passwords( $this->user->ID )[0];
		$regular    = WP_Application_Passwords::create_new_application_password( $this->user->ID, array( 'name' => 'Regular credential' ) );
		$this->assertIsArray( $regular );

		$store   = new ApplicationPasswordConnectionStore();
		$columns = $store->add_type_column(
			array(
				'name'    => 'Name',
				'created' => 'Created',
			)
		);
		$this->assertSame( array( 'name', 'oauth_server_credential_type', 'created' ), array_keys( $columns ) );

		$previous_user_id    = $GLOBALS['user_id'] ?? null;
		$GLOBALS['user_id'] = $this->user->ID; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The list table supplies this global.
		try {
			ob_start();
			$store->render_type_column( 'oauth_server_credential_type', $oauth_item );
			$oauth_column = ob_get_clean();
			$this->assertIsString( $oauth_column );
			$this->assertStringContainsString( 'OAuth 2.0', $oauth_column );
			$this->assertStringContainsString( esc_html( $resource ), $oauth_column );

			ob_start();
			$store->render_type_column( 'oauth_server_credential_type', $regular[1] );
			$this->assertSame( 'Application Password', ob_get_clean() );
		} finally {
			$GLOBALS['user_id'] = $previous_user_id; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restores test state.
		}
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

	/** Tests that a bound token cannot authenticate a different REST resource. */
	public function test_resource_bound_token_only_authenticates_on_that_resource(): void {
		$resource = untrailingslashit( rest_url( 'mcp/mcp-adapter-default-server' ) );
		$token    = $this->exchange( $this->issue_code( null, null, $resource ), self::VERIFIER, null, null, $resource )->get_data()['access_token'];

		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
		$this->assertSame( $this->user->ID, $this->server->bearer()->authenticate( false ) );

		$_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/posts?rest_route=/mcp/mcp-adapter-default-server';
		$_GET['rest_route']     = '/mcp/mcp-adapter-default-server';
		$this->assertFalse( $this->server->bearer()->authenticate( false ) );
		$this->assertSame( 'oauth_server_invalid_token', $this->server->bearer()->get_error()->get_error_code() );
	}

	/** Tests that the token request cannot remove an approved resource. */
	public function test_resource_bound_code_requires_resource_at_token_endpoint(): void {
		$resource = untrailingslashit( rest_url( 'mcp/mcp-adapter-default-server' ) );
		$response = $this->exchange( $this->issue_code( null, null, $resource ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_target', $response->get_data()['error'] );
	}

	/** Tests that refresh preserves the original resource restriction. */
	public function test_resource_bound_refresh_requires_same_resource(): void {
		$resource = untrailingslashit( rest_url( 'mcp/mcp-adapter-default-server' ) );
		$issued   = $this->exchange( $this->issue_code( null, null, $resource ), self::VERIFIER, null, null, $resource )->get_data();

		$missing = $this->refresh( $issued['refresh_token'] );
		$this->assertSame( 400, $missing->get_status() );
		$this->assertSame( 'invalid_target', $missing->get_data()['error'] );

		$this->assertSame( 200, $this->refresh( $issued['refresh_token'], null, null, $resource )->get_status() );
	}

	/** Tests that deliberately unrestricted tokens still work across REST routes. */
	public function test_unrestricted_token_authenticates_on_another_rest_route(): void {
		$token = $this->exchange( $this->issue_code() )->get_data()['access_token'];

		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
		$_SERVER['REQUEST_URI']        = '/wp-json/wp/v2/posts';

		$this->assertSame( $this->user->ID, $this->server->bearer()->authenticate( false ) );
	}

	public function test_oauth_access_tokens_are_not_application_passwords(): void {
		$token = $this->exchange( $this->issue_code(), self::VERIFIER )->get_data()['access_token'];

		add_filter( 'application_password_is_api_request', '__return_true' );
		$result = wp_authenticate_application_password( null, $this->user->user_login, $token );

		$this->assertWPError( $result );
		$this->assertContains( 'incorrect_password', $result->get_error_codes() );
	}

	public function test_only_hashes_of_opaque_tokens_are_stored(): void {
		$tokens = $this->exchange( $this->issue_code(), self::VERIFIER )->get_data();
		$stored = wp_json_encode( get_user_meta( $this->user->ID ) );
		$this->assertIsString( $stored );

		$this->assertStringNotContainsString( $tokens['access_token'], $stored );
		$this->assertStringNotContainsString( $tokens['refresh_token'], $stored );
		$this->assertStringContainsString( hash( 'sha256', $tokens['access_token'] ), $stored );
		$this->assertStringContainsString( hash( 'sha256', $tokens['refresh_token'] ), $stored );
	}

	public function test_refresh_rotates_both_tokens_and_keeps_the_connection(): void {
		$issued  = $this->exchange( $this->issue_code(), self::VERIFIER )->get_data();
		$rotated = $this->refresh( $issued['refresh_token'] );

		$this->assertSame( 200, $rotated->get_status() );
		$this->assertNotSame( $issued['access_token'], $rotated->get_data()['access_token'] );
		$this->assertNotSame( $issued['refresh_token'], $rotated->get_data()['refresh_token'] );
		$this->assertSame( OpaqueTokenService::ACCESS_LIFETIME, $rotated->get_data()['expires_in'] );
		$this->assertCount( 1, WP_Application_Passwords::get_user_application_passwords( $this->user->ID ) );

		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $rotated->get_data()['access_token'];
		$this->assertSame( $this->user->ID, $this->server->bearer()->authenticate( false ) );
	}

	public function test_reusing_a_rotated_refresh_token_revokes_the_connection(): void {
		$issued  = $this->exchange( $this->issue_code(), self::VERIFIER )->get_data();
		$rotated = $this->refresh( $issued['refresh_token'] );
		$this->assertSame( 200, $rotated->get_status() );

		$replay = $this->refresh( $issued['refresh_token'] );
		$this->assertSame( 400, $replay->get_status() );
		$this->assertSame( 'invalid_grant', $replay->get_data()['error'] );
		$this->assertEmpty( WP_Application_Passwords::get_user_application_passwords( $this->user->ID ) );

		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $rotated->get_data()['access_token'];
		$this->assertFalse( $this->server->bearer()->authenticate( false ) );
	}

	public function test_refresh_token_is_bound_to_its_client(): void {
		$issued = $this->exchange( $this->issue_code(), self::VERIFIER )->get_data();
		$other  = $this->server->clients()->create( 'Other Client', array( self::REDIRECT_URI ), false )[0];

		$response = $this->refresh( $issued['refresh_token'], $other );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_grant', $response->get_data()['error'] );
		$this->assertCount( 1, WP_Application_Passwords::get_user_application_passwords( $this->user->ID ) );
	}

	/**
	 * @dataProvider provide_non_rest_requests
	 *
	 * @param string $script     Script that handles the request.
	 * @param string $uri        Request URI.
	 * @param bool   $is_ajax    Whether the request is an AJAX request.
	 * @param bool   $rest_route Whether a rest_route query parameter is present.
	 */
	public function test_bearer_token_is_ignored_outside_the_rest_api( string $script, string $uri, bool $is_ajax, bool $rest_route ): void {
		$token = $this->exchange( $this->issue_code() )->get_data()['access_token'];

		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
		$_SERVER['SCRIPT_NAME']        = $script;
		$_SERVER['REQUEST_URI']        = $uri;
		if ( $rest_route ) {
			$_GET['rest_route'] = '/mcp/mcp-adapter-default-server';
		}
		if ( $is_ajax ) {
			add_filter( 'wp_doing_ajax', '__return_true' );
		}

		$this->assertFalse( $this->server->bearer()->authenticate( false ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: bool, 3: bool}>
	 */
	public static function provide_non_rest_requests(): array {
		return array(
			'admin-ajax with rest_route'   => array( '/wp-admin/admin-ajax.php', '/wp-admin/admin-ajax.php?rest_route=/mcp', true, true ),
			'admin-ajax by script only'    => array( '/wp-admin/admin-ajax.php', '/wp-admin/admin-ajax.php?rest_route=/mcp', false, true ),
			'REST path after wp-login.php' => array( '/wp-login.php', '/wp-login.php/wp-json/mcp/mcp-adapter-default-server', false, false ),
			'AJAX through index.php'       => array( '/index.php', '/?rest_route=/mcp/mcp-adapter-default-server', true, true ),
		);
	}

	/**
	 * @dataProvider provide_rest_requests
	 *
	 * @param string $uri        Request URI.
	 * @param bool   $rest_route Whether a rest_route query parameter is present.
	 */
	public function test_bearer_token_works_for_each_rest_permalink_style( string $uri, bool $rest_route ): void {
		$token = $this->exchange( $this->issue_code() )->get_data()['access_token'];

		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
		$_SERVER['REQUEST_URI']        = $uri;
		if ( $rest_route ) {
			$_GET['rest_route'] = '/mcp/mcp-adapter-default-server';
		}

		$this->assertSame( $this->user->ID, $this->server->bearer()->authenticate( false ) );
	}

	/**
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public static function provide_rest_requests(): array {
		return array(
			'pretty permalinks'    => array( '/wp-json/mcp/mcp-adapter-default-server', false ),
			'index.php permalinks' => array( '/index.php/wp-json/mcp/mcp-adapter-default-server', false ),
			'plain permalinks'     => array( '/?rest_route=/mcp/mcp-adapter-default-server', true ),
		);
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

	public function test_public_client_can_revoke_without_naming_itself(): void {
		$token = $this->exchange( $this->issue_code() )->get_data()['access_token'];

		$this->assertSame( 200, $this->revoke( array( 'token' => $token ) )->get_status() );
		$this->assertEmpty( WP_Application_Passwords::get_user_application_passwords( $this->user->ID ) );
	}

	public function test_confidential_client_must_authenticate_to_revoke(): void {
		[ $client, $secret ] = $this->server->clients()->create( 'Confidential Client', array( self::REDIRECT_URI ), true );
		$token               = $this->exchange( $this->issue_code( $this->user->ID, $client ), self::VERIFIER, $client, $secret )->get_data()['access_token'];

		// Leaving out client_id does not skip authentication: the token's own client is used.
		$this->assertSame( 401, $this->revoke( array( 'token' => $token ) )->get_status() );
		$this->assertSame(
			401,
			$this->revoke(
				array(
					'token'         => $token,
					'client_id'     => $client->get_id(),
					'client_secret' => 'wrong',
				)
			)->get_status()
		);
		$this->assertCount( 1, WP_Application_Passwords::get_user_application_passwords( $this->user->ID ) );

		$response = $this->revoke(
			array(
				'token'         => $token,
				'client_id'     => $client->get_id(),
				'client_secret' => $secret,
			)
		);
		$this->assertSame( 200, $response->get_status() );
		$this->assertEmpty( WP_Application_Passwords::get_user_application_passwords( $this->user->ID ) );
	}

	public function test_revocation_rejects_unknown_clients_and_other_clients_tokens(): void {
		$token = $this->exchange( $this->issue_code() )->get_data()['access_token'];
		$other = $this->server->clients()->create( 'Other Client', array( self::REDIRECT_URI ), false )[0];

		$unknown = $this->revoke(
			array(
				'token'     => $token,
				'client_id' => wp_generate_uuid4(),
			)
		);
		$this->assertSame( 401, $unknown->get_status() );
		$this->assertSame( 'invalid_client', $unknown->get_data()['error'] );

		// Another client gets a 200, as RFC 7009 requires, but the token is untouched.
		$this->assertSame(
			200,
			$this->revoke(
				array(
					'token'     => $token,
					'client_id' => $other->get_id(),
				)
			)->get_status()
		);
		$this->assertCount( 1, WP_Application_Passwords::get_user_application_passwords( $this->user->ID ) );
	}

	public function test_deleting_a_client_removes_its_connections_for_every_user(): void {
		$other_user   = self::factory()->user->create_and_get( array( 'role' => 'author' ) );
		$other_client = $this->server->clients()->create( 'Other Client', array( self::REDIRECT_URI ), false )[0];

		$token = $this->exchange( $this->issue_code() )->get_data()['access_token'];
		$this->exchange( $this->issue_code( $other_user->ID ) );
		$this->exchange( $this->issue_code( $this->user->ID, $other_client ), self::VERIFIER, $other_client );
		WP_Application_Passwords::create_new_application_password( $this->user->ID, array( 'name' => 'Made by hand' ) );

		$this->assertTrue( $this->server->clients()->delete( $this->client->get_id() ) );

		$this->assertSame( array( 'Other Client', 'Made by hand' ), array_values( wp_list_pluck( WP_Application_Passwords::get_user_application_passwords( $this->user->ID ), 'name' ) ) );
		$this->assertEmpty( WP_Application_Passwords::get_user_application_passwords( $other_user->ID ) );

		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
		$this->assertFalse( $this->server->bearer()->authenticate( false ) );
	}

	public function test_trashing_a_client_removes_its_connections(): void {
		$this->exchange( $this->issue_code() );

		$post = get_page_by_path( $this->client->get_id(), OBJECT, ClientRepository::POST_TYPE );
		$this->assertInstanceOf( WP_Post::class, $post );
		wp_trash_post( $post->ID );

		$this->assertEmpty( WP_Application_Passwords::get_user_application_passwords( $this->user->ID ) );
	}

	public function test_signing_in_again_beyond_the_limit_removes_the_oldest_connection(): void {
		for ( $i = 0; $i <= ApplicationPasswordConnectionStore::MAX_CONNECTIONS_PER_CLIENT; $i++ ) {
			$this->assertSame( 200, $this->exchange( $this->issue_code(), self::VERIFIER )->get_status() );
		}

		$this->assertCount( ApplicationPasswordConnectionStore::MAX_CONNECTIONS_PER_CLIENT, WP_Application_Passwords::get_user_application_passwords( $this->user->ID ) );
	}

	private function issue_code( ?int $user_id = null, ?Client $client = null, string $resource = '' ): string {
		$challenge = rtrim( strtr( base64_encode( hash( 'sha256', self::VERIFIER, true ) ), '+/', '-_' ), '=' );

		return $this->server->codes()->issue(
			array(
				'client_id'      => ( $client ?? $this->client )->get_id(),
				'redirect_uri'   => self::REDIRECT_URI,
				'code_challenge' => $challenge,
				'resource'       => $resource,
				'user_id'        => $user_id ?? $this->user->ID,
			)
		);
	}

	private function exchange( string $code, string $verifier = self::VERIFIER, ?Client $client = null, ?string $secret = null, string $resource = '' ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/oauth/v1/token' );
		$request->set_body_params(
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'client_id'     => ( $client ?? $this->client )->get_id(),
				'client_secret' => (string) $secret,
				'redirect_uri'  => self::REDIRECT_URI,
				'code_verifier' => $verifier,
				'resource'      => $resource,
			)
		);

		return rest_do_request( $request );
	}

	private function refresh( string $refresh_token, ?Client $client = null, ?string $secret = null, string $resource = '' ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/oauth/v1/token' );
		$request->set_body_params(
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $refresh_token,
				'client_id'     => ( $client ?? $this->client )->get_id(),
				'client_secret' => (string) $secret,
				'resource'      => $resource,
			)
		);

		return rest_do_request( $request );
	}

	/**
	 * Sends a revocation request.
	 *
	 * @param array<string, string> $params Body parameters.
	 */
	private function revoke( array $params ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/oauth/v1/revoke' );
		$request->set_body_params( $params );

		return rest_do_request( $request );
	}
}
