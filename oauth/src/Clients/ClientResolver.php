<?php
/** Authorization-time client resolution. */
declare( strict_types=1 );
namespace WP\OAuth\Clients;

use WP\OAuth\Clients\Cimd\CimdApproval;
use WP\OAuth\Clients\Cimd\CimdResolver;
defined( 'ABSPATH' ) || exit;

/** Resolves registered and CIMD clients for authorization requests. */
final class ClientResolver {
	/** @var ClientRepository */
	private $clients;

	/** @var CimdResolver */
	private $cimd;

	/**
	 * Constructor.
	 *
	 * @param ClientRepository $clients Persistent client registry.
	 * @param CimdResolver     $cimd    CIMD resolver.
	 */
	public function __construct( ClientRepository $clients, CimdResolver $cimd ) {
		$this->clients = $clients;
		$this->cimd    = $cimd;
	}

	/**
	 * Gets an existing local client without performing remote discovery.
	 *
	 * @param string $id Client identifier.
	 * @return Client|null Stored client, or null when it is unknown.
	 */
	public function get( string $id ): ?Client {
		return $this->clients->get( $id );
	}

	/**
	 * Resolves a client for an authorization request.
	 *
	 * @param string $id      Client identifier.
	 * @param int    $user_id Authenticated user ID.
	 * @return Client|CimdApproval|\WP_Error|null Resolved client, pending site approval, failure, or null when unknown.
	 */
	public function resolve_for_authorization( string $id, int $user_id ) {
		$client = $this->clients->get( $id );
		if ( $client && ! $client->is_cimd() ) {
			return $client;
		}
		return $this->cimd->resolve( $id, $user_id );
	}

	/**
	 * Persists a verified CIMD registration after administrator approval.
	 *
	 * @param CimdApproval $approval Verified pending approval.
	 * @return Client|\WP_Error Saved client or persistence failure.
	 */
	public function approve_cimd( CimdApproval $approval ) {
		return $this->cimd->approve( $approval );
	}

	/**
	 * Whether a client identifier could be a CIMD URL.
	 *
	 * @param string $id Client identifier.
	 */
	public function is_cimd_candidate( string $id ): bool {
		return CimdResolver::is_candidate( $id );
	}
}
