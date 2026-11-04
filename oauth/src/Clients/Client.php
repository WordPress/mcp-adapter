<?php
/**
 * OAuth client.
 *
 * @package WP\OAuth
 */

declare( strict_types=1 );

namespace WP\OAuth\Clients;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * A client registered by an administrator.
 */
final class Client {

	/**
	 * Hosts treated as loopback: core's list in
	 * wp_is_authorize_application_redirect_url_valid(), plus localhost, which
	 * desktop MCP clients use for their callback.
	 */
	// phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- False positive: sniff mistakes array() commas for multi-const commas.
	private const LOOPBACK_HOSTS = array(
		'127.0.0.1',
		'[::1]',
		'localhost',
	);

	/**
	 * Client identifier, a UUID.
	 *
	 * @var string
	 */
	private $id;

	/**
	 * Display name.
	 *
	 * @var string
	 */
	private $name;

	/**
	 * Registered redirect URIs.
	 *
	 * @var string[]
	 */
	private $redirect_uris;

	/**
	 * Hash of the client secret, or null for public clients.
	 *
	 * @var string|null
	 */
	private $secret_hash;

	/**
	 * Creation timestamp.
	 *
	 * @var int
	 */
	private $created;

	/**
	 * Constructor.
	 *
	 * @param string      $id            Client identifier.
	 * @param string      $name          Display name.
	 * @param string[]    $redirect_uris Registered redirect URIs.
	 * @param string|null $secret_hash   Hash of the client secret, or null.
	 * @param int         $created       Creation timestamp.
	 */
	public function __construct( string $id, string $name, array $redirect_uris, ?string $secret_hash, int $created ) {
		$this->id            = $id;
		$this->name          = $name;
		$this->redirect_uris = $redirect_uris;
		$this->secret_hash   = $secret_hash;
		$this->created       = $created;
	}

	/**
	 * Gets the client identifier.
	 */
	public function get_id(): string {
		return $this->id;
	}

	/**
	 * Gets the display name.
	 */
	public function get_name(): string {
		return $this->name;
	}

	/**
	 * Gets the registered redirect URIs.
	 *
	 * @return string[]
	 */
	public function get_redirect_uris(): array {
		return $this->redirect_uris;
	}

	/**
	 * Gets the creation timestamp.
	 */
	public function get_created(): int {
		return $this->created;
	}

	/**
	 * Whether the client must authenticate with a secret.
	 */
	public function is_confidential(): bool {
		return null !== $this->secret_hash;
	}

	/**
	 * Checks a client secret.
	 *
	 * @param string $secret Secret supplied by the client.
	 */
	public function verify_secret( string $secret ): bool {
		if ( null === $this->secret_hash || '' === $secret ) {
			return false;
		}

		return wp_verify_fast_hash( $secret, $this->secret_hash );
	}

	/**
	 * Whether a redirect URI is registered for this client.
	 *
	 * Matching is exact, except that loopback redirects may use any port, as
	 * native apps pick one at runtime (RFC 8252 section 7.3).
	 *
	 * @param string $uri Redirect URI from the request.
	 */
	public function allows_redirect_uri( string $uri ): bool {
		foreach ( $this->redirect_uris as $registered ) {
			if ( $registered === $uri || self::is_loopback_match( $registered, $uri ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether two loopback URIs match apart from the port.
	 *
	 * @param string $registered Registered URI.
	 * @param string $provided   URI from the request.
	 */
	private static function is_loopback_match( string $registered, string $provided ): bool {
		$a = wp_parse_url( $registered );
		$b = wp_parse_url( $provided );
		if ( ! is_array( $a ) || ! is_array( $b ) || ! self::is_http_loopback( $a ) || ! self::is_http_loopback( $b ) ) {
			return false;
		}

		return ( $a['host'] ?? '' ) === ( $b['host'] ?? '' )
			&& ( $a['path'] ?? '' ) === ( $b['path'] ?? '' )
			&& ( $a['query'] ?? '' ) === ( $b['query'] ?? '' )
			&& ! isset( $b['fragment'] );
	}

	/**
	 * Checks a URI before it is registered as a redirect URI.
	 *
	 * Uses core's check for Application Password redirect URLs, so the rules
	 * and the wp_authorize_application_redirect_url_invalid_protocols filter
	 * match core's own authorization flow. On top of that it allows HTTP on
	 * localhost, which core only allows on local sites, and rejects fragments
	 * (RFC 6749 section 3.1.2).
	 *
	 * @param string $uri URI to check.
	 * @return true|WP_Error True if the URI can be registered, or the reason it cannot.
	 */
	public static function validate_redirect_uri( string $uri ) {
		if ( '' === $uri || false !== strpos( $uri, '#' ) ) {
			return new WP_Error( 'oauth_server_redirect_uri', __( 'Redirect URIs must be absolute and cannot contain a fragment.', 'mcp-adapter' ) );
		}

		$parts = wp_parse_url( $uri );
		if ( is_array( $parts ) && self::is_http_loopback( $parts ) ) {
			return true;
		}

		if ( ! function_exists( 'wp_is_authorize_application_redirect_url_valid' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}

		return wp_is_authorize_application_redirect_url_valid( $uri );
	}

	/**
	 * Whether parsed URL parts are plain HTTP to a loopback host.
	 *
	 * @param array<string, mixed> $parts Parts from wp_parse_url().
	 */
	private static function is_http_loopback( array $parts ): bool {
		return 'http' === strtolower( (string) ( $parts['scheme'] ?? '' ) )
			&& in_array( strtolower( (string) ( $parts['host'] ?? '' ) ), self::LOOPBACK_HOSTS, true );
	}
}
