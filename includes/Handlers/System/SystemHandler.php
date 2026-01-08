<?php
/**
 * System method handlers for MCP requests.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Handlers\System;

use WP\McpSchema\Common\AbstractDataTransferObject;
use WP\McpSchema\Common\Protocol\DTO\Result;

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
}
