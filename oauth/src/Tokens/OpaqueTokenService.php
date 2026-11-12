<?php
/**
 * Opaque OAuth token service.
 *
 * @package WP\OAuth
 */

declare( strict_types=1 );

namespace WP\OAuth\Tokens;

use WP\OAuth\Grants\AuthorizedGrant;
use WP\OAuth\Tokens\Contracts\ConnectionStoreInterface;
use WP\OAuth\Tokens\Contracts\TokenServiceInterface;
use WP_Error;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Issues random opaque credentials and persists only their SHA-256 hashes.
 */
final class OpaqueTokenService implements TokenServiceInterface {

	public const ACCESS_PREFIX    = 'wpoa_at_';
	public const REFRESH_PREFIX   = 'wpoa_rt_';
	public const ACCESS_LIFETIME  = HOUR_IN_SECONDS;
	public const REFRESH_LIFETIME = 30 * DAY_IN_SECONDS;

	/** @var ConnectionStoreInterface */
	private $connections;

	public function __construct( ConnectionStoreInterface $connections ) {
		$this->connections = $connections;
	}

	/** {@inheritDoc} */
	public function issue( AuthorizedGrant $grant ) {
		$connection = $this->connections->create( $grant );
		if ( is_wp_error( $connection ) ) {
			return $connection;
		}

		$result = $this->rotate( $connection );
		if ( is_wp_error( $result ) ) {
			$this->connections->revoke( $connection );
		}

		return $result;
	}

	/** {@inheritDoc} */
	public function refresh( string $refresh_token, string $client_id, string $resource_indicator ) {
		$parsed = $this->parse( $refresh_token, self::REFRESH_PREFIX );
		if ( null === $parsed ) {
			return $this->invalid( 'invalid_grant', __( 'The refresh token is invalid or expired.', 'mcp-adapter' ) );
		}

		[ $user_id, $connection_id ] = $parsed;
		$connection                  = $this->connections->get( $user_id, $connection_id );
		if ( null === $connection || ! hash_equals( $connection->get_client_id(), $client_id ) ) {
			return $this->invalid( 'invalid_grant', __( 'The refresh token is invalid or expired.', 'mcp-adapter' ) );
		}

		$data = $connection->get_data();
		$hash = self::hash( $refresh_token );
		if ( ! empty( $data['previous_refresh_hash'] ) && hash_equals( (string) $data['previous_refresh_hash'], $hash ) ) {
			$this->connections->revoke( $connection );
			return $this->invalid( 'invalid_grant', __( 'Refresh token reuse was detected and the connection was revoked.', 'mcp-adapter' ) );
		}
		if ( empty( $data['refresh_hash'] ) || ! hash_equals( (string) $data['refresh_hash'], $hash ) || (int) ( $data['refresh_expires_at'] ?? 0 ) < time() ) {
			return $this->invalid( 'invalid_grant', __( 'The refresh token is invalid or expired.', 'mcp-adapter' ) );
		}
		$resource           = $connection->get_resource();
		$resources_mismatch = '' === $resource || '' === $resource_indicator
			? ! hash_equals( $resource, $resource_indicator )
			: ! hash_equals( untrailingslashit( $resource ), untrailingslashit( $resource_indicator ) );
		if ( $resources_mismatch ) {
			return $this->invalid( 'invalid_target', __( 'The refresh token was not issued for this resource.', 'mcp-adapter' ) );
		}

		$result = $this->rotate( $connection );
		if ( ! is_wp_error( $result ) ) {
			return $result;
		}

		// A competing refresh may have rotated this exact token first. Recognize
		// that replay after reloading and revoke the token family.
		$current = $this->connections->get( $user_id, $connection_id );
		if ( null !== $current && hash_equals( (string) ( $current->get_data()['previous_refresh_hash'] ?? '' ), $hash ) ) {
			$this->connections->revoke( $current );
		}

		return $this->invalid( 'invalid_grant', __( 'The refresh token has already been used.', 'mcp-adapter' ) );
	}

	public function supports( string $token ): bool {
		return 0 === strpos( $token, self::ACCESS_PREFIX ) || 0 === strpos( $token, self::REFRESH_PREFIX );
	}

	/** {@inheritDoc} */
	public function authenticate_access_token( string $token ) {
		$parsed = $this->parse( $token, self::ACCESS_PREFIX );
		if ( null === $parsed ) {
			return $this->invalid_token();
		}

		[ $user_id, $connection_id ] = $parsed;
		$connection                  = $this->connections->get( $user_id, $connection_id );
		if ( null === $connection ) {
			return $this->invalid_token();
		}

		$data     = $connection->get_data();
		$hash     = self::hash( $token );
		$valid    = ! empty( $data['access_hash'] )
			&& hash_equals( (string) $data['access_hash'], $hash )
			&& (int) ( $data['access_expires_at'] ?? 0 ) >= time();
		$previous = ! empty( $data['previous_access_hash'] )
			&& hash_equals( (string) $data['previous_access_hash'], $hash )
			&& (int) ( $data['previous_access_expires_at'] ?? 0 ) >= time();
		if ( ! $valid && ! $previous ) {
			return $this->invalid_token();
		}

		$user = get_userdata( $user_id );
		if ( ! $user instanceof WP_User || ! wp_is_application_passwords_available_for_user( $user ) ) {
			return $this->invalid_token();
		}

		return new AuthenticatedToken( $user, $connection->get_client_id(), $connection_id, $connection->get_resource(), $data );
	}

	/** {@inheritDoc} */
	public function resolve_for_revocation( string $token ) {
		$prefix = 0 === strpos( $token, self::ACCESS_PREFIX ) ? self::ACCESS_PREFIX : self::REFRESH_PREFIX;
		$parsed = $this->parse( $token, $prefix );
		if ( null === $parsed ) {
			return $this->invalid_token();
		}
		[ $user_id, $connection_id ] = $parsed;
		$connection                  = $this->connections->get( $user_id, $connection_id );
		if ( null === $connection ) {
			return $this->invalid_token();
		}
		$hash         = self::hash( $token );
		$known_hashes = array_intersect_key(
			$connection->get_data(),
			array_flip( array( 'access_hash', 'previous_access_hash', 'refresh_hash', 'previous_refresh_hash' ) )
		);
		foreach ( $known_hashes as $known_hash ) {
			if ( is_string( $known_hash ) && hash_equals( $known_hash, $hash ) ) {
				$user = get_userdata( $user_id );
				return $user instanceof WP_User
					? new AuthenticatedToken( $user, $connection->get_client_id(), $connection_id, $connection->get_resource(), $connection->get_data() )
					: $this->invalid_token();
			}
		}

		return $this->invalid_token();
	}

	public function record_usage( AuthenticatedToken $token ): void {
		$this->connections->record_usage( $this->connection_from_token( $token ) );
	}

	public function revoke( AuthenticatedToken $token ): void {
		$this->connections->revoke( $this->connection_from_token( $token ) );
	}

	/** @return TokenSet|WP_Error */
	private function rotate( Connection $connection ) {
		$access  = $this->mint( self::ACCESS_PREFIX, $connection );
		$refresh = $this->mint( self::REFRESH_PREFIX, $connection );
		$now     = time();
		$data    = $connection->get_data();
		$updated = $connection->with(
			array(
				'previous_access_hash'       => (string) ( $data['access_hash'] ?? '' ),
				'previous_access_expires_at' => (int) ( $data['access_expires_at'] ?? 0 ),
				'access_hash'                => self::hash( $access ),
				'access_expires_at'          => $now + self::ACCESS_LIFETIME,
				'previous_refresh_hash'      => (string) ( $data['refresh_hash'] ?? '' ),
				'refresh_hash'               => self::hash( $refresh ),
				'refresh_expires_at'         => $now + self::REFRESH_LIFETIME,
			)
		);

		if ( ! $this->connections->compare_and_swap( $connection, $updated ) ) {
			return new WP_Error( 'oauth_server_token_race', __( 'The connection changed while tokens were being issued.', 'mcp-adapter' ) );
		}

		return new TokenSet( $access, 'Bearer', $refresh, self::ACCESS_LIFETIME );
	}

	private function mint( string $prefix, Connection $connection ): string {
		$secret = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Opaque random credential.
		return $prefix . $connection->get_user_id() . '.' . $connection->get_id() . '.' . $secret;
	}

	/** @return array{0: int, 1: string}|null */
	private function parse( string $token, string $prefix ): ?array {
		if ( 1 !== preg_match( '/^' . preg_quote( $prefix, '/' ) . '([1-9][0-9]*)\.([a-f0-9-]{36})\.[A-Za-z0-9_-]{43}$/', $token, $matches ) || ! wp_is_uuid( $matches[2], 4 ) ) {
			return null;
		}
		return array( (int) $matches[1], $matches[2] );
	}

	private static function hash( string $token ): string {
		return hash( 'sha256', $token );
	}

	private function connection_from_token( AuthenticatedToken $token ): Connection {
		return new Connection( $token->get_user()->ID, $token->get_credential_id(), $token->get_credential() );
	}

	private function invalid_token(): WP_Error {
		return $this->invalid( 'oauth_server_invalid_token', __( 'The access token is invalid or has been revoked.', 'mcp-adapter' ), 401 );
	}

	private function invalid( string $code, string $message, int $status = 400 ): WP_Error {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}
}
