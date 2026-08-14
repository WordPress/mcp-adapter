<?php
/**
 * System method handlers for MCP requests.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Handlers\System;

/**
 * Handles system-related MCP methods.
 */
class SystemHandler {
	/**
	 * Handles the ping request.
	 *
	 * @since n.e.x.t Returns a revision-neutral array instead of a DTO.
	 *
	 * @return array<string, mixed> Empty result per MCP specification.
	 */
	public function ping(): array {
		// The protocol defines a ping result as an empty object. There is nothing
		// to encode, so the encoder is not involved.
		return array();
	}
}
