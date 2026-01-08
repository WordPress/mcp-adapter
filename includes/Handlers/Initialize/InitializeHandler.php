<?php
/**
 * Initialize method handler for MCP requests.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Handlers\Initialize;

use WP\MCP\Core\McpServer;
use WP\McpSchema\Common\Lifecycle\DTO\Implementation;
use WP\McpSchema\Common\McpConstants;
use WP\McpSchema\Common\Protocol\DTO\InitializeResult;
use WP\McpSchema\Server\Lifecycle\DTO\ServerCapabilities;

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
	 * @return \WP\McpSchema\Common\Protocol\DTO\InitializeResult Response with server capabilities and information.
	 */
	public function handle( $request_id = 0 ): InitializeResult {
		$server_info = Implementation::fromArray(
			array(
				'name'    => $this->mcp->get_server_name(),
				'version' => $this->mcp->get_server_version(),
			)
		);

		// Capabilities should only be advertised if they are implemented end-to-end.
		// IMPORTANT: We set explicit boolean values (not empty arrays) to ensure proper JSON serialization.
		// Empty arrays `[]` serialize as JSON arrays `[]`, but MCP spec requires JSON objects `{}`.
		// Setting explicit values like `listChanged: false` produces associative arrays that serialize correctly.
		$capabilities = ServerCapabilities::fromArray(
			array(
				'prompts'   => array( 'listChanged' => false ),
				'resources' => array(
					'subscribe'   => false,
					'listChanged' => false,
				),
				'tools'     => array( 'listChanged' => false ),
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
