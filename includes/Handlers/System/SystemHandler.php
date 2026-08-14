<?php
/**
 * System method handlers for MCP requests.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Handlers\System;

use WP\McpSchema\Common\Protocol\DTO\Result;

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
		return Result::fromArray( array() )->toArray();
	}
}
