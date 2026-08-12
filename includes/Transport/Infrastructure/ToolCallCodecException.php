<?php
/**
 * Tool-call protocol codec exception.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Transport\Infrastructure;

/**
 * Raised when a stable tool outcome cannot be represented by a revision.
 *
 * @internal
 * @since n.e.x.t
 */
final class ToolCallCodecException extends \RuntimeException {
}
