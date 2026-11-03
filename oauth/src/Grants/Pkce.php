<?php
/**
 * PKCE helpers.
 *
 * @package WP\OAuth
 */

declare( strict_types=1 );

namespace WP\OAuth\Grants;

defined( 'ABSPATH' ) || exit;

/**
 * Proof Key for Code Exchange (RFC 7636), S256 only.
 */
final class Pkce {

	/**
	 * Whether a value looks like an S256 code challenge.
	 *
	 * @param string $challenge Code challenge.
	 */
	public static function is_valid_challenge( string $challenge ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9_\-]{43}$/', $challenge );
	}

	/**
	 * Checks a code verifier against an S256 challenge.
	 *
	 * @param string $verifier  Code verifier from the token request.
	 * @param string $challenge Code challenge from the authorization request.
	 */
	public static function verify( string $verifier, string $challenge ): bool {
		if ( 1 !== preg_match( '/^[A-Za-z0-9\-._~]{43,128}$/', $verifier ) ) {
			return false;
		}

		$expected = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- base64url is required by RFC 7636.

		return hash_equals( $challenge, $expected );
	}
}
