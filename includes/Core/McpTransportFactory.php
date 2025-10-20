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
use WP\MCP\Transport\Infrastructure\McpTransportContext;

/**
 * Factory for creating and initializing MCP transports.
 */
class McpTransportFactory {
	/**
	 * MCP Server instance.
	 *
	 * @var \WP\MCP\Core\McpServer
	 */
	private McpServer $mcp_server;

	/**
	 * Constructor.
	 *
	 * @param \WP\MCP\Core\McpServer $mcp_server MCP server instance.
	 */
	public function __construct( McpServer $mcp_server ) {
		$this->mcp_server = $mcp_server;
	}

	/**
	 * Initialize MCP transports for the server.
	 *
	 * @param array $mcp_transports Array of MCP transport class names to initialize.
	 */
	public function initialize_transports( array $mcp_transports ): void {
		foreach ( $mcp_transports as $mcp_transport ) {
			// Check if class exists
			if ( ! class_exists( $mcp_transport ) ) {
				_doing_it_wrong(
					__FUNCTION__,
					sprintf(
						/* translators: %s: Transport class name */
						esc_html__( 'Transport class "%s" does not exist. Make sure the class is properly autoloaded or included.', 'mcp-adapter' ),
						esc_html( $mcp_transport )
					),
					'0.1.0'
				);
				// Log error and continue processing other transports
				$this->mcp_server->get_error_handler()->log(
					sprintf( 'Transport class "%s" does not exist.', $mcp_transport ),
					array( 'McpTransportFactory::initialize_transports' )
				);
				continue;
			}

			// Check for interface implementation
			if ( ! in_array( McpTransportInterface::class, class_implements( $mcp_transport ) ?: array(), true ) ) {
				_doing_it_wrong(
					__FUNCTION__,
					sprintf(
						/* translators: %s: Transport class name */
						esc_html__( 'Transport class "%s" must implement McpTransportInterface. Check your transport implementation.', 'mcp-adapter' ),
						esc_html( $mcp_transport )
					),
					'0.1.0'
				);
				// Log error and continue processing other transports
				$this->mcp_server->get_error_handler()->log(
					sprintf( 'MCP transport class "%s" must implement the McpTransportInterface.', $mcp_transport ),
					array( 'McpTransportFactory::initialize_transports' )
				);
				continue;
			}

			// Interface-based instantiation with dependency injection
			$context = $this->create_transport_context();
			new $mcp_transport( $context );
		}
	}

	/**
	 * Create transport context with all required dependencies.
	 *
	 * @return \WP\MCP\Transport\Infrastructure\McpTransportContext
	 */
	public function create_transport_context(): McpTransportContext {
		// Create handlers
		$initialize_handler = new InitializeHandler( $this->mcp_server );
		$tools_handler      = new ToolsHandler( $this->mcp_server );
		$resources_handler  = new ResourcesHandler( $this->mcp_server );
		$prompts_handler    = new PromptsHandler( $this->mcp_server );
		$system_handler     = new SystemHandler();

		// Create the context - the router will be created automatically
		return new McpTransportContext(
			array(
				'mcp_server'                    => $this->mcp_server,
				'initialize_handler'            => $initialize_handler,
				'tools_handler'                 => $tools_handler,
				'resources_handler'             => $resources_handler,
				'prompts_handler'               => $prompts_handler,
				'system_handler'                => $system_handler,
				'observability_handler'         => $this->mcp_server->get_observability_handler(),
				'error_handler'                 => $this->mcp_server->get_error_handler(),
				'transport_permission_callback' => $this->mcp_server->get_transport_permission_callback(),
			)
		);
	}
}
