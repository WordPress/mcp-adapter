<?php
/**
 * System method handlers for MCP requests.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Handlers\System;

use WP\MCP\Core\McpRequestContext;
use WP\McpSchema\Record\PingRequest;

/**
 * Handles system-related MCP methods.
 */
class SystemHandler {
	/**
	 * Handles the ping request.
	 *
	 * @param \WP\McpSchema\Record\PingRequest $request Validated request.
	 * @param \WP\MCP\Core\McpRequestContext $request_context Exact request context.
	 * @return array<string, mixed> Logical empty result.
	 * @since n.e.x.t
	 */
	public function ping( PingRequest $request, McpRequestContext $request_context ): array {
		unset( $request, $request_context );
		return array();
	}
}
