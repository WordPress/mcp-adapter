<?php
/**
 * Initialize method handler for MCP requests.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Handlers\Initialize;

use WP\MCP\Core\McpRequestContext;
use WP\MCP\Core\McpServer;
use WP\McpSchema\Record\InitializeRequest;
use WP\McpSchema\Record\InitializeResult;

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
	 * @since 0.5.0
	 * @since n.e.x.t Accepts an exact validated request record and Core context.
	 *
	 * @param \WP\McpSchema\Record\InitializeRequest $request Validated request.
	 * @param \WP\MCP\Core\McpRequestContext $request_context Exact request context.
	 *
	 * @return \WP\McpSchema\Record\InitializeResult Response with server capabilities and information.
	 */
	public function handle( InitializeRequest $request, McpRequestContext $request_context ): InitializeResult {
		unset( $request );
		$schema = $request_context->schema();
		$result = $schema->fromArray(
			InitializeResult::class,
			array(
				'protocolVersion' => $request_context->revision(),
				'capabilities'    => array(
					'prompts'   => array( 'listChanged' => false ),
					'resources' => array(
						'subscribe'   => false,
						'listChanged' => false,
					),
					'tools'     => array( 'listChanged' => false ),
				),
				'serverInfo'      => array(
					'name'    => $this->mcp->get_server_name(),
					'version' => $this->mcp->get_server_version(),
				),
				'instructions'    => $this->mcp->get_server_description(),
			)
		);

		/**
		 * Filters the initialize response before returning to the client.
		 *
		 * Use this filter to modify server capabilities, instructions, or
		 * other initialization data dynamically. To modify the result, call
		 * `$result->jsonSerialize()`, change the data, and reconstruct it through
		 * the selected schema.
		 *
		 * @since 0.5.0
		 *
		 * @param \WP\McpSchema\Record\InitializeResult $result The initialize result record.
		 * @param \WP\MCP\Core\McpServer                             $server The MCP server instance.
		 * @param \WP\McpSchema\Schema                                $schema Selected schema.
		 */
		return apply_filters( 'mcp_adapter_initialize_response', $result, $this->mcp, $schema );
	}
}
