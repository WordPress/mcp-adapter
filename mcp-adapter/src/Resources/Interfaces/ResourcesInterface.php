<?php
/**
 * MCP Resources Interface.
 *
 * @package WP\MCP
 */

namespace WP\MCP\Resources\Interfaces;

/**
 * Interface for MCP resources.
 */
interface ResourcesInterface {
	/**
	 * Get the resources.
	 *
	 * @return array The resources.
	 */
	public function get_resources(): array;
}
