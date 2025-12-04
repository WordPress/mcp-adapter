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
	 * @var \WP\MCP\Core\McpServer
	 */
	private McpServer $mcp;

	/**
	 * Constructor.
	 *
	 * @param \WP\MCP\Core\McpServer $mcp The WordPress MCP instance.
	 */
	public function __construct( McpServer $mcp ) {
		$this->mcp = $mcp;
	}

	/**
	 * Handles the initialize request.
	 *
	 * @param int $request_id Optional. The request ID for JSON-RPC. Default 0.
	 *
	 * @return array Response with server capabilities and information.
	 */
	public function handle( int $request_id = 0 ): array {
		$server_info = array(
			'name'    => $this->mcp->get_server_name(),
			'version' => $this->mcp->get_server_version(),
		);

		// MCP 2025-06-18 compliant capabilities
		$capabilities = array(
			'tools'       => new stdClass(), // Empty object indicates support
			'resources'   => new stdClass(), // Basic resources support without listChanged/subscribe
			'prompts'     => new stdClass(), // Basic prompts support without listChanged
			'logging'     => new stdClass(), // Server supports sending log messages to client
			'completions' => new stdClass(), // Server supports argument autocompletion (note: plural!)
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
