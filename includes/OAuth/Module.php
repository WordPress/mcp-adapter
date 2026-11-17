<?php
/**
 * OAuth sign-in for MCP clients: wiring, requirements and configuration.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\OAuth;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Boots the OAuth authorization server when the site can run it.
 *
 * OAuth here only authenticates. An access token acts as the WordPress user who approved
 * the connection, on the whole REST API of the site where it was approved, and that
 * user's capabilities decide what it can do. There are no OAuth scopes and no
 * per-connection permissions; `general` exists only because MCP clients expect a scope
 * name in the challenge and metadata, and it is never enforced.
 *
 * The protocol surface is the minimum MCP clients need: protected resource metadata
 * (RFC 9728), authorization server metadata (RFC 8414), the authorization code flow with
 * PKCE S256, refresh token rotation, public clients identified by a Client ID Metadata
 * Document or by stateless dynamic registration (RFC 7591), and bearer tokens (RFC 6750).
 * The only stored state is one user meta row per connection ({@see Grants}). It is not an
 * Application Password: those never expire, and core keeps all of a user's Application
 * Passwords in one serialized value written without locking, so a refresh token rotation
 * could not be checked and swapped safely there.
 *
 * @since n.e.x.t
 */
final class Module {

	/**
	 * Path of the issuer identifier under the site root.
	 *
	 * @var string
	 */
	public const ISSUER_PATH = '/mcp-adapter/oauth';

	/**
	 * The one scope name advertised. Requested scopes are accepted and ignored.
	 *
	 * @var string
	 */
	public const SCOPE = 'general';

	/**
	 * REST namespace of the token and registration endpoints.
	 *
	 * @var string
	 */
	public const REST_NAMESPACE = 'mcp-adapter/v1';

	/**
	 * Registers the hooks. Called once by {@see \WP\MCP\Plugin}, so only the standalone
	 * plugin runs OAuth; a plugin that bundles the adapter as a library does not.
	 *
	 * Another copy of this code may already run on the site, such as one bundled under a
	 * prefixed namespace. Class names differ between copies, but hook names do not, so the
	 * first copy to boot fires `mcp_adapter_oauth_init` and every later copy stands aside.
	 *
	 * Connections stay listed and revocable on the profile, are cleaned up when a user
	 * leaves a site, and survive a password rehash, even while sign-in is off. Whether
	 * sign-in itself runs is decided on `init`, the first point where the permalink
	 * settings can be read.
	 *
	 * @return void
	 */
	public static function boot(): void {
		if ( did_action( 'mcp_adapter_oauth_init' ) ) {
			return;
		}

		ProfileConnections::register();
		add_action( 'remove_user_from_blog', array( Grants::class, 'delete_for_user_on_site' ), 10, 2 );
		add_action( 'wp_set_password', array( Grants::class, 'rebind_password' ), 10, 3 );
		add_action( 'init', array( self::class, 'start' ) );

		/**
		 * Fires once OAuth sign-in has registered its hooks.
		 *
		 * Any other copy of the OAuth code checks this action and does not boot, so a site
		 * runs one authorization server.
		 *
		 * @since n.e.x.t
		 */
		do_action( 'mcp_adapter_oauth_init' );
	}

	/**
	 * Starts the endpoints and bearer authentication when sign-in is enabled.
	 *
	 * @return void
	 */
	public static function start(): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		Discovery::register();
		BearerAuth::register();
		Endpoints::register();
		AuthorizeScreen::register();
	}

	/**
	 * Whether OAuth sign-in is on for this site.
	 *
	 * @return bool True when every requirement is met and no filter turned it off.
	 */
	public static function is_enabled(): bool {
		if ( array() !== self::unmet_requirements() ) {
			return false;
		}

		/**
		 * Filters whether OAuth sign-in is offered once its requirements are met.
		 *
		 * Return false to keep the MCP server on while refusing OAuth, for example to
		 * allow only Application Passwords. A filter cannot turn sign-in on when a
		 * requirement is missing.
		 *
		 * @since n.e.x.t
		 *
		 * @param bool $enabled Whether sign-in is offered. Default true.
		 */
		return (bool) apply_filters( 'mcp_adapter_oauth_enabled', true );
	}

	/**
	 * The requirements this site does not meet, in plain language.
	 *
	 * @return list<string> One sentence per unmet requirement; empty when all are met.
	 */
	public static function unmet_requirements(): array {
		global $wp_rewrite;

		$unmet = array();
		if ( 'https' !== wp_parse_url( home_url(), PHP_URL_SCHEME ) && ! self::is_local() ) {
			$unmet[] = __( 'The site address must use HTTPS.', 'mcp-adapter' );
		}

		if ( ! $wp_rewrite instanceof \WP_Rewrite || ! $wp_rewrite->using_mod_rewrite_permalinks() ) {
			$unmet[] = __( 'Permalinks must use any structure other than Plain (Settings → Permalinks).', 'mcp-adapter' );
		}

		if ( '' !== trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' ) ) {
			$unmet[] = __( 'The site must be at the root of its domain. Sites in a subdirectory are not supported yet.', 'mcp-adapter' );
		}

		return $unmet;
	}

	/**
	 * Whether this request may carry tokens: HTTPS, or a local development site.
	 *
	 * @return bool True when tokens may be sent and accepted on this request.
	 */
	public static function is_secure_request(): bool {
		return is_ssl() || self::is_local();
	}

	/**
	 * How long an access token stays valid.
	 *
	 * @return int Seconds; at least 60.
	 */
	public static function access_token_ttl(): int {
		/**
		 * Filters how long an OAuth access token stays valid.
		 *
		 * @since n.e.x.t
		 *
		 * @param int $seconds Lifetime in seconds. Default one hour.
		 */
		return max( MINUTE_IN_SECONDS, (int) apply_filters( 'mcp_adapter_oauth_access_token_ttl', HOUR_IN_SECONDS ) );
	}

	/**
	 * How long a connection survives without a refresh.
	 *
	 * Every refresh restarts this window, so a client used at least this often stays
	 * connected until it is revoked or the user's password changes.
	 *
	 * @return int Seconds; at least one hour.
	 */
	public static function inactivity_ttl(): int {
		/**
		 * Filters how long an OAuth connection survives without a token refresh.
		 *
		 * @since n.e.x.t
		 *
		 * @param int $seconds Inactivity window in seconds. Default 30 days.
		 */
		return max( HOUR_IN_SECONDS, (int) apply_filters( 'mcp_adapter_oauth_refresh_token_ttl', 30 * DAY_IN_SECONDS ) );
	}

	/**
	 * The issuer identifier.
	 *
	 * @return string The issuer URL, without a trailing slash.
	 */
	public static function issuer(): string {
		return self::origin() . self::ISSUER_PATH;
	}

	/**
	 * The scheme, host and port of the site, taken from the configured home URL.
	 *
	 * Request headers never feed this, so a forged Host header cannot change an
	 * advertised URL. A default port is dropped, so every URL built here compares equal
	 * to the ones clients send.
	 *
	 * @return string The origin, for example `https://example.com`.
	 */
	public static function origin(): string {
		$parts = wp_parse_url( home_url() );

		return self::origin_of( is_array( $parts ) ? $parts : array() );
	}

	/**
	 * The well-known URL for an identifier, with the identifier's path inserted after
	 * the well-known segment (RFC 8414 section 3.1, RFC 9728 section 3.1).
	 *
	 * @param string $suffix     Well-known suffix, for example `oauth-protected-resource`.
	 * @param string $identifier Issuer or resource URL on this site.
	 * @return string The metadata URL.
	 */
	public static function well_known_url( string $suffix, string $identifier ): string {
		// The metadata must name the identifier exactly, so only a bare "/" path is dropped.
		$path = (string) wp_parse_url( $identifier, PHP_URL_PATH );

		return self::origin() . '/.well-known/' . $suffix . ( '/' === $path ? '' : $path );
	}

	/**
	 * Whether a `resource` parameter names this site.
	 *
	 * A token covers the whole REST API of the site, so any URL on the site's origin is
	 * an acceptable audience (RFC 8707). URLs on any other origin are refused.
	 *
	 * @param string $url The requested resource.
	 * @return bool True when the resource belongs to this site.
	 */
	public static function accepts_resource( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) || isset( $parts['fragment'] ) ) {
			return false;
		}

		return self::origin_of( $parts ) === self::origin();
	}

	/**
	 * The lowercase scheme, host and non-default port of parsed URL parts.
	 *
	 * @param array<string, mixed> $parts Parts from wp_parse_url().
	 * @return string The origin.
	 */
	private static function origin_of( array $parts ): string {
		$scheme  = strtolower( (string) ( $parts['scheme'] ?? 'https' ) );
		$default = 'https' === $scheme ? 443 : 80;
		$port    = isset( $parts['port'] ) && $default !== (int) $parts['port'] ? ':' . $parts['port'] : '';

		return $scheme . '://' . strtolower( (string) ( $parts['host'] ?? '' ) ) . $port;
	}

	/**
	 * Whether WordPress reports a local development environment.
	 *
	 * @return bool True on a local site.
	 */
	private static function is_local(): bool {
		return 'local' === wp_get_environment_type();
	}
}
