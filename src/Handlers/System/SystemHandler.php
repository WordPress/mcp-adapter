<?php
/**
 * System method handlers for MCP requests.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Handlers\System;

use WP\MCP\Core\McpServer;
use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;

/**
 * Handles system-related MCP methods.
 */
class SystemHandler {
	/**
	 * The WordPress MCP instance.
	 *
	 * @var McpServer
	 */
	private McpServer $mcp;

	/**
	 * Constructor.
	 *
	 * @param McpServer $mcp The WordPress MCP instance.
	 */
	public function __construct( McpServer $mcp ) {
		$this->mcp = $mcp;
	}

	/**
	 * Handle the ping request.
	 *
	 * @param int $request_id The request ID for JSON-RPC.
	 *
	 * @return array
	 */
	public function ping( int $request_id = 0 ): array {
		// According to MCP specification, ping returns an empty result.
		return array();
	}

	/**
	 * Handle the logging/setLevel request.
	 *
	 * @param array $params     Request parameters.
	 * @param int   $request_id The request ID for JSON-RPC.
	 *
	 * @return array
	 */
	public function set_logging_level( array $params, int $request_id = 0 ): array {
		if ( ! isset( $params['params']['level'] ) && ! isset( $params['level'] ) ) {
			return array( 'error' => McpErrorFactory::missing_parameter( $request_id, 'level' )['error'] );
		}

		// @todo: Implement logging level setting logic here.

		return array(
			'success' => true,
		);
	}

	/**
	 * Handle the completion/complete request.
	 *
	 * @param int $request_id The request ID for JSON-RPC.
	 *
	 * @return array
	 */
	public function complete( int $request_id = 0 ): array {
		// Implement completion logic here.

		return array(
			'success' => true,
		);
	}

	/**
	 * Handle the roots/list request.
	 *
	 * @param int $request_id The request ID for JSON-RPC.
	 *
	 * @return array
	 */
	public function list_roots( int $request_id = 0 ): array {
		// Implement roots listing logic here.
		$roots = array();

		return array(
			'roots' => $roots,
		);
	}

	/**
	 * Handle method not found errors.
	 *
	 * @param array $params     Request parameters.
	 * @param int   $request_id The request ID for JSON-RPC.
	 *
	 * @return array
	 */
	public function method_not_found( array $params, int $request_id = 0 ): array {
		$method = $params['method'] ?? 'unknown';

		return array( 'error' => McpErrorFactory::method_not_found( $request_id, $method )['error'] );
	}
}
