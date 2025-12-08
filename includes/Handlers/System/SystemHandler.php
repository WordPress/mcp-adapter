<?php
/**
 * System method handlers for MCP requests.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Handlers\System;

use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;

/**
 * Handles system-related MCP methods.
 */
class SystemHandler {
	/**
	 * Handles the ping request.
	 *
	 * @param int $request_id Optional. The request ID for JSON-RPC. Default 0.
	 *
	 * @return array Empty result array per MCP specification.
	 */
	public function ping( int $request_id = 0 ): array {
		// According to MCP specification, ping returns an empty result.
		return array();
	}

	/**
	 * Handles the logging/setLevel request.
	 *
	 * @param array $params     Request parameters.
	 * @param int   $request_id Optional. The request ID for JSON-RPC. Default 0.
	 *
	 * @return array Response with error if level parameter is missing, empty array otherwise.
	 */
	public function set_logging_level( array $params, int $request_id = 0 ): array {
		if ( ! isset( $params['params']['level'] ) && ! isset( $params['level'] ) ) {
			return array( 'error' => McpErrorFactory::missing_parameter( $request_id, 'level' )['error'] );
		}

		// @todo: Implement logging level setting logic here.

		return array();
	}

	/**
	 * Handles the completion/complete request.
	 *
	 * @param int $request_id Optional. The request ID for JSON-RPC. Default 0.
	 *
	 * @return array Completion response array.
	 */
	public function complete( int $request_id = 0 ): array {
		// Implement completion logic here.

		return array();
	}

	/**
	 * Handles the roots/list request.
	 *
	 * @param int $request_id Optional. The request ID for JSON-RPC. Default 0.
	 *
	 * @return array Response with roots list.
	 */
	public function list_roots( int $request_id = 0 ): array {
		// Implement roots listing logic here.
		$roots = array();

		return array(
			'roots' => $roots,
		);
	}

	/**
	 * Handles method not found errors.
	 *
	 * @param array $params     Request parameters.
	 * @param int   $request_id Optional. The request ID for JSON-RPC. Default 0.
	 *
	 * @return array Response with method not found error.
	 */
	public function method_not_found( array $params, int $request_id = 0 ): array {
		$method = $params['method'] ?? 'unknown';

		return array( 'error' => McpErrorFactory::method_not_found( $request_id, $method )['error'] );
	}
}
