<?php
/**
 * OAuth client.
 *
 * @package WP\OAuth
 */

declare( strict_types=1 );

namespace WP\OAuth\Clients;

defined( 'ABSPATH' ) || exit;

/**
 * A client registered by an administrator.
 */
final class Client {

	// phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- False positive: sniff mistakes array() commas for multi-const commas.
	private const LOOPBACK_HOSTS = array(
		'127.0.0.1',
		'localhost',
		'[::1]',
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
	 * Builds a client from stored data.
	 *
	 * @param mixed $data Stored data.
	 * @return self|null The client, or null if the data is malformed.
	 */
	public static function from_array( $data ): ?self {
		if ( ! is_array( $data ) || empty( $data['id'] ) || empty( $data['name'] ) || empty( $data['redirect_uris'] ) || ! is_array( $data['redirect_uris'] ) ) {
			return null;
		}

		return new self(
			(string) $data['id'],
			(string) $data['name'],
			array_map( 'strval', $data['redirect_uris'] ),
			isset( $data['secret_hash'] ) ? (string) $data['secret_hash'] : null,
			(int) ( $data['created'] ?? 0 )
		);
	}

	/**
	 * Converts the client to storable data.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'            => $this->id,
			'name'          => $this->name,
			'redirect_uris' => $this->redirect_uris,
			'secret_hash'   => $this->secret_hash,
			'created'       => $this->created,
		);
	}

	/**
	 * Gets the client identifier.
	 */
	public function get_id(): string {
		return $this->id;
	}

	/**
	 * Returns a copy with a new name and redirect URIs.
	 *
	 * @param string   $name          Display name.
	 * @param string[] $redirect_uris Redirect URIs.
	 */
	public function with_details( string $name, array $redirect_uris ): self {
		return new self( $this->id, $name, $redirect_uris, $this->secret_hash, $this->created );
	}

	/**
	 * Returns a copy with a new secret hash, or none to make it a public client.
	 *
	 * @param string|null $secret_hash Hash of the client secret, or null.
	 */
	public function with_secret_hash( ?string $secret_hash ): self {
		return new self( $this->id, $this->name, $this->redirect_uris, $secret_hash, $this->created );
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
		if ( ! is_array( $a ) || ! is_array( $b ) ) {
			return false;
		}

		foreach ( array( $a, $b ) as $parts ) {
			if ( 'http' !== ( $parts['scheme'] ?? '' ) || ! in_array( $parts['host'] ?? '', self::LOOPBACK_HOSTS, true ) ) {
				return false;
			}
		}

		return ( $a['host'] ?? '' ) === ( $b['host'] ?? '' )
			&& ( $a['path'] ?? '' ) === ( $b['path'] ?? '' )
			&& ( $a['query'] ?? '' ) === ( $b['query'] ?? '' )
			&& ! isset( $b['fragment'] );
	}

	/**
	 * Whether a URI is acceptable to register as a redirect URI.
	 *
	 * Allows HTTPS, HTTP on loopback hosts, and private-use schemes for native
	 * apps. Fragments are not allowed (RFC 6749 section 3.1.2).
	 *
	 * @param string $uri URI to check.
	 */
	public static function is_valid_redirect_uri( string $uri ): bool {
		$parts = wp_parse_url( $uri );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || isset( $parts['fragment'] ) ) {
			return false;
		}

		$scheme = strtolower( $parts['scheme'] );
		if ( 'https' === $scheme ) {
			return ! empty( $parts['host'] );
		}

		if ( 'http' === $scheme ) {
			return in_array( $parts['host'] ?? '', self::LOOPBACK_HOSTS, true );
		}

		// Schemes that can run code, load local content, or are not app callbacks.
		$blocked = array( 'javascript', 'data', 'vbscript', 'file', 'blob', 'about', 'ftp', 'ws', 'wss', 'mailto', 'tel' );

		return ! in_array( $scheme, $blocked, true ) && 1 === preg_match( '/^[a-z][a-z0-9+.\-]*$/', $scheme );
	}
}
