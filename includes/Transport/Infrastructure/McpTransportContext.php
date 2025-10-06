<?php
/**
 * Transport context object for dependency injection.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Transport\Infrastructure;

use WP\MCP\Core\McpServer;
use WP\MCP\Handlers\Initialize\InitializeHandler;
use WP\MCP\Handlers\Prompts\PromptsHandler;
use WP\MCP\Handlers\Resources\ResourcesHandler;
use WP\MCP\Handlers\System\SystemHandler;
use WP\MCP\Handlers\Tools\ToolsHandler;
use WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface;
use WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface;

/**
 * Transport context object for dependency injection.
 *
 * Contains all dependencies needed by transport implementations,
 * promoting loose coupling and easier testing.
 *
 * Note: The request_router parameter is optional. If not provided,
 * a RequestRouter instance will be automatically created with this
 * context as its dependency.
 */
class McpTransportContext {

	/**
	 * Initialize the transport context.
	 *
	 * @param \WP\MCP\Core\McpServer             $mcp_server The MCP server instance.
	 * @param \WP\MCP\Handlers\Initialize\InitializeHandler     $initialize_handler The initialize handler.
	 * @param \WP\MCP\Handlers\Tools\ToolsHandler          $tools_handler The tools handler.
	 * @param \WP\MCP\Handlers\Resources\ResourcesHandler      $resources_handler The resources handler.
	 * @param \WP\MCP\Handlers\Prompts\PromptsHandler        $prompts_handler The prompts handler.
	 * @param \WP\MCP\Handlers\System\SystemHandler         $system_handler The system handler.
	 * @param string                $observability_handler The observability handler class name.
	 * @param \WP\MCP\Transport\Infrastructure\RequestRouter|null $request_router The request router service.
	 * @param callable|null         $transport_permission_callback Optional custom permission callback for transport-level authentication.
	 */
	/**
	 * The MCP server instance.
	 *
	 * @var \WP\MCP\Core\McpServer
	 */
	public McpServer $mcp_server;

	/**
	 * The initialize handler.
	 *
	 * @var \WP\MCP\Handlers\Initialize\InitializeHandler
	 */
	public InitializeHandler $initialize_handler;

	/**
	 * The tools handler.
	 *
	 * @var \WP\MCP\Handlers\Tools\ToolsHandler
	 */
	public ToolsHandler $tools_handler;

	/**
	 * The resources handler.
	 *
	 * @var \WP\MCP\Handlers\Resources\ResourcesHandler
	 */
	public ResourcesHandler $resources_handler;

	/**
	 * The prompts handler.
	 *
	 * @var \WP\MCP\Handlers\Prompts\PromptsHandler
	 */
	public PromptsHandler $prompts_handler;

	/**
	 * The system handler.
	 *
	 * @var \WP\MCP\Handlers\System\SystemHandler
	 */
	public SystemHandler $system_handler;

	/**
	 * The observability handler instance.
	 *
	 * @var \WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface
	 */
	public McpObservabilityHandlerInterface $observability_handler;

	/**
	 * The error handler instance.
	 *
	 * @var \WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface
	 */
	public McpErrorHandlerInterface $error_handler;

	/**
	 * The request router service.
	 */
	public RequestRouter $request_router;

	/**
	 * Optional custom permission callback for transport-level authentication.
	 *
	 * @var callable|callable-string|null
	 */
	public $transport_permission_callback;

	/**
	 * Initialize the transport context.
	 *
	 * @param array{
	 *   mcp_server: \WP\MCP\Core\McpServer,
	 *   initialize_handler: \WP\MCP\Handlers\Initialize\InitializeHandler,
	 *   tools_handler: \WP\MCP\Handlers\Tools\ToolsHandler,
	 *   resources_handler: \WP\MCP\Handlers\Resources\ResourcesHandler,
	 *   prompts_handler: \WP\MCP\Handlers\Prompts\PromptsHandler,
	 *   system_handler: \WP\MCP\Handlers\System\SystemHandler,
	 *   observability_handler: \WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface,
	 *   request_router?: \WP\MCP\Transport\Infrastructure\RequestRouter,
	 *   transport_permission_callback?: callable|null,
	 *   error_handler?: \WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface
	 * } $properties Properties to set on the context.
	 * Note: request_router is optional and will be auto-created if not provided.
	 */
	public function __construct( array $properties ) {
		foreach ( $properties as $name => $value ) {
			$this->$name = $value;
		}

		// If request_router is provided, we're done
		if ( isset( $properties['request_router'] ) ) {
			return;
		}

		// Create request_router if not provided
		$this->request_router = new RequestRouter( $this );
	}
}
