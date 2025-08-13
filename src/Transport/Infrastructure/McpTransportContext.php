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
use WP\MCP\Handlers\Tools\ToolsHandler;
use WP\MCP\Handlers\Resources\ResourcesHandler;
use WP\MCP\Handlers\Prompts\PromptsHandler;
use WP\MCP\Handlers\System\SystemHandler;

/**
 * Transport context object for dependency injection.
 *
 * Contains all dependencies needed by transport implementations,
 * promoting loose coupling and easier testing.
 */
class McpTransportContext {

	/**
	 * Initialize the transport context.
	 *
	 * @param McpServer             $mcp_server The MCP server instance.
	 * @param InitializeHandler     $initialize_handler The initialize handler.
	 * @param ToolsHandler          $tools_handler The tools handler.
	 * @param ResourcesHandler      $resources_handler The resources handler.
	 * @param PromptsHandler        $prompts_handler The prompts handler.
	 * @param SystemHandler         $system_handler The system handler.
	 * @param string                $observability_handler The observability handler class name.
	 * @param McpRequestRouter|null $request_router The request router service.
	 * @param callable|null         $transport_permission_callback Optional custom permission callback for transport-level authentication.
	 */
	public function __construct(
		public McpServer $mcp_server,
		public InitializeHandler $initialize_handler,
		public ToolsHandler $tools_handler,
		public ResourcesHandler $resources_handler,
		public PromptsHandler $prompts_handler,
		public SystemHandler $system_handler,
		public string $observability_handler,
		public ?McpRequestRouter $request_router,
		public $transport_permission_callback = null
	) {}
}
