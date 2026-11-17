<?php
/**
 * Approved authorization grant.
 *
 * @package WP\OAuth
 */

declare( strict_types=1 );

namespace WP\OAuth\Grants;

use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Stable input to credential issuance, independent of client registration.
 *
 * A future CIMD resolver can supply the same approved client snapshot as a
 * manually registered client. Token implementations therefore do not depend
 * on how the client was discovered or whether it has a registration row.
 */
final class AuthorizedGrant {

	/**
	 * User who approved the connection.
	 *
	 * @var WP_User
	 */
	private $user;

	/**
	 * Client identifier.
	 *
	 * @var string
	 */
	private $client_id;

	/**
	 * Client display name at approval time.
	 *
	 * @var string
	 */
	private $client_name;

	/**
	 * Approved resource indicator.
	 *
	 * @var string
	 */
	private $resource;

	/**
	 * Approved client metadata snapshot.
	 *
	 * @var array<string, mixed>
	 */
	private $client_metadata;

	/**
	 * Constructor.
	 *
	 * @param WP_User              $user            User who approved the connection.
	 * @param string               $client_id       Client identifier.
	 * @param string               $client_name     Client display name at approval time.
	 * @param string               $resource_indicator Approved resource indicator.
	 * @param array<string, mixed> $client_metadata Approved client metadata snapshot.
	 */
	public function __construct( WP_User $user, string $client_id, string $client_name, string $resource_indicator, array $client_metadata = array() ) {
		$this->user            = $user;
		$this->client_id       = $client_id;
		$this->client_name     = $client_name;
		$this->resource        = $resource_indicator;
		$this->client_metadata = $client_metadata;
	}

	/**
	 * Gets the user who approved the connection.
	 */
	public function get_user(): WP_User {
		return $this->user;
	}

	/**
	 * Gets the client identifier.
	 */
	public function get_client_id(): string {
		return $this->client_id;
	}

	/**
	 * Gets the client display name at approval time.
	 */
	public function get_client_name(): string {
		return $this->client_name;
	}

	/**
	 * Gets the approved resource indicator.
	 */
	public function get_resource(): string {
		return $this->resource;
	}

	/**
	 * Gets the approved client metadata snapshot.
	 *
	 * @return array<string, mixed>
	 */
	public function get_client_metadata(): array {
		return $this->client_metadata;
	}
}
