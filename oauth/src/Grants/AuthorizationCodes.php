<?php
/**
 * Authorization code store.
 *
 * @package WP\OAuth
 */

declare( strict_types=1 );

namespace WP\OAuth\Grants;

defined( 'ABSPATH' ) || exit;

/**
 * Issues short-lived, single-use authorization codes.
 *
 * Codes are stored as transients keyed by a hash of the code, so the stored
 * data cannot be used to recover a working code.
 */
final class AuthorizationCodes {

	private const PREFIX  = 'oauth_server_code_';
	public const LIFETIME = 60;

	/**
	 * Issues a code for an approved authorization request.
	 *
	 * @param array<string, mixed> $data Request details to bind to the code.
	 * @return string The code.
	 */
	public function issue( array $data ): string {
		$code            = bin2hex( random_bytes( 32 ) );
		$data['expires'] = time() + self::LIFETIME;

		set_transient( self::key( $code ), $data, self::LIFETIME );

		return $code;
	}

	/**
	 * Looks up a code and deletes it, so it can only be used once.
	 *
	 * @param string $code The code.
	 * @return array<string, mixed>|null The bound request details, or null if the code is unknown or expired.
	 */
	public function consume( string $code ): ?array {
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $code ) ) {
			return null;
		}

		$key  = self::key( $code );
		$data = get_transient( $key );
		delete_transient( $key );

		if ( ! is_array( $data ) || (int) ( $data['expires'] ?? 0 ) < time() ) {
			return null;
		}

		return $data;
	}

	/**
	 * Builds the transient key for a code.
	 *
	 * @param string $code The code.
	 */
	private static function key( string $code ): string {
		return self::PREFIX . hash( 'sha256', $code );
	}
}
