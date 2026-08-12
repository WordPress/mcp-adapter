<?php
/**
 * Tool-call result codec selector.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Transport\Infrastructure;

use WP\MCP\Core\McpProtocolContext;

/**
 * Selects the exact-revision codec for one request context.
 *
 * @internal
 * @since n.e.x.t
 */
final class ToolCallResultCodecFactory {

	/**
	 * Select a codec for a request.
	 *
	 * @param \WP\MCP\Core\McpProtocolContext $context Request protocol context.
	 */
	public static function for_context( McpProtocolContext $context ): ToolCallResultCodecInterface {
		if ( McpProtocolContext::MODERN_SCHEMA_REVISION === $context->get_schema_revision() ) {
			return new V20260728ToolCallResultCodec();
		}

		return new V20251125ToolCallResultCodec();
	}
}
