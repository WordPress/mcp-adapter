<?php
/**
 * Interface for MCP system tools that are not backed by WordPress abilities.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Domain\Tools\Contracts;

use WP\MCP\Core\McpServer;
use WP\MCP\Domain\Tools\McpTool;

/**
 * Interface for system tools that provide MCP protocol functionality.
 *
 * System tools are infrastructure tools that serve the MCP protocol itself,
 * rather than WordPress business logic. Examples include discovery tools,
 * ability information tools, and execution tools.
 *
 * Unlike ability-based tools, system tools:
 * - Are not backed by WordPress abilities
 * - Are not discoverable through ability discovery
 * - Have their own lifecycle and versioning
 * - Serve MCP protocol requirements
 */
interface McpSystemToolInterface {
	/**
	 * Build the MCP tool instance.
	 *
	 * @param \WP\MCP\Core\McpServer $server The MCP server instance.
	 *
	 * @return \WP\MCP\Domain\Tools\McpTool The constructed MCP tool.
	 */
	public function build( McpServer $server ): McpTool;

	/**
	 * Execute the system tool.
	 *
	 * @param array $args The arguments.
	 * @param \WP\MCP\Core\McpServer $server The MCP server instance.
	 *
	 * @return array The result.
	 */
	public function execute( array $args, McpServer $server ): array;
}
