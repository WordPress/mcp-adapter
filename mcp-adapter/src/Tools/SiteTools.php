<?php //phpcs:ignore
declare(strict_types=1);

namespace WP\MCP\Tools;

use WP\MCP\Tools\Interfaces\ToolsInterface;

/**
 * Class for managing MCP Sites Tools functionality.
 */
class SitesTools implements ToolsInterface {


	/**
	 * Get the tool definitions.
	 *
	 * @return array Array of tool definitions.
	 */
	public function get_tools(): array {
		$tools = array();

		// List user sites
		$tools[] = array(
			'name'        => 'wp_list_user_sites',
			'description' => 'Get a list of all sites accessible to the current user',
			'type'        => 'read',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'page'     => array(
						'type'    => 'integer',
						'default' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'default' => 10,
					),
				),
			),
			'callback'            => array( $this, 'get_user_sites' ),
			'permission_callback' => array( $this, 'permission_callback' ),
			'annotations'         => array(
				'title'         => 'List User Sites',
				'readOnlyHint'  => true,
				'openWorldHint' => false,
			),
		);

		return $tools;
	}


	public function get_user_sites( $args ) {
		$page     = $args['page'] ?? 1;
		$per_page = $args['per_page'] ?? 10;

		$sites = get_ordered_blogs_of_user( get_current_user_id(), true, false, false, true, false, false );

		return $sites;
	}

	public function permission_callback( $args = array() ) {
		return is_user_logged_in();
	}
}
