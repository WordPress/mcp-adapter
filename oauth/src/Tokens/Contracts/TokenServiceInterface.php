<?php
/**
 * Token service contract.
 *
 * @package WP\OAuth
 */

declare( strict_types=1 );

namespace WP\OAuth\Tokens\Contracts;

use WP\OAuth\Grants\AuthorizedGrant;
use WP\OAuth\Tokens\AuthenticatedToken;
use WP\OAuth\Tokens\TokenSet;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Issues, validates, and revokes OAuth credentials.
 *
 * Protocol components depend on this contract rather than a token format or
 * persistence mechanism. Implementations remain responsible for enforcing the
 * security properties of their credentials, including revocation.
 */
interface TokenServiceInterface {

	/**
	 * Issues credentials for an authorized user and client.
	 *
	 * @param AuthorizedGrant $grant Approved user, client snapshot, and resource.
	 * @return TokenSet|WP_Error Issued credentials, or an error.
	 */
	public function issue( AuthorizedGrant $grant );

	/**
	 * Rotates credentials using a refresh token.
	 *
	 * @param string $refresh_token Refresh token.
	 * @param string $client_id     Authenticated client identifier.
	 * @param string $resource_indicator Requested resource indicator.
	 * @return TokenSet|WP_Error Rotated credentials, or an error.
	 */
	public function refresh( string $refresh_token, string $client_id, string $resource_indicator );

	/**
	 * Whether this service recognizes a token's format.
	 *
	 * Tokens it does not recognize are left for other authentication providers.
	 *
	 * @param string $token Token.
	 */
	public function supports( string $token ): bool;

	/**
	 * Validates an access token and resolves its connection.
	 *
	 * @param string $token Token.
	 * @return AuthenticatedToken|WP_Error Validated token context, or an error.
	 */
	public function authenticate_access_token( string $token );

	/**
	 * Resolves a recognized token for RFC 7009 revocation.
	 *
	 * Unlike access-token authentication, this may accept an expired access
	 * token or a refresh token when an implementation supports them.
	 *
	 * @param string $token Token.
	 * @return AuthenticatedToken|WP_Error Validated token context, or an error.
	 */
	public function resolve_for_revocation( string $token );

	/**
	 * Records a successful use of a token.
	 *
	 * @param AuthenticatedToken $token Validated token.
	 */
	public function record_usage( AuthenticatedToken $token ): void;

	/**
	 * Revokes the connection represented by a validated token.
	 *
	 * @param AuthenticatedToken $token Validated token.
	 */
	public function revoke( AuthenticatedToken $token ): void;
}
