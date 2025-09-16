<?php
/**
 * Interface for MCP REST transport protocols.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Transport\Contracts;

use WP_REST_Request;

/**
 * Interface for MCP REST transport protocols.
 *
 * This interface extends the base transport interface to provide
 * WordPress REST API specific functionality, with methods that
 * work specifically with WP_REST_Request objects.
 */
interface McpRestTransportInterface extends McpTransportInterface {

	/**
	 * Check if the user has permission to access the MCP API.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request The WordPress REST request object.
	 * @return bool|\WP_Error True if allowed, WP_Error or false if not.
	 */
	public function check_permission( WP_REST_Request $request );

	/**
	 * Handle incoming REST requests.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request The WordPress REST request object.
	 * @return \WP_REST_Response REST API response object.
	 */
	public function handle_request( WP_REST_Request $request ): \WP_REST_Response;
}
