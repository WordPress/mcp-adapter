<?php
/**
 * OAuth server setup.
 *
 * @package WP\OAuth
 */

declare( strict_types=1 );

namespace WP\OAuth;

use WP\OAuth\Admin\ClientsPage;
use WP\OAuth\Admin\HealthCheck;
use WP\OAuth\Authentication\Bearer;
use WP\OAuth\Clients\ClientRepository;
use WP\OAuth\Endpoints\Authorize;
use WP\OAuth\Endpoints\Revoke;
use WP\OAuth\Endpoints\Token;
use WP\OAuth\Grants\AuthorizationCodes;
use WP\OAuth\Tokens\ApplicationPasswordConnectionStore;
use WP\OAuth\Tokens\Contracts\ConnectionStoreInterface;
use WP\OAuth\Tokens\Contracts\TokenServiceInterface;
use WP\OAuth\Tokens\OpaqueTokenService;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the OAuth server components together.
 */
final class Server {

	/**
	 * The running server, once initialized.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Client registry.
	 *
	 * @var ClientRepository
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
	 * Token service.
	 *
	 * @var TokenServiceInterface
	 */
	private $tokens;

	/**
	 * OAuth connection persistence.
	 *
	 * @var ApplicationPasswordConnectionStore
	 */
	private $connections;

	/**
	 * Bearer token authentication.
	 *
	 * @var Bearer
	 */
	private $bearer;

	/**
	 * Starts the server unless it has been disabled.
	 */
	public static function init(): void {
		if ( null !== self::$instance ) {
			return;
		}

		/**
		 * Filters whether the OAuth server is enabled.
		 *
		 * @param bool $enabled Whether to run the OAuth server. Default true.
		 */
		if ( ! apply_filters( 'oauth_server_enabled', true ) ) {
			return;
		}

		self::$instance = new self();
		self::$instance->register_hooks();
	}

	/**
	 * Gets the running server.
	 *
	 * @return self|null The server, or null if it is not running.
	 */
	public static function instance(): ?self {
		return self::$instance;
	}

	/**
	 * Whether OAuth sign-in can be used on this site right now.
	 *
	 * Connections use Application Password records as revocation anchors, so
	 * OAuth is only available when Application Passwords are.
	 */
	public static function is_available(): bool {
		return wp_is_application_passwords_available();
	}

	/**
	 * The issuer identifier for this authorization server.
	 */
	public static function issuer(): string {
		return untrailingslashit( home_url() );
	}

	/**
	 * The authorization endpoint URL.
	 */
	public static function authorization_endpoint(): string {
		return add_query_arg( 'action', Authorize::ACTION, wp_login_url() );
	}

	/**
	 * The token endpoint URL.
	 */
	public static function token_endpoint(): string {
		return rest_url( Token::REST_NAMESPACE . Token::REST_ROUTE );
	}

	/**
	 * The revocation endpoint URL.
	 */
	public static function revocation_endpoint(): string {
		return rest_url( Token::REST_NAMESPACE . Revoke::REST_ROUTE );
	}

	/**
	 * Builds the components.
	 */
	private function __construct() {
		$this->clients     = new ClientRepository();
		$this->resources   = new ProtectedResources();
		$this->codes       = new AuthorizationCodes();
		$this->connections = new ApplicationPasswordConnectionStore();
		$default_tokens    = new OpaqueTokenService( $this->connections );

		/**
		 * Filters the OAuth token strategy.
		 *
		 * Alternate implementations, such as a JWT strategy, must implement
		 * TokenServiceInterface. Connection persistence remains independent.
		 *
		 * @param TokenServiceInterface    $tokens      Default opaque token service.
		 * @param ConnectionStoreInterface $connections Connection persistence.
		 */
		$tokens       = apply_filters( 'oauth_server_token_service', $default_tokens, $this->connections );
		$this->tokens = $tokens instanceof TokenServiceInterface ? $tokens : $default_tokens;
		$this->bearer = new Bearer( $this->tokens, $this->connections );
	}

	/**
	 * Registers hooks for every component.
	 */
	private function register_hooks(): void {
		$this->clients->register_hooks();
		$this->connections->register_hooks();
		( new Discovery( $this->resources ) )->register_hooks();
		( new Challenge( $this->resources, $this->bearer ) )->register_hooks();
		( new Authorize( $this->clients, $this->resources, $this->codes ) )->register_hooks();
		( new Token( $this->clients, $this->codes, $this->tokens ) )->register_hooks();
		( new Revoke( $this->clients, $this->tokens ) )->register_hooks();
		( new ClientsPage( $this->clients ) )->register_hooks();
		( new HealthCheck() )->register_hooks();
		$this->bearer->register_hooks();
	}

	/**
	 * Gets the client registry.
	 */
	public function clients(): ClientRepository {
		return $this->clients;
	}

	/**
	 * Gets the authorization code store.
	 */
	public function codes(): AuthorizationCodes {
		return $this->codes;
	}

	/**
	 * Gets the bearer token authentication handler.
	 */
	public function bearer(): Bearer {
		return $this->bearer;
	}

	/**
	 * Gets the token service.
	 */
	public function tokens(): TokenServiceInterface {
		return $this->tokens;
	}
}
