<?php //phpcs:ignore
/**
 * Initialize method handler for MCP requests.
 *
 * @package WordPressMcp
 */

declare(strict_types=1);

namespace WP\MCP\RequestMethodHandlers;

use WP\MCP\Server;
use stdClass;

/**
 * Handles the initialize MCP method.
 */
class InitializeHandler {
	/**
	 * The WordPress MCP instance.
	 *
	 * @var Server
	 */
	private Server $mcp;

	/**
	 * Constructor.
	 */
	public function __construct() {
	}

	/**
	 * Handle the initialize request.
	 *
	 * @return array
	 */
	public function handle(): array {
		$site_info = array(
			'name'        => get_bloginfo( 'name' ),
			'url'         => get_bloginfo( 'url' ),
			'description' => get_bloginfo( 'description' ),
			'language'    => get_bloginfo( 'language' ),
			'charset'     => get_bloginfo( 'charset' ),
		);

		// @todo: add capabilities based on your implementation
		$capabilities = array(
			'tools'      => array(
				'list' => true,
				'call' => true,
			),
			'resources'  => array(
				'list'        => true,
				'subscribe'   => true,
				'listChanged' => true,
			),
			'prompts'    => array(
				'list'        => true,
				'get'         => true,
				'listChanged' => true,
			),
			'logging'    => new stdClass(),
			'completion' => new stdClass(),
			'roots'      => array(
				'list'        => true,
				'listChanged' => true,
			),
		);

		// Send the response according to JSON-RPC 2.0 and InitializeResult schema.
		return array(
			'protocolVersion' => '2025-06-18',
			'serverInfo'      => $this->mcp->get_server_info(),
			'capabilities'    => (object) $capabilities,
			'instructions'    => 'This is a WordPress MCP Server implementation that provides tools, resources, and prompts for interacting with the WordPress site ' . get_bloginfo( 'name' ) . ' (' . get_bloginfo( 'url' ) . ').',
		);
	}
}
