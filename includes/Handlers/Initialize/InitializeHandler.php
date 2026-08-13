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
use WP\McpSchema\Common\Protocol\DTO\InitializeResult;

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
	 * Unsupported initialize versions fall back to the supported legacy revision;
	 * modern requests use per-request negotiation rather than initialize.
	 *
	 * @since 0.5.0
	 *
	 * @param string $client_protocol_version The protocol version requested by the client.
	 *
	 * @return array Response with server capabilities and information.
	 */
	public function handle( string $client_protocol_version ): array {
		$negotiated_version = McpVersionNegotiator::negotiate( $client_protocol_version );

		$server_info = array(
			'name'    => $this->mcp->get_server_name(),
			'version' => $this->mcp->get_server_version(),
		);

		// Capabilities should only be advertised if they are implemented end-to-end.
		// IMPORTANT: We set explicit boolean values (not empty arrays) to ensure proper JSON serialization.
		// Empty arrays `[]` serialize as JSON arrays `[]`, but MCP spec requires JSON objects `{}`.
		// Setting explicit values like `listChanged: false` produces associative arrays that serialize correctly.
		$capabilities = array(
			'prompts'   => array( 'listChanged' => false ),
			'resources' => array(
				'subscribe'   => false,
				'listChanged' => false,
			),
			'tools'     => array( 'listChanged' => false ),
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
		 * other initialization data dynamically. Call `$result->toArray()`,
		 * change the data, and return `InitializeResult::fromArray( $data )`.
		 *
		 * @since 0.5.0
		 *
		 * @param \WP\McpSchema\Common\Protocol\DTO\InitializeResult $result The initialize result facade.
		 * @param \WP\MCP\Core\McpServer                              $server The MCP server instance.
		 */
		$filtered = apply_filters( 'mcp_adapter_initialize_response', $result, $this->mcp );
		if ( $filtered instanceof InitializeResult ) {
			return $filtered->toArray();
		}

		return is_array( $filtered ) ? $filtered : $result->toArray();
	}
}
