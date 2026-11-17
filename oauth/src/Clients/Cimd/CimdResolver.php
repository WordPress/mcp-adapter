<?php
/** Resolves metadata-document client IDs at authorization time. */
declare( strict_types=1 );
namespace WP\OAuth\Clients\Cimd;

use WP\OAuth\Clients\Client;
use WP\OAuth\Clients\ClientRepository;
use WP_Error;
defined( 'ABSPATH' ) || exit;

final class CimdResolver {
	/** @var ClientRepository */
	private $clients;

	/** @var CimdFetcherInterface */
	private $fetcher;

	/**
	 * Constructor.
	 *
	 * @param ClientRepository     $clients Client persistence.
	 * @param CimdFetcherInterface $fetcher Secure metadata fetcher.
	 */
	public function __construct( ClientRepository $clients, CimdFetcherInterface $fetcher ) {
		$this->clients = $clients;
		$this->fetcher = $fetcher;
	}

	/**
	 * Whether a client ID has the URL shape required for CIMD.
	 *
	 * @param string $id Client identifier.
	 * @return bool Whether the identifier is a CIMD candidate.
	 */
	public static function is_candidate( string $id ): bool {
		return ! is_wp_error( CimdDocument::validate_url( $id ) );
	}

	/**
	 * Resolves a CIMD client for an authorization request.
	 *
	 * @param string $id      Client metadata document URL.
	 * @param int    $user_id Authenticated user ID.
	 * @return Client|CimdApproval|WP_Error|null Client, pending site approval, failure, or null when CIMD does not apply.
	 */
	public function resolve( string $id, int $user_id ) {
		if ( ! apply_filters( 'oauth_server_cimd_enabled', true ) || ! self::is_candidate( $id ) ) {
			return null;
		}
		if ( ! apply_filters( 'oauth_server_cimd_admit_url', true, $id ) ) {
			return new WP_Error( 'oauth_server_cimd_denied', __( 'This client metadata publisher is not allowed by site policy.', 'mcp-adapter' ) );
		}
		$stored = $this->clients->get( $id );
		if ( $stored && $stored->is_metadata_fresh() ) {
			return $this->apply_policy( $stored );
		}
		if ( $user_id <= 0 ) {
			return new WP_Error( 'oauth_server_cimd_login', __( 'Sign in before client metadata is retrieved.', 'mcp-adapter' ) );
		}
		if ( ! $stored instanceof Client && ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error( 'oauth_server_cimd_approval_required', __( 'This application must be approved by a site administrator before anyone can connect it.', 'mcp-adapter' ) );
		}
		$result = $this->fetcher->fetch( $id );
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			return $stored && is_array( $data ) && ! empty( $data['transient'] ) ? $this->apply_policy( $stored ) : $result;
		}
		$trusted  = (bool) apply_filters( 'oauth_server_cimd_client_trusted', false, $id, $result['metadata'] );
		$approval = new CimdApproval( $id, $result['metadata'], $result['expires'], $trusted, $stored instanceof Client );
		$policy   = $this->apply_policy( $approval->to_client() );
		if ( is_wp_error( $policy ) ) {
			return $policy;
		}
		if ( $this->clients->cimd_requires_approval( $id, $result['metadata'] ) ) {
			if ( ! user_can( $user_id, 'manage_options' ) ) {
				return new WP_Error( 'oauth_server_cimd_reapproval_required', __( 'This application changed its security settings and must be approved again by a site administrator.', 'mcp-adapter' ) );
			}
			return $approval;
		}
		$client = $this->clients->save_cimd( $id, $result['metadata'], $result['expires'], $trusted );
		return is_wp_error( $client ) ? $client : $this->apply_policy( $client );
	}

	/**
	 * Persists metadata after explicit administrator approval.
	 *
	 * The authorization endpoint is responsible for capability and nonce checks.
	 *
	 * @param CimdApproval $approval Verified pending approval.
	 * @return Client|WP_Error Saved client or persistence failure.
	 */
	public function approve( CimdApproval $approval ) {
		return $this->clients->save_cimd( $approval->get_client_id(), $approval->get_metadata(), $approval->get_expires(), $approval->is_trusted() );
	}

	/**
	 * Applies the site's publisher trust policy.
	 *
	 * @param Client $client Verified client.
	 * @return Client|WP_Error Allowed client or policy failure.
	 */
	private function apply_policy( Client $client ) {
		$allowed = (bool) apply_filters( 'oauth_server_cimd_allow_untrusted_clients', true, $client->is_trusted(), $client->get_id(), $client->get_metadata() );
		return $client->is_trusted() || $allowed ? $client : new WP_Error( 'oauth_server_cimd_untrusted', __( 'This client metadata publisher is not trusted by site policy.', 'mcp-adapter' ) );
	}
}
