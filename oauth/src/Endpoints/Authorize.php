<?php
/**
 * Authorization endpoint.
 *
 * @package WP\OAuth
 */

declare( strict_types=1 );

namespace WP\OAuth\Endpoints;

use WP\OAuth\Clients\Cimd\CimdApproval;
use WP\OAuth\Clients\Client;
use WP\OAuth\Clients\ClientResolver;
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
	private const APPROVAL_PREFIX  = 'oauth_server_cimd_approval_';

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
	 * @var ClientResolver
	 */
	private $clients;

	/**
	 * Protected resource registry.
	 *
	 * @var ProtectedResources
	 */
	private $resources;

	/**
	 * Authorization code store.
	 *
	 * @var AuthorizationCodes
	 */
	private $codes;

	/**
	 * Constructor.
	 *
	 * @param ClientResolver    $clients   Authorization-time client resolver.
	 * @param ProtectedResources        $resources Protected resource registry.
	 * @param AuthorizationCodes $codes     Authorization code store.
	 */
	public function __construct( ClientResolver $clients, ProtectedResources $resources, AuthorizationCodes $codes ) {
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
			if ( isset( $_POST['cimd_approval_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Selects the handler; that handler verifies its nonce.
				$this->handle_cimd_approval();
			}
			$this->handle_decision();
		}

		$params = array();
		foreach ( self::PARAMS as $name ) {
			$params[ $name ] = isset( $_GET[ $name ] ) && is_string( $_GET[ $name ] ) ? trim( wp_unslash( $_GET[ $name ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- An OAuth request from another site; values are validated below, and redirect URIs must match exactly.
		}

		// Never fetch an attacker-selected URL before a user has authenticated.
		$client   = $this->clients->get( $params['client_id'] );
		$approval = null;
		if ( ! $client instanceof Client && $this->clients->is_cimd_candidate( $params['client_id'] ) && ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( $this->request_url( $params ) ) );
			exit;
		}
		if ( is_user_logged_in() && ( ! $client instanceof Client || $client->is_cimd() ) ) {
			$resolved = $this->clients->resolve_for_authorization( $params['client_id'], get_current_user_id() );
			if ( is_wp_error( $resolved ) ) {
				$this->fail( $resolved->get_error_message() );
			}
			if ( $resolved instanceof CimdApproval ) {
				$approval = $resolved;
				$client   = $approval->to_client();
			} else {
				$client = $resolved;
			}
		}

		// Check the client and redirect URI before redirecting to the client (OAuth 2.1 section 7.5.2).
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
		if ( $approval instanceof CimdApproval ) {
			$approval_id = bin2hex( random_bytes( 16 ) );
			set_transient(
				self::APPROVAL_PREFIX . $approval_id,
				array(
					'params'   => $params,
					'user_id'  => $user->ID,
					'approval' => $approval->to_array(),
				),
				self::REQUEST_LIFETIME
			);
			$this->render_cimd_approval( $approval, $user, $params, $approval_id );
			exit;
		}

		$request_id = bin2hex( random_bytes( 16 ) );
		set_transient(
			self::REQUEST_PREFIX . $request_id,
			array_merge(
				$params,
				array(
					'user_id'           => $user->ID,
					'client_generation' => $client->get_generation(),
				)
			),
			self::REQUEST_LIFETIME
		);

		$this->render_consent( $client, $user, $params, $request_id );
		exit;
	}

	/**
	 * Handles an administrator's site-level CIMD approval. Always ends the request.
	 *
	 * @return never
	 */
	private function handle_cimd_approval(): void {
		$approval_id = isset( $_POST['cimd_approval_id'] ) ? sanitize_key( wp_unslash( $_POST['cimd_approval_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked below.
		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', $approval_id ) || ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			$this->fail( __( 'You are not allowed to approve this application for the site.', 'mcp-adapter' ) );
		}

		check_admin_referer( self::ACTION . '_cimd_' . $approval_id );

		$key     = self::APPROVAL_PREFIX . $approval_id;
		$pending = get_transient( $key );
		if ( ! is_array( $pending ) || ! delete_transient( $key ) || get_current_user_id() !== (int) ( $pending['user_id'] ?? 0 ) || ! isset( $pending['params'], $pending['approval'] ) || ! is_array( $pending['params'] ) || ! is_array( $pending['approval'] ) ) {
			$this->fail( __( 'This approval request has expired. Start again from the application.', 'mcp-adapter' ) );
		}

		$approval = CimdApproval::from_array( $pending['approval'] );
		if ( ! $approval instanceof CimdApproval || $approval->get_client_id() !== (string) ( $pending['params']['client_id'] ?? '' ) ) {
			$this->fail( __( 'This approval request is not valid. Start again from the application.', 'mcp-adapter' ) );
		}

		if ( ! isset( $_POST['approve_client'] ) ) {
			$this->redirect_error( $pending['params'], 'access_denied', 'A site administrator denied this client registration.' );
		}

		$client = $this->clients->approve_cimd( $approval );
		if ( is_wp_error( $client ) ) {
			$this->fail( $client->get_error_message() );
		}

		wp_safe_redirect( $this->request_url( $pending['params'] ) );
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
		if ( ! $client instanceof Client || ! $client->allows_redirect_uri( (string) $pending['redirect_uri'] ) || ! hash_equals( $client->get_generation(), (string) ( $pending['client_generation'] ?? '' ) ) ) {
			$this->fail( __( 'This application is no longer registered on this site.', 'mcp-adapter' ) );
		}

		if ( ! isset( $_POST['approve'] ) ) {
			$this->redirect_error( $pending, 'access_denied', 'The user denied the request.' );
		}

		$code = $this->codes->issue(
			array(
				'client_id'         => $client->get_id(),
				'redirect_uri'      => $pending['redirect_uri'],
				'code_challenge'    => $pending['code_challenge'],
				'resource'          => $pending['resource'],
				'user_id'           => get_current_user_id(),
				'client_generation' => $client->get_generation(),
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
	 * @param Client $client     Client asking for access.
	 * @param WP_User                 $user       Current user.
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

	/**
	 * Renders the site-level CIMD approval screen.
	 *
	 * @param CimdApproval          $approval    Verified registration awaiting approval.
	 * @param WP_User               $user        Current administrator.
	 * @param array<string, string> $params      Authorization request parameters.
	 * @param string                $approval_id Stored approval ID.
	 */
	private function render_cimd_approval( CimdApproval $approval, WP_User $user, array $params, string $approval_id ): void {
		nocache_headers();

		$data = array( // phpcs:ignore SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable -- Used by the template.
			'approval'        => $approval,
			'client'          => $approval->to_client(),
			'user'            => $user,
			'params'          => $params,
			'approval_id'     => $approval_id,
			'action_url'      => Server::authorization_endpoint(),
			'nonce_action'    => self::ACTION . '_cimd_' . $approval_id,
			'switch_user_url' => wp_login_url( $this->request_url( $params ), true ),
		);

		require dirname( __DIR__, 2 ) . '/templates/client-approval.php';
	}
}
