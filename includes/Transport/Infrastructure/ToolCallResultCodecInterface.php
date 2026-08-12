<?php
/**
 * Revision-specific tool-call result codec contract.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Transport\Infrastructure;

use WP\MCP\Domain\Tools\ToolCallOutcome;

/**
 * Encodes a stable Adapter outcome through one exact schema DTO tree.
 *
 * @internal
 * @since n.e.x.t
 */
interface ToolCallResultCodecInterface {

	/**
	 * Encode an outcome for the selected wire revision.
	 *
	 * @param \WP\MCP\Domain\Tools\ToolCallOutcome $outcome Stable tool-call outcome.
	 *
	 * @return array<string, mixed>
	 * @throws \WP\MCP\Transport\Infrastructure\ToolCallCodecException When the outcome is not representable.
	 */
	public function encode( ToolCallOutcome $outcome ): array;
}
