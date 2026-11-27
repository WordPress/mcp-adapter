<?php
/**
 * Tasks method handlers for MCP requests.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Handlers\Tasks;

use WP\MCP\Core\McpRequestContext;
use WP\MCP\Core\McpServer;
use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;
use WP\MCP\Tasks\McpTaskManager;
use WP\McpSchema\Record;

/**
 * Handles the methods of the MCP Tasks extension.
 *
 * The extension is not part of the core MCP schema, so requests arrive as
 * generic JSON-RPC records and their params are checked here.
 *
 * @since 0.8.0
 *
 * @phpstan-import-type Task from \WP\MCP\Tasks\McpTaskManager
 */
class TasksHandler {

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
	 * Handles the tasks/get request.
	 *
	 * @since 0.8.0
	 *
	 * @param \WP\McpSchema\Record           $request         Validated JSON-RPC request.
	 * @param \WP\MCP\Core\McpRequestContext $request_context Exact request context.
	 *
	 * @return array<string, mixed> The task with its result or error, or a protocol error.
	 */
	public function get_task( Record $request, McpRequestContext $request_context ): array {
		$task = $this->find_task( $request, $request_context );
		if ( null === $task ) {
			return $this->task_error( $request, $request_context );
		}

		// Polling drives cron on a site with no other traffic.
		if ( McpTaskManager::STATUS_QUEUED === $task['status'] ) {
			McpTaskManager::spawn_cron();
		}

		$result = McpTaskManager::to_wire( $task );
		if ( McpTaskManager::STATUS_COMPLETED === $task['status'] ) {
			$result['result'] = json_decode( is_string( $task['result'] ?? null ) ? $task['result'] : '', false, 512, JSON_THROW_ON_ERROR );
		} elseif ( McpTaskManager::STATUS_FAILED === $task['status'] ) {
			$result['error'] = $task['error'] ?? McpErrorFactory::create_error( McpErrorFactory::INTERNAL_ERROR, 'The task failed.' );
		}

		return $result;
	}

	/**
	 * Handles the tasks/update request.
	 *
	 * Tasks run ability-backed tools, which cannot ask the client for input,
	 * so no input request is ever outstanding and every response is ignored.
	 *
	 * @since 0.8.0
	 *
	 * @param \WP\McpSchema\Record           $request         Validated JSON-RPC request.
	 * @param \WP\MCP\Core\McpRequestContext $request_context Exact request context.
	 *
	 * @return array<string, mixed> An empty acknowledgement or a protocol error.
	 */
	public function update_task( Record $request, McpRequestContext $request_context ): array {
		$task = $this->find_task( $request, $request_context );
		if ( null === $task ) {
			return $this->task_error( $request, $request_context );
		}

		if ( ! ( $this->params( $request )['inputResponses'] ?? null ) instanceof \stdClass ) {
			return McpErrorFactory::invalid_params( $this->request_id( $request ), 'inputResponses must be an object.' );
		}

		return array();
	}

	/**
	 * Handles the tasks/cancel request.
	 *
	 * @since 0.8.0
	 *
	 * @param \WP\McpSchema\Record           $request         Validated JSON-RPC request.
	 * @param \WP\MCP\Core\McpRequestContext $request_context Exact request context.
	 *
	 * @return array<string, mixed> An empty acknowledgement or a protocol error.
	 */
	public function cancel_task( Record $request, McpRequestContext $request_context ): array {
		$task = $this->find_task( $request, $request_context );
		if ( null === $task ) {
			return $this->task_error( $request, $request_context );
		}

		McpTaskManager::cancel( $task['id'] );

		return array();
	}

	/**
	 * Find the task a request names, if the caller may see it.
	 *
	 * @param \WP\McpSchema\Record           $request         Validated JSON-RPC request.
	 * @param \WP\MCP\Core\McpRequestContext $request_context Exact request context.
	 *
	 * @return array<string, mixed>|null The stored task, or null when task_error() explains why not.
	 *
	 * @phpstan-return Task|null
	 */
	private function find_task( Record $request, McpRequestContext $request_context ): ?array {
		if ( ! McpTaskManager::client_supports_tasks( $request_context ) ) {
			return null;
		}

		return McpTaskManager::get_for_current_user( $this->params( $request )['taskId'] ?? null, $this->mcp->get_server_id() );
	}

	/**
	 * Build the protocol error for a request whose task was not found.
	 *
	 * @param \WP\McpSchema\Record           $request         Validated JSON-RPC request.
	 * @param \WP\MCP\Core\McpRequestContext $request_context Exact request context.
	 *
	 * @return array<string, mixed> JSON-RPC error envelope.
	 */
	private function task_error( Record $request, McpRequestContext $request_context ): array {
		return McpTaskManager::client_supports_tasks( $request_context )
			? McpErrorFactory::invalid_params( $this->request_id( $request ), 'Task not found' )
			: McpTaskManager::missing_capability_error( $this->request_id( $request ) );
	}

	/**
	 * Read the ID of a generic JSON-RPC request.
	 *
	 * @param \WP\McpSchema\Record $request Validated JSON-RPC request.
	 *
	 * @return string|int|null
	 */
	private function request_id( Record $request ) {
		$request_id = $request->get( 'id' );

		return is_string( $request_id ) || is_int( $request_id ) ? $request_id : null;
	}

	/**
	 * Read the params of a generic JSON-RPC request.
	 *
	 * @param \WP\McpSchema\Record $request Validated JSON-RPC request.
	 *
	 * @return array<string, mixed>
	 */
	private function params( Record $request ): array {
		$params = $request->has( 'params' ) ? $request->get( 'params' ) : null;
		$fields = array();
		foreach ( $params instanceof \stdClass ? get_object_vars( $params ) : array() as $field => $value ) {
			$fields[ (string) $field ] = $value;
		}

		return $fields;
	}
}
