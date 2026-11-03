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

defined( 'ABSPATH' ) || exit;

/**
 * Handles authorization requests and the consent screen.
 *
 * Runs as a wp-login.php action, so login redirects work on every permalink
 * setting and the page gets the login screen's frame protection.
 */
final class Authorize {

	public const ACTION = 'oauth_authorize';

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

		$params = $this->read_params();

		// Without a known client and redirect URI, errors cannot be sent back to the client.
		$client = $this->clients->get( $params['client_id'] );
		if ( ! $client instanceof Client ) {
			$this->fail( __( 'This application is not registered on this site.', 'mcp-adapter' ) );
		}
		if ( '' === $params['redirect_uri'] || ! $client->allows_redirect_uri( $params['redirect_uri'] ) ) {
			$this->fail( __( 'This application asked to return to an address that is not registered for it.', 'mcp-adapter' ) );
		}

		if ( 'code' !== $params['response_type'] ) {
			$this->redirect_error( $params, 'unsupported_response_type', 'Only the code response type is supported.' );
		}
		if ( 'S256' !== $params['code_challenge_method'] || ! Pkce::is_valid_challenge( $params['code_challenge'] ) ) {
			$this->redirect_error( $params, 'invalid_request', 'PKCE with the S256 method is required.' );
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

		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] ) {
			check_admin_referer( $this->nonce_action( $params ) );

			if ( ! isset( $_POST['approve'] ) ) {
				$this->redirect_error( $params, 'access_denied', 'The user denied the request.' );
			}

			$code = $this->codes->issue(
				array(
					'client_id'      => $client->get_id(),
					'redirect_uri'   => $params['redirect_uri'],
					'code_challenge' => $params['code_challenge'],
					'resource'       => $params['resource'],
					'user_id'        => $user->ID,
				)
			);

			$this->redirect( $params, array( 'code' => $code ) );
		}

		$this->render_consent( $client, $user, $params );
		exit;
	}

	/**
	 * Reads the authorization request parameters.
	 *
	 * @return array<string, string>
	 */
	private function read_params(): array {
		$source = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] ? $_POST : $_GET; // phpcs:ignore WordPress.Security.NonceVerification -- The nonce is checked before anything changes.

		$params = array();
		foreach ( self::PARAMS as $name ) {
			$params[ $name ] = isset( $source[ $name ] ) && is_string( $source[ $name ] ) ? trim( wp_unslash( $source[ $name ] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Values are validated, and redirect URIs must match exactly.
		}

		return $params;
	}

	/**
	 * Builds the URL of this authorization request.
	 *
	 * @param array<string, string> $params Request parameters.
	 */
	private function request_url( array $params ): string {
		$present = array_filter(
			$params,
			static function ( string $value ): bool {
				return '' !== $value;
			}
		);

		return add_query_arg( array_map( 'rawurlencode', $present ), Server::authorization_endpoint() );
	}

	/**
	 * Builds the nonce action, bound to the request so a nonce for one
	 * request cannot approve another.
	 *
	 * @param array<string, string> $params Request parameters.
	 */
	private function nonce_action( array $params ): string {
		return self::ACTION . '|' . $params['client_id'] . '|' . md5( $params['redirect_uri'] . '|' . $params['code_challenge'] . '|' . $params['resource'] );
	}

	/**
	 * Redirects back to the client with an error.
	 *
	 * @param array<string, string> $params      Request parameters.
	 * @param string                $error       OAuth error code.
	 * @param string                $description Description for the client developer.
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
	 * @param array<string, string> $params Request parameters.
	 * @param array<string, string> $values Values to add to the redirect URI.
	 * @return never
	 */
	private function redirect( array $params, array $values ): void {
		if ( '' !== $params['state'] ) {
			$values['state'] = $params['state'];
		}
		$values['iss'] = Server::issuer();

		$uri       = $params['redirect_uri'];
		$separator = false === strpos( $uri, '?' ) ? '?' : '&';

		// The redirect URI was checked against the client's registered URIs.
		wp_redirect( $uri . $separator . http_build_query( $values, '', '&', PHP_QUERY_RFC3986 ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Redirects to the client's registered URI.
		exit;
	}

	/**
	 * Shows an error that cannot be sent back to the client.
	 *
	 * @param string $message Message for the user.
	 * @return never
	 */
	private function fail( string $message ): void {
		wp_die( esc_html( $message ), esc_html__( 'Cannot connect application', 'mcp-adapter' ), array( 'response' => 400 ) );
	}

	/**
	 * Renders the consent screen.
	 *
	 * @param \WP\OAuth\Clients\Client $client Client asking for access.
	 * @param \WP_User                 $user   Current user.
	 * @param array<string, string>    $params Request parameters.
	 */
	private function render_consent( Client $client, \WP_User $user, array $params ): void {
		$data = array( // phpcs:ignore SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable -- Used by the template.
			'client'        => $client,
			'user'          => $user,
			'params'        => $params,
			'action_url'    => Server::authorization_endpoint(),
			'nonce_action'  => $this->nonce_action( $params ),
			'redirect_host' => (string) wp_parse_url( $params['redirect_uri'], PHP_URL_HOST ),
		);

		require dirname( __DIR__, 2 ) . '/templates/consent.php';
	}
}
