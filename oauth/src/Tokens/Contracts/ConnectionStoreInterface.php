<?php
/**
 * OAuth connection persistence contract.
 *
 * @package WP\OAuth
 */

declare( strict_types=1 );

namespace WP\OAuth\Tokens\Contracts;

use WP\OAuth\Grants\AuthorizedGrant;
use WP\OAuth\Tokens\Connection;
use WP_Error;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Persists connection state independently of the token representation.
 */
interface ConnectionStoreInterface {

	/** @return Connection|WP_Error */
	public function create( AuthorizedGrant $grant );

	public function get( int $user_id, string $connection_id ): ?Connection;

	/**
	 * Atomically replaces a connection if its state still matches $expected.
	 */
	public function compare_and_swap( Connection $expected, Connection $replacement ): bool;

	public function revoke( Connection $connection ): void;

	public function record_usage( Connection $connection ): void;

	/** @param array<string, mixed> $credential Application Password record. */
	public function manages_credential( WP_User $user, array $credential ): bool;
}
