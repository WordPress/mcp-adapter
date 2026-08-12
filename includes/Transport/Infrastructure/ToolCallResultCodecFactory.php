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
	 * @since n.e.x.t
	 *
	 * @param \WP\MCP\Core\McpProtocolContext $context Request protocol context.
	 *
	 * @throws \InvalidArgumentException If the context has no supported schema revision.
	 */
	public static function for_context( McpProtocolContext $context ): ToolCallResultCodecInterface {
		switch ( $context->get_schema_revision() ) {
			case McpProtocolContext::SCHEMA_REVISION_2025_11_25:
				return new V20251125ToolCallResultCodec();

			case McpProtocolContext::SCHEMA_REVISION_2026_07_28:
				return new V20260728ToolCallResultCodec();
		}

		throw new \InvalidArgumentException(
			sprintf( 'Unsupported MCP schema revision: %s', $context->get_schema_revision() )
		);
	}
}
