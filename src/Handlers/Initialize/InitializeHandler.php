<?php
/**
 * Initialize method handler for MCP requests.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Handlers\Initialize;

use WP\MCP\Core\McpServer;
use stdClass;

/**
 * Handles the initialize MCP method.
 */
class InitializeHandler {
	/**
	 * The WordPress MCP instance.
	 *
	 * @var McpServer
	 */
	private McpServer $mcp;

	/**
	 * Constructor.
	 *
	 * @param McpServer $mcp The WordPress MCP instance.
	 */
	public function __construct( McpServer $mcp ) {
		$this->mcp = $mcp;
	}

	/**
	 * Handle the initialize request.
	 *
	 * @param int $request_id The request ID for JSON-RPC.
	 *
	 * @return array
	 */
	public function handle( int $request_id = 0 ): array {
		$server_info = array(
			'name'    => $this->mcp->get_server_name(),
			'version' => $this->mcp->get_server_version(),
		);

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
			'serverInfo'      => $server_info,
			'capabilities'    => (object) $capabilities,
			'instructions'    => $this->mcp->get_server_description(),
		);
	}
}
