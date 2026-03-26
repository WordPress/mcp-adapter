<?php
/**
 * Initialize method handler for MCP requests.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Handlers\Initialize;

use WP\MCP\Core\McpServer;
use WP\MCP\Core\McpVersionNegotiator;
use WP\McpSchema\Common\Lifecycle\DTO\Implementation;
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
	 * Negotiates the protocol version with the client using McpVersionNegotiator.
	 * If the client requests a supported version, that version is used. Otherwise
	 * the server falls back to the latest supported version.
	 *
	 * @since n.e.x.t
	 *
	 * @param string $client_protocol_version The protocol version requested by the client.
	 *
	 * @return \WP\McpSchema\Common\Protocol\DTO\InitializeResult Response with server capabilities and information.
	 */
	public function handle( string $client_protocol_version ): InitializeResult {
		$negotiated_version = McpVersionNegotiator::negotiate( $client_protocol_version );

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

		$result = InitializeResult::fromArray(
			array(
				'protocolVersion' => $negotiated_version,
				'capabilities'    => $capabilities,
				'serverInfo'      => $server_info,
				'instructions'    => $this->mcp->get_server_description(),
			)
		);

		/**
		 * Filters the initialize response before returning to the client.
		 *
		 * Use this filter to modify server capabilities, instructions, or
		 * other initialization data dynamically. To modify the result, call
		 * `$result->toArray()`, change the data, and return
		 * `InitializeResult::fromArray( $modified_data )`.
		 *
		 * @since n.e.x.t
		 *
		 * @param \WP\McpSchema\Common\Protocol\DTO\InitializeResult $result The initialize result DTO.
		 * @param \WP\MCP\Core\McpServer                             $server The MCP server instance.
		 */
		return apply_filters( 'mcp_adapter_initialize_response', $result, $this->mcp );
	}
}
