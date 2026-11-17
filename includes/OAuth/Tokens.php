<?php
/**
 * Signed tokens and the secrets they carry.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\OAuth;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Signs and verifies every token the authorization server hands out.
 *
 * A token is `base64url(JSON payload) . '.' . base64url(HMAC-SHA256)`. Each kind of token
 * (authorization code, access token, refresh token, dynamically registered client ID) has
 * its own key, derived from the site's `auth` salt, so one kind can never be replayed as
 * another and rotating the salts disconnects every client, as it logs out every cookie.
 *
 * Codes, access tokens and refresh tokens also carry a random secret whose SHA-256 digest
 * is stored on the connection. Someone who can read the database and the salts still
 * cannot mint a working token without that secret, the same protection core gives login
 * sessions.
 *
 * @since n.e.x.t
 */
final class Tokens {

	/**
	 * Authorization code.
	 *
	 * @var string
	 */
	public const CODE = 'c';

	/**
	 * Access token.
	 *
	 * @var string
	 */
	public const ACCESS = 'a';

	/**
	 * Refresh token.
	 *
	 * @var string
	 */
	public const REFRESH = 'r';

	/**
	 * Dynamically registered client ID.
	 *
	 * @var string
	 */
	public const CLIENT = 'd';

	/**
	 * Longest token accepted for verification, in bytes.
	 *
	 * @var int
	 */
	public const MAX_LENGTH = 4096;

	/**
	 * Signs a payload as a token of the given kind.
	 *
	 * @param string               $kind    One of the kind constants.
	 * @param array<string, mixed> $payload The claims.
	 * @return string The token.
	 */
	public static function sign( string $kind, array $payload ): string {
		$payload['t'] = $kind;
		$body         = self::encode( (string) wp_json_encode( $payload ) );

		return $body . '.' . self::encode( hash_hmac( 'sha256', $body, self::key( $kind ), true ) );
	}

	/**
	 * Verifies a token of the given kind and returns its claims.
	 *
	 * The signature is checked before the payload is decoded.
	 *
	 * @param string $kind  One of the kind constants.
	 * @param string $token The token.
	 * @return array<string, mixed>|null The claims, or null when the token is not valid.
	 */
	public static function verify( string $kind, string $token ): ?array {
		if ( strlen( $token ) > self::MAX_LENGTH ) {
			return null;
		}

		$parts = explode( '.', $token );
		if ( 2 !== count( $parts ) ) {
			return null;
		}

		$expected = self::encode( hash_hmac( 'sha256', $parts[0], self::key( $kind ), true ) );
		if ( ! hash_equals( $expected, $parts[1] ) ) {
			return null;
		}

		$claims = json_decode( self::decode( $parts[0] ), true );
		if ( ! is_array( $claims ) || ( $claims['t'] ?? null ) !== $kind ) {
			return null;
		}

		return $claims;
	}

	/**
	 * A new random secret.
	 *
	 * @return string 128 random bits, base64url encoded.
	 */
	public static function secret(): string {
		return self::encode( random_bytes( 16 ) );
	}

	/**
	 * The digest stored for a secret.
	 *
	 * @param string $secret The secret.
	 * @return string The SHA-256 digest, hex encoded.
	 */
	public static function digest( string $secret ): string {
		return hash( 'sha256', $secret );
	}

	/**
	 * Whether a secret matches a stored digest, in constant time.
	 *
	 * @param mixed $secret The secret from a token.
	 * @param mixed $digest The stored digest.
	 * @return bool True on a match.
	 */
	public static function matches( $secret, $digest ): bool {
		return is_string( $secret ) && is_string( $digest ) && '' !== $digest && hash_equals( $digest, self::digest( $secret ) );
	}

	/**
	 * A value tied to the user's current password hash.
	 *
	 * Stored on each connection and compared on every use, so changing the password
	 * disconnects every client, as it logs out every cookie.
	 *
	 * @param \WP_User $user The user.
	 * @return string The binding.
	 */
	public static function password_binding( \WP_User $user ): string {
		return substr( hash_hmac( 'sha256', (string) $user->user_pass, self::key( 'p' ) ), 0, 32 );
	}

	/**
	 * Encodes bytes as unpadded base64url.
	 *
	 * @param string $raw The bytes.
	 * @return string The text.
	 */
	public static function encode( string $raw ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Token encoding, not obfuscation.
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
	}

	/**
	 * Decodes unpadded base64url.
	 *
	 * @param string $text The text.
	 * @return string The bytes, or an empty string when the text is not base64url.
	 */
	private static function decode( string $text ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Token decoding, not obfuscation.
		$raw = base64_decode( strtr( $text, '-_', '+/' ), true );

		return false === $raw ? '' : $raw;
	}

	/**
	 * The signing key for one kind of token.
	 *
	 * @param string $kind The kind.
	 * @return string The raw key.
	 */
	private static function key( string $kind ): string {
		return hash_hmac( 'sha256', 'mcp-adapter-oauth|' . $kind, wp_salt( 'auth' ), true );
	}
}
