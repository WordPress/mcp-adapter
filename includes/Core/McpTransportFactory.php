<?php
/**
 * MCP Transport Factory for initializing MCP transports.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Core;

use WP\MCP\Handlers\Initialize\InitializeHandler;
use WP\MCP\Handlers\Prompts\PromptsHandler;
use WP\MCP\Handlers\Resources\ResourcesHandler;
use WP\MCP\Handlers\System\SystemHandler;
use WP\MCP\Handlers\Tools\ToolsHandler;
use WP\MCP\Transport\Contracts\McpTransportInterface;
use WP\MCP\Transport\Infrastructure\TransportContext;

/**
 * Factory for creating and initializing MCP transports.
 */
class McpTransportFactory {
	/**
	 * MCP Server instance.
	 *
	 * @var McpServer
	 */
	private McpServer $mcp_server;

	/**
	 * Constructor.
	 *
	 * @param McpServer $mcp_server MCP server instance.
	 */
	public function __construct( McpServer $mcp_server ) {
		$this->mcp_server = $mcp_server;
	}

	/**
	 * Initialize MCP transports for the server.
	 *
	 * @param array $mcp_transports Array of MCP transport class names to initialize.
	 *
	 * @throws \Exception If any transport class does not implement McpTransportInterface.
	 */
	public function initialize_transports( array $mcp_transports ): void {
		foreach ( $mcp_transports as $mcp_transport ) {
			// Check for interface implementation
			if ( ! in_array( McpTransportInterface::class, class_implements( $mcp_transport ) ?: array(), true ) ) {
				throw new \Exception(
					esc_html__( 'MCP transport class must implement the McpTransportInterface.', 'mcp-adapter' )
				);
			}

			// Interface-based instantiation with dependency injection
			$context = $this->create_transport_context();
			new $mcp_transport( $context );
		}
	}

	/**
	 * Create transport context with all required dependencies.
	 *
	 * @return TransportContext
	 */
	public function create_transport_context(): TransportContext {
		// Create handlers
		$initialize_handler = new InitializeHandler( $this->mcp_server );
		$tools_handler      = new ToolsHandler( $this->mcp_server );
		$resources_handler  = new ResourcesHandler( $this->mcp_server );
		$prompts_handler    = new PromptsHandler( $this->mcp_server );
		$system_handler     = new SystemHandler();

		// Create the context - the router will be created automatically
		return new TransportContext(
			array(
				'mcp_server'                    => $this->mcp_server,
				'initialize_handler'            => $initialize_handler,
				'tools_handler'                 => $tools_handler,
				'resources_handler'             => $resources_handler,
				'prompts_handler'               => $prompts_handler,
				'system_handler'                => $system_handler,
				'observability_handler'         => $this->mcp_server->get_observability_handler(),
				'transport_permission_callback' => $this->mcp_server->get_transport_permission_callback(),
			)
		);
	}
}
