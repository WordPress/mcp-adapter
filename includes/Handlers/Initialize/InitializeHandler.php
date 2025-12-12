<?php
/**
 * Initialize method handler for MCP requests.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Handlers\Initialize;

use WP\MCP\Core\McpServer;
use WP\McpSchema\Common\Lifecycle\Implementation;
use WP\McpSchema\Common\McpConstants;
use WP\McpSchema\Common\Protocol\InitializeResult;
use WP\McpSchema\Server\Lifecycle\ServerCapabilities;

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
	 * @param string|int|null $request_id Optional. The request ID for JSON-RPC. Default 0.
	 *
	 * @return \WP\McpSchema\Common\Protocol\InitializeResult Response with server capabilities and information.
	 */
	public function handle( $request_id = 0 ): InitializeResult {
		$server_info = Implementation::fromArray(
			array(
				'name'    => $this->mcp->get_server_name(),
				'version' => $this->mcp->get_server_version(),
			)
		);

		// Empty arrays indicate support for the capability without additional options.
		$capabilities = ServerCapabilities::fromArray(
			array(
				'logging'     => new \stdClass(),
				'completions' => new \stdClass(),
				'prompts'     => array(),
				'resources'   => array(),
				'tools'       => array(),
			)
		);

		return InitializeResult::fromArray(
			array(
				'protocolVersion' => McpConstants::LATEST_PROTOCOL_VERSION,
				'capabilities'    => $capabilities,
				'serverInfo'      => $server_info,
				'instructions'    => $this->mcp->get_server_description(),
			)
		);
	}
}
