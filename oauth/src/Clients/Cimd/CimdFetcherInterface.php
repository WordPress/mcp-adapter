<?php
/** CIMD fetch contract. */
declare( strict_types=1 );
namespace WP\OAuth\Clients\Cimd;

use WP_Error;
defined( 'ABSPATH' ) || exit;
/** Fetches and validates client ID metadata documents. */
interface CimdFetcherInterface {
	/**
	 * Fetches a client ID metadata document.
	 *
	 * @param string $url Exact client ID URL.
	 * @return array{metadata: array<string, mixed>, expires: int}|WP_Error Verified metadata and expiry, or a failure.
	 */
	public function fetch( string $url );
}
