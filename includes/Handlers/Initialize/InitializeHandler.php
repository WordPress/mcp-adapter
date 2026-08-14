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
	 * @since 0.5.0
	 * @since n.e.x.t Returns a revision-neutral array instead of a DTO.
	 *
	 * @param string $client_protocol_version The protocol version requested by the client.
	 *
	 * @return array<string, mixed> Response with server capabilities and information, in wire shape.
	 */
	public function handle( string $client_protocol_version ): array {
		$negotiated_version = McpVersionNegotiator::negotiate( $client_protocol_version );

		$server_info = array(
			'name'    => $this->mcp->get_server_name(),
			'version' => $this->mcp->get_server_version(),
		);

		// Capabilities are advertised only when implemented end-to-end. The
		// explicit false values deliberately tell clients that list-change
		// notifications and resource subscriptions are not emitted.
		$capabilities = array(
			'prompts'   => array( 'listChanged' => false ),
			'resources' => array(
				'subscribe'   => false,
				'listChanged' => false,
			),
			'tools'     => array( 'listChanged' => false ),
		);

		$payload = array(
			'protocolVersion' => $negotiated_version,
			'capabilities'    => $capabilities,
			'serverInfo'      => $server_info,
		);

		$instructions = $this->mcp->get_server_description();
		if ( '' !== $instructions ) {
			$payload['instructions'] = $instructions;
		}

		$result = $this->mcp->get_wire_encoder()->initialize_result( $payload );

		/**
		 * Filters the initialize response before returning to the client.
		 *
		 * Use this filter to modify server capabilities, instructions, or
		 * other initialization data dynamically. Modify the array keys
		 * directly and return the array.
		 *
		 * @since 0.5.0
		 * @since n.e.x.t The result is a revision-neutral array instead of an InitializeResult DTO.
		 *
		 * @param array<string, mixed>   $result The initialize result in wire shape.
		 * @param \WP\MCP\Core\McpServer $server The MCP server instance.
		 */
		return apply_filters( 'mcp_adapter_initialize_response', $result, $this->mcp );
	}
}
