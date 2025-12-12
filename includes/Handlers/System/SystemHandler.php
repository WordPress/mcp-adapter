<?php
/**
 * System method handlers for MCP requests.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Handlers\System;

use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;
use WP\McpSchema\Client\Roots\ListRootsResult;
use WP\McpSchema\Common\AbstractDataTransferObject;
use WP\McpSchema\Common\Protocol\Result;
use WP\McpSchema\Server\Core\CompleteResult;
use WP\McpSchema\Server\Core\CompleteResultCompletion;

/**
 * Handles system-related MCP methods.
 */
class SystemHandler {
	/**
	 * Handles the ping request.
	 *
	 * @param string|int|null $request_id Optional. The request ID for JSON-RPC. Default 0.
	 *
	 * @return \WP\McpSchema\Common\AbstractDataTransferObject Empty result DTO per MCP specification.
	 */
	public function ping( $request_id = 0 ): AbstractDataTransferObject {
		return Result::fromArray( array() );
	}

	/**
	 * Handles the logging/setLevel request.
	 *
	 * @param array $params     Request parameters.
	 * @param string|int|null $request_id Optional. The request ID for JSON-RPC. Default 0.
	 *
	 * @return \WP\McpSchema\Common\AbstractDataTransferObject Response with error if level parameter is missing, empty result otherwise.
	 */
	public function set_logging_level( array $params, $request_id = 0 ): AbstractDataTransferObject {
		if ( ! isset( $params['params']['level'] ) && ! isset( $params['level'] ) ) {
			return McpErrorFactory::missing_parameter( $request_id, 'level' );
		}

		// @todo: Implement logging level setting logic here.

		return Result::fromArray( array() );
	}

	/**
	 * Handles the completion/complete request.
	 *
	 * @param string|int|null $request_id Optional. The request ID for JSON-RPC. Default 0.
	 *
	 * @return \WP\McpSchema\Common\AbstractDataTransferObject Completion response DTO.
	 */
	public function complete( $request_id = 0 ): AbstractDataTransferObject {
		return CompleteResult::fromArray(
			array(
				'completion' => CompleteResultCompletion::fromArray(
					array(
						'values' => array(),
					)
				),
			)
		);
	}

	/**
	 * Handles the roots/list request.
	 *
	 * @param string|int|null $request_id Optional. The request ID for JSON-RPC. Default 0.
	 *
	 * @return \WP\McpSchema\Common\AbstractDataTransferObject Response with roots list.
	 */
	public function list_roots( $request_id = 0 ): AbstractDataTransferObject {
		// Implement roots listing logic here.
		return ListRootsResult::fromArray(
			array(
				'roots' => array(),
			)
		);
	}

	/**
	 * Handles method not found errors.
	 *
	 * @param array $params     Request parameters.
	 * @param string|int|null $request_id Optional. The request ID for JSON-RPC. Default 0.
	 *
	 * @return \WP\McpSchema\Common\AbstractDataTransferObject Response with method not found error.
	 */
	public function method_not_found( array $params, $request_id = 0 ): AbstractDataTransferObject {
		$method = $params['method'] ?? 'unknown';

		return McpErrorFactory::method_not_found( $request_id, $method );
	}
}
