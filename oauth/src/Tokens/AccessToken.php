<?php
/**
 * Access token format.
 *
 * @package WP\OAuth
 */

declare( strict_types=1 );

namespace WP\OAuth\Tokens;

defined( 'ABSPATH' ) || exit;

/**
 * Encodes and decodes access tokens.
 *
 * A token carries the user ID, the Application Password UUID, and the raw
 * Application Password. Verifying it uses the stored password hash, so tokens
 * need no extra storage or signing secret, and deleting the Application
 * Password revokes the token.
 */
final class AccessToken {

	public const PREFIX = 'wpoa_';

	/**
	 * Encodes a token.
	 *
	 * @param int    $user_id  User ID.
	 * @param string $uuid     Application Password UUID.
	 * @param string $password Raw Application Password.
	 */
	public static function encode( int $user_id, string $uuid, string $password ): string {
		$payload = $user_id . ':' . $uuid . ':' . $password;

		return self::PREFIX . rtrim( strtr( base64_encode( $payload ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Token encoding.
	}

	/**
	 * Whether a string looks like one of our tokens.
	 *
	 * @param string $token Token.
	 */
	public static function is_ours( string $token ): bool {
		return 0 === strpos( $token, self::PREFIX );
	}

	/**
	 * Decodes a token.
	 *
	 * @param string $token Token.
	 * @return array{user_id: int, uuid: string, password: string}|null The parts, or null if malformed.
	 */
	public static function decode( string $token ): ?array {
		if ( ! self::is_ours( $token ) ) {
			return null;
		}

		$decoded = base64_decode( strtr( substr( $token, strlen( self::PREFIX ) ), '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Token decoding.
		if ( false === $decoded ) {
			return null;
		}

		$parts = explode( ':', $decoded, 3 );
		if ( 3 !== count( $parts ) || ! ctype_digit( $parts[0] ) || ! wp_is_uuid( $parts[1], 4 ) || '' === $parts[2] ) {
			return null;
		}

		return array(
			'user_id'  => (int) $parts[0],
			'uuid'     => $parts[1],
			'password' => $parts[2],
		);
	}
}
