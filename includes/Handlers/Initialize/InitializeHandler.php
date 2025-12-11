<?php
/**
 * Initialize method handler for MCP requests.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Handlers\Initialize;

use stdClass;
use WP\MCP\Core\McpServer;
use WP\McpSchema\Common\Lifecycle\Implementation;
use WP\McpSchema\Common\Protocol\InitializeResult;
use WP\McpSchema\Server\Lifecycle\ServerCapabilities;
use WP\McpSchema\Server\Lifecycle\ServerCapabilitiesPrompts;
use WP\McpSchema\Server\Lifecycle\ServerCapabilitiesResources;
use WP\McpSchema\Server\Lifecycle\ServerCapabilitiesTools;

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
	 * @return InitializeResult Response with server capabilities and information.
	 */
	public function handle( int $request_id = 0 ): InitializeResult {
		// Create the server implementation info DTO.
		$server_info = new Implementation(
			$this->mcp->get_server_name(),
			$this->mcp->get_server_version()
		);

		// Create typed capabilities DTOs.
		// Empty DTOs indicate support for the capability without additional options.
		$capabilities = new ServerCapabilities(
			null, // experimental
			new stdClass(), // logging - Server supports sending log messages to client
			new stdClass(), // completions - Server supports argument autocompletion
			new ServerCapabilitiesPrompts(), // Basic prompts support without listChanged
			new ServerCapabilitiesResources(), // Basic resources support without listChanged/subscribe
			new ServerCapabilitiesTools(), // Tools support
			null  // tasks
		);

		return new InitializeResult(
			'2025-06-18',
			$capabilities,
			$server_info,
			null, // _meta
			$this->mcp->get_server_description()
		);
	}
}
