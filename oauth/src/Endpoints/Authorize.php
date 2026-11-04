<?php
/**
 * Authorization endpoint.
 *
 * @package WP\OAuth
 */

declare( strict_types=1 );

namespace WP\OAuth\Endpoints;

use WP\OAuth\Clients\Client;
use WP\OAuth\Clients\ClientRepository;
use WP\OAuth\Grants\AuthorizationCodes;
use WP\OAuth\Grants\Pkce;
use WP\OAuth\ProtectedResources;
use WP\OAuth\Server;
use WP_Error;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Handles authorization requests and the consent screen.
 *
 * Runs as a wp-login.php action (as WP-API/OAuth2 does), so login redirects
 * work on every permalink setting and the page gets the login screen's frame
 * protection.
 *
 * A validated request is stored server-side while the user decides, and the
 * consent form only carries its ID. Submitting the form consumes the stored
 * request, so a double submission cannot mint two codes (as wp-media/mcp-oauth
 * does with its state transient).
 */
final class Authorize {

	public const ACTION = 'oauth_authorize';

	private const REQUEST_PREFIX   = 'oauth_server_request_';
	private const REQUEST_LIFETIME = 10 * MINUTE_IN_SECONDS;

	// phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- False positive: sniff mistakes array() commas for multi-const commas.
	private const PARAMS = array(
		'response_type',
		'client_id',
		'redirect_uri',
		'state',
		'code_challenge',
		'code_challenge_method',
		'resource',
		'scope',
	);

	/**
	 * Client registry.
	 *
	 * @var \WP\OAuth\Clients\ClientRepository
	 */
	private $clients;

	/**
	 * Protected resource registry.
	 *
	 * @var \WP\OAuth\ProtectedResources
	 */
	private $resources;

	/**
	 * Authorization code store.
	 *
	 * @var \WP\OAuth\Grants\AuthorizationCodes
	 */
	private $codes;

	/**
	 * Constructor.
	 *
	 * @param \WP\OAuth\Clients\ClientRepository  $clients   Client registry.
	 * @param \WP\OAuth\ProtectedResources        $resources Protected resource registry.
	 * @param \WP\OAuth\Grants\AuthorizationCodes $codes     Authorization code store.
	 */
	public function __construct( ClientRepository $clients, ProtectedResources $resources, AuthorizationCodes $codes ) {
		$this->clients   = $clients;
		$this->resources = $resources;
		$this->codes     = $codes;
	}

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'login_form_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Handles the request. Always ends the request.
	 */
	public function handle(): void {
		if ( ! Server::is_available() ) {
			$this->fail( __( 'Connecting applications is not available on this site.', 'mcp-adapter' ) );
		}

		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] ) {
			$this->handle_decision();
		}

		$params = array();
		foreach ( self::PARAMS as $name ) {
			$params[ $name ] = isset( $_GET[ $name ] ) && is_string( $_GET[ $name ] ) ? trim( wp_unslash( $_GET[ $name ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- An OAuth request from another site; values are validated below, and redirect URIs must match exactly.
		}

		// Check the client and redirect URI before redirecting anywhere (OAuth 2.1 section 7.5.2).
		$client = $this->clients->get( $params['client_id'] );
		if ( ! $client instanceof Client ) {
			$this->fail( __( 'This application is not registered on this site.', 'mcp-adapter' ) );
		}
		if ( '' === $params['redirect_uri'] || ! $client->allows_redirect_uri( $params['redirect_uri'] ) ) {
			$this->fail( __( 'This application asked to return to an address that is not registered for it.', 'mcp-adapter' ) );
		}

		// From here on, errors go back to the client.
		if ( 'code' !== $params['response_type'] ) {
			$this->redirect_error( $params, 'unsupported_response_type', 'Only the code response type is supported.' );
		}
		if ( 'S256' !== $params['code_challenge_method'] || ! Pkce::is_valid_challenge( $params['code_challenge'] ) ) {
			$this->redirect_error( $params, 'invalid_request', 'PKCE with the S256 method is required.' );
		}
		if ( '' === $params['state'] ) {
			// Required by OAuth 2.1 section 4.1.1: without it the client cannot detect CSRF.
			$this->redirect_error( $params, 'invalid_request', 'The state parameter is required.' );
		}
		if ( '' !== $params['resource'] && null === $this->resources->find_by_url( $params['resource'] ) ) {
			$this->redirect_error( $params, 'invalid_target', 'The requested resource is not served by this site.' );
		}

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( $this->request_url( $params ) ) );
			exit;
		}

		$user = wp_get_current_user();
		if ( ! wp_is_application_passwords_available_for_user( $user ) ) {
			$this->redirect_error( $params, 'access_denied', 'This user cannot authorize applications.' );
		}

		$request_id = bin2hex( random_bytes( 16 ) );
		set_transient( self::REQUEST_PREFIX . $request_id, array_merge( $params, array( 'user_id' => $user->ID ) ), self::REQUEST_LIFETIME );

		$this->render_consent( $client, $user, $params, $request_id );
		exit;
	}

	/**
	 * Handles the consent form. Always ends the request.
	 */
	private function handle_decision(): void {
		$request_id = isset( $_POST['request_id'] ) ? sanitize_key( wp_unslash( $_POST['request_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked below.
		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', $request_id ) || ! is_user_logged_in() ) {
			$this->fail( __( 'This request is not valid. Start again from the application.', 'mcp-adapter' ) );
		}

		check_admin_referer( self::ACTION . '_' . $request_id );

		// Consume the stored request. delete_transient() only succeeds once, so a
		// repeated submission fails here.
		$key     = self::REQUEST_PREFIX . $request_id;
		$pending = get_transient( $key );
		if ( ! is_array( $pending ) || ! delete_transient( $key ) || get_current_user_id() !== (int) $pending['user_id'] ) {
			$this->fail( __( 'This request has expired. Start again from the application.', 'mcp-adapter' ) );
		}

		// The client may have been edited or deleted while the user decided.
		$client = $this->clients->get( (string) $pending['client_id'] );
		if ( ! $client instanceof Client || ! $client->allows_redirect_uri( (string) $pending['redirect_uri'] ) ) {
			$this->fail( __( 'This application is no longer registered on this site.', 'mcp-adapter' ) );
		}

		if ( ! isset( $_POST['approve'] ) ) {
			$this->redirect_error( $pending, 'access_denied', 'The user denied the request.' );
		}

		$code = $this->codes->issue(
			array(
				'client_id'      => $client->get_id(),
				'redirect_uri'   => $pending['redirect_uri'],
				'code_challenge' => $pending['code_challenge'],
				'resource'       => $pending['resource'],
				'user_id'        => get_current_user_id(),
			)
		);

		$this->redirect( $pending, array( 'code' => $code ) );
	}

	/**
	 * Builds the URL of this authorization request.
	 *
	 * @param array<string, string> $params Request parameters.
	 */
	private function request_url( array $params ): string {
		$present = array_filter(
			array_intersect_key( $params, array_flip( self::PARAMS ) ),
			static function ( $value ): bool {
				return '' !== $value;
			}
		);

		return add_query_arg( array_map( 'rawurlencode', $present ), Server::authorization_endpoint() );
	}

	/**
	 * Redirects back to the client with an error.
	 *
	 * @param array<string, mixed> $params      Request parameters.
	 * @param string               $error       OAuth error code.
	 * @param string               $description Description for the client developer.
	 * @return never
	 */
	private function redirect_error( array $params, string $error, string $description ): void {
		$this->redirect(
			$params,
			array(
				'error'             => $error,
				'error_description' => $description,
			)
		);
	}

	/**
	 * Redirects back to the client.
	 *
	 * @param array<string, mixed>  $params Request parameters.
	 * @param array<string, string> $values Values to add to the redirect URI.
	 * @return never
	 */
	private function redirect( array $params, array $values ): void {
		if ( '' !== (string) $params['state'] ) {
			$values['state'] = (string) $params['state'];
		}
		$values['iss'] = Server::issuer();

		$uri       = (string) $params['redirect_uri'];
		$separator = false === strpos( $uri, '?' ) ? '?' : '&';

		// The redirect URI was checked against the client's registered URIs.
		wp_redirect( $uri . $separator . http_build_query( $values, '', '&', PHP_QUERY_RFC3986 ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Redirects to the client's registered URI.
		exit;
	}

	/**
	 * Shows an error that cannot be sent back to the client, styled as a login screen error.
	 *
	 * @param string $message Message for the user.
	 * @return never
	 */
	private function fail( string $message ): void {
		status_header( 400 );
		nocache_headers();

		if ( ! function_exists( 'login_header' ) ) {
			wp_die( esc_html( $message ), esc_html__( 'Cannot connect application', 'mcp-adapter' ), array( 'response' => 400 ) );
		}

		login_header( __( 'Cannot connect application', 'mcp-adapter' ), '', new WP_Error( 'oauth_server_error', esc_html( $message ) ) );
		login_footer();
		exit;
	}

	/**
	 * Renders the consent screen.
	 *
	 * @param \WP\OAuth\Clients\Client $client     Client asking for access.
	 * @param \WP_User                 $user       Current user.
	 * @param array<string, string>    $params     Request parameters.
	 * @param string                   $request_id Stored request ID.
	 */
	private function render_consent( Client $client, WP_User $user, array $params, string $request_id ): void {
		nocache_headers();

		$data = array( // phpcs:ignore SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable -- Used by the template.
			'client'          => $client,
			'user'            => $user,
			'params'          => $params,
			'request_id'      => $request_id,
			'action_url'      => Server::authorization_endpoint(),
			'nonce_action'    => self::ACTION . '_' . $request_id,
			'redirect_host'   => (string) wp_parse_url( $params['redirect_uri'], PHP_URL_HOST ),
			'switch_user_url' => wp_login_url( $this->request_url( $params ), true ),
		);

		require dirname( __DIR__, 2 ) . '/templates/consent.php';
	}
}
