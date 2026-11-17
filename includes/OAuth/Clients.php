<?php
/**
 * Identifies OAuth clients and checks their return addresses.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\OAuth;

use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Resolves the two kinds of client MCP apps use, both public (no client secret).
 *
 * - **Client ID Metadata Document.** The client ID is an HTTPS URL to a JSON document the
 *   app publishes (Claude, ChatGPT, Codex, VS Code). It is fetched once, when the consent
 *   screen loads, with the connection pinned to an address checked as public so the fetch
 *   cannot reach the site's own network.
 * - **Dynamic registration (RFC 7591).** The app registers itself (Cursor). Nothing is
 *   stored: the name and return addresses are packed into the client ID and signed
 *   ({@see Tokens::CLIENT}), with size caps that keep the ID bounded.
 *
 * Return addresses must be HTTPS, plain HTTP on a loopback host, or an app's own scheme
 * such as `cursor://` ({@see is_app_scheme()}). They match exactly, except that a loopback
 * address may use any port (RFC 8252 section 7.3).
 *
 * @since n.e.x.t
 */
final class Clients {

	/**
	 * Prefix of dynamically registered client IDs.
	 *
	 * @var string
	 */
	public const DCR_PREFIX = 'dcr.';

	/**
	 * Largest metadata document accepted, in bytes.
	 *
	 * @var int
	 */
	private const MAX_DOCUMENT = 5120;

	/**
	 * Most return addresses a dynamically registered client may declare.
	 *
	 * @var int
	 */
	private const MAX_DCR_REDIRECTS = 5;

	/**
	 * Longest return address accepted, in bytes.
	 *
	 * @var int
	 */
	private const MAX_REDIRECT_LENGTH = 512;

	/**
	 * Longest client name kept, in characters.
	 *
	 * @var int
	 */
	private const MAX_NAME_LENGTH = 100;

	/**
	 * Loopback hosts allowed with plain HTTP.
	 *
	 * @var list<string>
	 */
	// phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- False positive: sniff mistakes array() commas for multi-const commas (only handles short syntax).
	private const LOOPBACK_HOSTS = array( 'localhost', '127.0.0.1', '[::1]' );

	/**
	 * Schemes a browser acts on itself instead of handing to an app, refused as return
	 * addresses.
	 *
	 * @var list<string>
	 */
	// phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- False positive: sniff mistakes array() commas for multi-const commas (only handles short syntax).
	private const BROWSER_SCHEMES = array( 'about', 'blob', 'data', 'file', 'filesystem', 'ftp', 'javascript', 'vbscript', 'view-source', 'ws', 'wss' );

	/**
	 * IPv4 ranges a metadata fetch must never reach, beyond PHP's private and reserved
	 * ranges: shared address space, IETF protocol assignments, documentation, benchmarking
	 * and multicast.
	 *
	 * @var list<string>
	 */
	// phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- False positive: sniff mistakes array() commas for multi-const commas (only handles short syntax).
	private const BLOCKED_RANGES = array(
		'100.64.0.0/10',
		'192.0.0.0/24',
		'192.0.2.0/24',
		'198.18.0.0/15',
		'198.51.100.0/24',
		'203.0.113.0/24',
		'224.0.0.0/4',
	);

	/**
	 * Resolves a client ID to the app's name and return addresses.
	 *
	 * @param string $client_id The client ID from the authorization request.
	 * @return array{id:string,name:string,redirect_uris:list<string>,verified:bool}|\WP_Error The client;
	 *         `verified` is true for a metadata document, whose host vouches for the app.
	 */
	public static function resolve( string $client_id ) {
		if ( 0 === strpos( $client_id, self::DCR_PREFIX ) ) {
			$claims = Tokens::verify( Tokens::CLIENT, substr( $client_id, strlen( self::DCR_PREFIX ) ) );
			if ( null === $claims || ! is_string( $claims['n'] ?? null ) || ! is_array( $claims['r'] ?? null ) ) {
				return new WP_Error( 'invalid_client', __( 'This app registration is not valid on this site.', 'mcp-adapter' ) );
			}

			return array(
				'id'            => $client_id,
				'name'          => $claims['n'],
				'redirect_uris' => array_values( array_filter( $claims['r'], 'is_string' ) ),
				'verified'      => false,
			);
		}

		if ( 0 === strpos( $client_id, 'https://' ) ) {
			return self::from_metadata_document( $client_id );
		}

		return new WP_Error( 'invalid_client', __( 'This app is not registered on this site.', 'mcp-adapter' ) );
	}

	/**
	 * Registers a client (RFC 7591) without storing anything.
	 *
	 * Every client is registered as public: whatever authentication method it asks for,
	 * the response reports `none`, which RFC 7591 section 3.2.1 allows.
	 *
	 * @param array<string, mixed> $metadata The registration request.
	 * @return array<string, mixed>|\WP_Error The registration response, or an RFC 7591 error
	 *         whose code is `invalid_redirect_uri` or `invalid_client_metadata`.
	 */
	public static function register( array $metadata ) {
		$uris = $metadata['redirect_uris'] ?? null;
		if ( ! is_array( $uris ) || array() === $uris || count( $uris ) > self::MAX_DCR_REDIRECTS ) {
			/* translators: %d: maximum number of redirect URIs. */
			return new WP_Error( 'invalid_redirect_uri', sprintf( __( 'Send between 1 and %d redirect URIs.', 'mcp-adapter' ), self::MAX_DCR_REDIRECTS ) );
		}

		// RFC 7591 lets the server drop values it refuses; the client gets the kept ones back.
		$uris = self::acceptable_redirects( $uris );
		if ( array() === $uris ) {
			return new WP_Error( 'invalid_redirect_uri', __( 'At least one redirect URI must be HTTPS, HTTP on localhost, 127.0.0.1 or [::1], or the app\'s own scheme, without a fragment.', 'mcp-adapter' ) );
		}

		if ( ! self::declares( $metadata, 'grant_types', 'authorization_code' ) || ! self::declares( $metadata, 'response_types', 'code' ) ) {
			return new WP_Error( 'invalid_client_metadata', __( 'This server supports only the authorization code flow.', 'mcp-adapter' ) );
		}

		$name = self::name( $metadata['client_name'] ?? null );
		if ( '' === $name ) {
			$name = self::return_label( $uris[0] );
		}

		$signed = Tokens::sign(
			Tokens::CLIENT,
			array(
				'n' => $name,
				'r' => $uris,
			)
		);
		if ( strlen( $signed ) > Tokens::MAX_LENGTH ) {
			return new WP_Error( 'invalid_client_metadata', __( 'The client name and redirect URIs are too long together.', 'mcp-adapter' ) );
		}

		return array(
			'client_id'                  => self::DCR_PREFIX . $signed,
			'client_id_issued_at'        => time(),
			'client_name'                => $name,
			'redirect_uris'              => $uris,
			'grant_types'                => array( 'authorization_code', 'refresh_token' ),
			'response_types'             => array( 'code' ),
			'token_endpoint_auth_method' => 'none',
		);
	}

	/**
	 * Picks the return address for an authorization request.
	 *
	 * @param list<string> $registered The client's registered return addresses.
	 * @param string|null  $requested  The `redirect_uri` parameter, or null when absent.
	 * @return string|null The address to use, or null when the request names none that matches.
	 */
	public static function match_redirect( array $registered, ?string $requested ): ?string {
		if ( null === $requested ) {
			// Omitting it is allowed only when exactly one fixed address is registered.
			return 1 === count( $registered ) && ! self::is_loopback( $registered[0] ) ? $registered[0] : null;
		}

		if ( ! self::is_allowed_redirect( $requested ) ) {
			return null;
		}

		foreach ( $registered as $uri ) {
			if ( $uri === $requested ) {
				return $requested;
			}

			if ( self::is_loopback( $uri ) && self::is_loopback( $requested ) && self::without_port( $uri ) === self::without_port( $requested ) ) {
				return $requested;
			}
		}

		return null;
	}

	/**
	 * Whether a return address is plain HTTP on a loopback host.
	 *
	 * @param string $uri The address.
	 * @return bool True for an app on the user's own computer.
	 */
	public static function is_loopback( string $uri ): bool {
		$parts = wp_parse_url( $uri );

		return is_array( $parts )
			&& 'http' === strtolower( (string) ( $parts['scheme'] ?? '' ) )
			&& in_array( strtolower( (string) ( $parts['host'] ?? '' ) ), self::LOOPBACK_HOSTS, true );
	}

	/**
	 * Whether a return address uses an app's own scheme (RFC 8252 section 7.1), such as
	 * `cursor://anysphere.cursor-mcp/oauth/callback` or `com.example.app:/callback`.
	 *
	 * MCP spec deviation: the authorization spec asks for HTTPS or localhost only. App
	 * schemes are accepted anyway, because Cursor returns through `cursor://`. Another app
	 * on the computer could claim the same scheme, but PKCE keeps an intercepted code
	 * useless, and a malicious client gains nothing it could not get from an HTTPS address
	 * it owns.
	 *
	 * @param string $uri The address.
	 * @return bool True for a scheme other than HTTP, HTTPS and the browser's own schemes.
	 */
	public static function is_app_scheme( string $uri ): bool {
		$scheme = self::scheme( $uri );

		return '' !== $scheme && ! in_array( $scheme, array( 'http', 'https' ), true ) && ! in_array( $scheme, self::BROWSER_SCHEMES, true );
	}

	/**
	 * How the consent screen and the profile name a return address.
	 *
	 * @param string $uri The address.
	 * @return string The host; for an app's own scheme, the scheme and any host, such as
	 *                `cursor://anysphere.cursor-mcp`.
	 */
	public static function return_label( string $uri ): string {
		$host = (string) wp_parse_url( $uri, PHP_URL_HOST );
		if ( ! self::is_app_scheme( $uri ) ) {
			return $host;
		}

		return self::scheme( $uri ) . ':' . ( '' === $host ? '' : '//' . $host );
	}

	/**
	 * Whether this site can fetch metadata documents safely.
	 *
	 * The fetch is pinned to a checked address through cURL, which a proxy would bypass.
	 *
	 * @return bool True when the cURL transport is available and no proxy is configured.
	 */
	public static function can_fetch_metadata(): bool {
		return function_exists( 'curl_init' ) && defined( 'CURLOPT_RESOLVE' ) && ! defined( 'WP_PROXY_HOST' )
			&& ! ( defined( 'WP_HTTP_BLOCK_EXTERNAL' ) && WP_HTTP_BLOCK_EXTERNAL );
	}

	/**
	 * Fetches and validates a Client ID Metadata Document.
	 *
	 * @param string $url The client ID.
	 * @return array{id:string,name:string,redirect_uris:list<string>,verified:bool}|\WP_Error The client.
	 */
	private static function from_metadata_document( string $url ) {
		$failed = new WP_Error( 'invalid_client', __( 'The app\'s published details could not be loaded.', 'mcp-adapter' ) );
		if ( ! self::can_fetch_metadata() || ! self::is_metadata_url( $url ) ) {
			return $failed;
		}

		$body = self::fetch_pinned( $url );
		if ( null === $body ) {
			return $failed;
		}

		$document = json_decode( $body, true );
		if ( ! is_array( $document ) || ( $document['client_id'] ?? null ) !== $url ) {
			return $failed;
		}

		$name = self::name( $document['client_name'] ?? null );
		$uris = $document['redirect_uris'] ?? null;
		if ( '' === $name || ! is_array( $uris ) || array() === $uris || count( $uris ) > 20 ) {
			return $failed;
		}

		$uris = self::acceptable_redirects( $uris );
		if ( array() === $uris ) {
			return $failed;
		}

		if ( ! self::is_public_client( $document ) || ! self::declares( $document, 'grant_types', 'authorization_code' ) || ! self::declares( $document, 'response_types', 'code' ) ) {
			return $failed;
		}

		return array(
			'id'            => $url,
			'name'          => $name,
			'redirect_uris' => $uris,
			'verified'      => true,
		);
	}

	/**
	 * Whether a metadata document allows public-client use.
	 *
	 * The plural `token_endpoint_auth_methods_supported` list wins when present and must
	 * include `none`; ChatGPT prefers `private_key_jwt` but lists `none` there. Otherwise
	 * the standard field must be absent or `none`.
	 *
	 * @param array<string, mixed> $document The document.
	 * @return bool True when the app can connect without a client secret.
	 */
	private static function is_public_client( array $document ): bool {
		if ( isset( $document['token_endpoint_auth_methods_supported'] ) ) {
			return is_array( $document['token_endpoint_auth_methods_supported'] )
				&& in_array( 'none', $document['token_endpoint_auth_methods_supported'], true );
		}

		return ! isset( $document['token_endpoint_auth_method'] ) || 'none' === $document['token_endpoint_auth_method'];
	}

	/**
	 * Whether optional client metadata, when present, includes a required value.
	 *
	 * Extra values are ignored, such as Claude's `jwt-bearer` grant type.
	 *
	 * @param array<string, mixed> $metadata The metadata.
	 * @param string               $field    `grant_types` or `response_types`.
	 * @param string               $value    The required value.
	 * @return bool True when the field is absent or includes the value.
	 */
	private static function declares( array $metadata, string $field, string $value ): bool {
		return ! isset( $metadata[ $field ] ) || ( is_array( $metadata[ $field ] ) && in_array( $value, $metadata[ $field ], true ) );
	}

	/**
	 * Whether a client ID URL is acceptable for a metadata document.
	 *
	 * HTTPS with a path, and no credentials, fragment or dot segments.
	 *
	 * @param string $url The client ID.
	 * @return bool True when acceptable.
	 */
	private static function is_metadata_url( string $url ): bool {
		$parts = wp_parse_url( $url );

		return strlen( $url ) <= 2048
			&& is_array( $parts )
			&& 'https' === ( $parts['scheme'] ?? '' )
			&& '' !== (string) ( $parts['host'] ?? '' )
			&& ! isset( $parts['user'] )
			&& ! isset( $parts['pass'] )
			&& false === strpos( $url, '#' )
			&& '/' !== ( $parts['path'] ?? '/' )
			&& 1 !== preg_match( '#/\.\.?(/|$)#', (string) $parts['path'] );
	}

	/**
	 * Fetches a URL with the connection pinned to a public IPv4 address.
	 *
	 * The host is resolved once here and checked; cURL is then told to use exactly that
	 * address, so a DNS answer that changes between the check and the request cannot
	 * redirect the fetch inward. Proxies are turned off for the request, including one
	 * from an `https_proxy` environment variable or another plugin, because a proxy
	 * resolves the host itself and would ignore the pin. The request is refused unless
	 * the pin was applied.
	 *
	 * @param string $url The URL.
	 * @return string|null The response body, or null on any failure.
	 */
	private static function fetch_pinned( string $url ): ?string {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		$port = (int) ( wp_parse_url( $url, PHP_URL_PORT ) ?? 443 );
		$ips  = gethostbynamel( $host );
		if ( false === $ips || ! isset( $ips[0] ) || ! self::is_public_ipv4( $ips[0] ) ) {
			return null;
		}

		$address = $ips[0];
		$pinned  = false;
		$pin     = static function ( $handle, $request, $target ) use ( $url, $host, $port, $address, &$pinned ): void {
			if ( $target !== $url ) {
				return;
			}

			// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Pinning needs the raw handle.
			curl_setopt( $handle, CURLOPT_RESOLVE, array( $host . ':' . $port . ':' . $address ) );
			curl_setopt( $handle, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4 );
			// An empty string turns off every proxy, including environment variables.
			curl_setopt( $handle, CURLOPT_PROXY, '' );
			// phpcs:enable WordPress.WP.AlternativeFunctions.curl_curl_setopt
			$pinned = true;
		};

		add_action( 'http_api_curl', $pin, PHP_INT_MAX, 3 );
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 3,
				'redirection'         => 0,
				'limit_response_size' => self::MAX_DOCUMENT,
				'headers'             => array( 'Accept' => 'application/json' ),
			)
		);
		remove_action( 'http_api_curl', $pin, PHP_INT_MAX );

		if ( ! $pinned || is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body = wp_remote_retrieve_body( $response );

		// A body at the limit was probably cut short.
		return strlen( $body ) >= self::MAX_DOCUMENT ? null : $body;
	}

	/**
	 * Whether an IPv4 address is publicly routable.
	 *
	 * @param string $ip The address.
	 * @return bool True when public.
	 */
	private static function is_public_ipv4( string $ip ): bool {
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return false;
		}

		$long = (int) ip2long( $ip );
		foreach ( self::BLOCKED_RANGES as $range ) {
			[ $network, $bits ] = explode( '/', $range );
			$mask               = -1 << 32 - (int) $bits;
			if ( ( $long & $mask ) === ( (int) ip2long( $network ) & $mask ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The return addresses in a client's list that are acceptable, without duplicates.
	 *
	 * Apps often list more addresses than they use. The unacceptable ones are dropped
	 * rather than refusing the app, and a request that later names a dropped one fails at
	 * the consent screen.
	 *
	 * @param array<mixed> $uris The declared addresses.
	 * @return list<string> The acceptable addresses.
	 */
	private static function acceptable_redirects( array $uris ): array {
		$kept = array();
		foreach ( $uris as $uri ) {
			if ( ! is_string( $uri ) || ! self::is_allowed_redirect( $uri ) || in_array( $uri, $kept, true ) ) {
				continue;
			}

			$kept[] = $uri;
		}

		return $kept;
	}

	/**
	 * Whether a return address is acceptable at all.
	 *
	 * The address must be printable ASCII (an internationalized domain in its punycode
	 * form), so the consent screen never shows a lookalike Unicode host.
	 *
	 * @param string $uri The address.
	 * @return bool True for HTTPS with a host, HTTP on a loopback host, or an app's own
	 *              scheme, without credentials or fragment.
	 */
	private static function is_allowed_redirect( string $uri ): bool {
		$parts = wp_parse_url( $uri );
		if ( strlen( $uri ) > self::MAX_REDIRECT_LENGTH || ! is_array( $parts ) || 1 === preg_match( '/[^\x21-\x7e]/', $uri ) || false !== strpos( $uri, '#' ) || isset( $parts['user'] ) ) {
			return false;
		}

		if ( 'https' === self::scheme( $uri ) ) {
			return '' !== (string) ( $parts['host'] ?? '' );
		}

		return self::is_loopback( $uri ) || self::is_app_scheme( $uri );
	}

	/**
	 * The scheme of an address, lowercased.
	 *
	 * @param string $uri The address.
	 * @return string The scheme, or empty when the address has no valid one (RFC 3986).
	 */
	private static function scheme( string $uri ): string {
		return 1 === preg_match( '/^([a-z][a-z0-9+.\-]*):/i', $uri, $match ) ? strtolower( $match[1] ) : '';
	}

	/**
	 * A return address without its port, for loopback comparison.
	 *
	 * @param string $uri The address.
	 * @return string Scheme, host, path and query.
	 */
	private static function without_port( string $uri ): string {
		$parts = (array) wp_parse_url( $uri );

		return strtolower( (string) ( $parts['scheme'] ?? '' ) ) . '://' . strtolower( (string) ( $parts['host'] ?? '' ) )
			. ( $parts['path'] ?? '' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
	}

	/**
	 * A display-safe client name.
	 *
	 * @param mixed $name The declared name.
	 * @return string The name as plain text, shortened; empty when absent.
	 */
	private static function name( $name ): string {
		if ( ! is_string( $name ) ) {
			return '';
		}

		return mb_substr( trim( sanitize_text_field( $name ) ), 0, self::MAX_NAME_LENGTH );
	}
}
