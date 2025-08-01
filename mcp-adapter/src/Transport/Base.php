<?php //phpcs:ignore
declare(strict_types=1);

namespace WP\MCP\Transport;

use WP\MCP\Registry\Server;
use WP\MCP\RequestMethodHandlers\InitializeHandler;
use WP\MCP\RequestMethodHandlers\ToolsHandler;
use WP\MCP\RequestMethodHandlers\ResourcesHandler;
use WP\MCP\RequestMethodHandlers\PromptsHandler;
use WP\MCP\RequestMethodHandlers\SystemHandler;
use WP_Error;

/**
 * Abstract base class for MCP transport protocols
 *
 * Contains shared logic between different transport protocols (STDIO/Streamable)
 */
abstract class Base {

	/**
	 * The WordPress MCP instance.
	 *
	 * @var Server
	 */
	protected Server $mcp;

	/**
	 * The initialize handler.
	 *
	 * @var InitializeHandler
	 */
	protected InitializeHandler $initialize_handler;

	/**
	 * The tools handler.
	 *
	 * @var ToolsHandler
	 */
	protected ToolsHandler $tools_handler;

	/**
	 * The resources handler.
	 *
	 * @var ResourcesHandler
	 */
	protected ResourcesHandler $resources_handler;

	/**
	 * The prompts handler.
	 *
	 * @var PromptsHandler
	 */
	protected PromptsHandler $prompts_handler;

	/**
	 * The system handler.
	 *
	 * @var SystemHandler
	 */
	protected SystemHandler $system_handler;

	/**
	 * Initialize the shared handlers
	 *
	 * @param Server $mcp The WordPress MCP instance.
	 */
	public function __construct( Server $mcp ) {
		$this->mcp = $mcp;

		// Initialize handlers.
		$this->initialize_handler = new InitializeHandler();
		$this->tools_handler      = new ToolsHandler( $this->mcp );
		$this->resources_handler  = new ResourcesHandler( $this->mcp );
		$this->prompts_handler    = new PromptsHandler( $this->mcp );
		$this->system_handler     = new SystemHandler();
	}

	/**
	 * Route a request to the appropriate handler
	 *
	 * @param string $method The MCP method name.
	 * @param array  $params The request parameters.
	 * @param int    $request_id The request ID (for JSON-RPC).
	 * @return array
	 */
	protected function route_request( string $method, array $params, int $request_id = 0 ): array {
		try {
			$result = match ( $method ) {
				'initialize', 'init' => $this->initialize_handler->handle(),
				'ping' => $this->system_handler->ping(),
				'tools/list' => $this->tools_handler->list_tools(),
				'tools/list/all' => $this->tools_handler->list_all_tools( $params ),
				'tools/call' => $this->tools_handler->call_tool( $params ),
				'resources/list' => $this->add_cursor_compatibility( $this->resources_handler->list_resources() ),
				'resources/templates/list' => $this->add_cursor_compatibility( $this->resources_handler->list_resource_templates( $params ) ),
				'resources/read' => $this->resources_handler->read_resource( $params ),
				'resources/subscribe' => $this->resources_handler->subscribe_resource( $params ),
				'resources/unsubscribe' => $this->resources_handler->unsubscribe_resource( $params ),
				'prompts/list' => $this->prompts_handler->list_prompts(),
				'prompts/get' => $this->handle_prompt_get( $params ),
				'logging/setLevel' => $this->system_handler->set_logging_level( $params ),
				'completion/complete' => $this->system_handler->complete(),
				'roots/list' => $this->system_handler->list_roots(),
				default => $this->create_method_not_found_error( $method, $request_id ),
			};

			return $result;

		} catch ( \Throwable $exception ) {
			return $this->handle_exception( $exception, $request_id );
		}
	}

	/**
	 * Add nextCursor for backward compatibility with existing API
	 *
	 * @param array $result The result array.
	 * @return array
	 */
	private function add_cursor_compatibility( array $result ): array {
		if ( ! isset( $result['nextCursor'] ) ) {
			$result['nextCursor'] = '';
		}
		return $result;
	}

	/**
	 * Handle prompt get requests with special result structure
	 *
	 * @param array $params Request parameters.
	 * @return array
	 */
	private function handle_prompt_get( array $params ): array {
		$result = $this->prompts_handler->get_prompt( $params );

		// Handle the nested result structure from the handler.
		if ( isset( $result['result'] ) ) {
			return $result['result'];
		}

		return $result;
	}

	/**
	 * Create a method not found error
	 *
	 * @param string $method The method that was not found.
	 * @param int    $request_id The request ID.
	 * @return array
	 */
	abstract protected function create_method_not_found_error( string $method, int $request_id ): array;

	/**
	 * Handle exceptions that occur during request processing
	 *
	 * @param \Throwable $exception The exception.
	 * @param int        $request_id The request ID.
	 * @return array
	 */
	abstract protected function handle_exception( \Throwable $exception, int $request_id ): array;

	/**
	 * Format a successful response
	 *
	 * @param array $result The result data.
	 * @param int   $request_id The request ID.
	 * @return mixed
	 */
	abstract protected function format_success_response( array $result, int $request_id = 0 ): mixed;

	/**
	 * Format an error response
	 *
	 * @param array $error The error data.
	 * @param int   $request_id The request ID.
	 * @return mixed
	 */
	abstract protected function format_error_response( array $error, int $request_id = 0 ): mixed;

	/**
	 * Check if the user has permission to access the MCP API
	 *
	 * @return bool|WP_Error
	 */
	abstract public function check_permission(): WP_Error|bool;
}
