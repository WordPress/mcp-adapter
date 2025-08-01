<?php
/**
 * MCP Prompts Interface.
 *
 * @package WP\MCP
 */

namespace WP\MCP\Prompts\Interfaces;

/**
 * Interface for MCP prompts.
 */
interface PromptsInterface {
	/**
	 * Get the prompts.
	 *
	 * @return array The prompts.
	 */
	public function get_prompts(): array;
}
