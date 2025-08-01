<?php
/**
 * MCP Tools Interface.
 *
 * @package WP\MCP
 */

namespace WP\MCP\Tools\Interfaces;

/**
 * Interface for MCP resources.
 */
interface ToolsInterface {
	/**
	 * Get the tools.
	 *
	 * @return array The tools.
	 */
	public function get_tools(): array;
}
