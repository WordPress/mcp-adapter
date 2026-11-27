<?php
/**
 * Background runner for MCP tasks.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Tasks;

use WP\MCP\Core\McpAdapter;
use WP\MCP\Core\McpRequestContext;
use WP\MCP\Core\McpServer;
use WP\MCP\Handlers\Tools\ToolsHandler;
use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;
use WP\McpSchema\Record\CallToolRequest;
use WP\McpSchema\Record\CallToolResult;
use WP\McpSchema\Schemas;

/**
 * Runs a stored tool call from WP-Cron and records its outcome.
 *
 * @internal
 *
 * @since 0.8.0
 */
final class McpTaskRunner {

	/**
	 * Tasks this process claimed and has not finished, keyed by task ID.
	 *
	 * @var array<string, true>
	 */
	private static array $running = array();

	/**
	 * Hook the cron events. Runs on every request, so it only adds actions.
	 *
	 * @since 0.8.0
	 */
	public static function register(): void {
		add_action( McpTaskManager::RUN_HOOK, array( self::class, 'run' ) );
		add_action( McpTaskManager::DELETE_HOOK, array( McpTaskManager::class, 'delete' ) );
	}

	/**
	 * Run one task.
	 *
	 * @since 0.8.0
	 *
	 * @param mixed $task_id Task ID from the cron event.
	 */
	public static function run( $task_id ): void {
		if ( ! is_string( $task_id ) ) {
			return;
		}

		// Claiming the task is atomic, so a cancelled or already claimed task is left alone.
		$task = McpTaskManager::store()->transition(
			$task_id,
			array( McpTaskManager::STATUS_QUEUED ),
			array(
				'status'     => McpTaskManager::STATUS_RUNNING,
				'updated_at' => McpTaskManager::now(),
			)
		);
		if ( null === $task || ! McpTaskManager::is_complete_record( $task ) ) {
			return;
		}

		// A fatal error or timeout skips the code below. Without this the task would stay `working` until its TTL.
		self::$running[ $task_id ] = true;
		register_shutdown_function(
			static function () use ( $task_id ): void {
				if ( ! isset( self::$running[ $task_id ] ) ) {
					return;
				}
				self::finish( $task_id, self::failure( 'The task stopped before it finished.' ) );
			}
		);

		$start_time    = microtime( true );
		$previous_user = get_current_user_id();
		$server        = null;
		try {
			$server = self::server( $task['server_id'] );
			if ( null === $server ) {
				$changes = self::failure( 'The MCP server for this task is no longer registered.' );
			} elseif ( 0 !== $task['user_id'] && false === get_userdata( $task['user_id'] ) ) {
				$changes = self::failure( 'The user who created this task no longer exists.' );
			} else {
				// The tool call re-runs the permission check as this user.
				wp_set_current_user( $task['user_id'] );
				$changes = self::execute( $server, $task );
			}
		} catch ( \Throwable $throwable ) {
			$changes = self::failure( 'The task could not be completed.' );
			if ( null !== $server ) {
				$server->get_error_handler()->log(
					'Error running task',
					array(
						'tool'      => $task['tool'],
						'exception' => $throwable->getMessage(),
					)
				);
			}
		} finally {
			wp_set_current_user( $previous_user );
		}

		// A result too large for the store must not leave the task `working`.
		if ( ! self::finish( $task_id, $changes ) && McpTaskManager::STATUS_COMPLETED === $changes['status'] ) {
			$changes = self::failure( 'The task result could not be stored.' );
			self::finish( $task_id, $changes );
		}

		if ( null === $server ) {
			return;
		}

		$server->get_observability_handler()->record_event(
			'mcp.task',
			array(
				'status'    => McpTaskManager::STATUS_COMPLETED === $changes['status'] ? 'success' : 'error',
				'server_id' => $task['server_id'],
				'tool_name' => $task['tool'],
			),
			( microtime( true ) - $start_time ) * 1000
		);
	}

	/**
	 * Run the stored tool call through the same handler an inline call uses.
	 *
	 * @param \WP\MCP\Core\McpServer $server Server the task belongs to.
	 * @param array{id: string, tool: string, arguments: string} $task Claimed task.
	 *
	 * @return array<string, mixed> Fields to store, including the final `status`.
	 *
	 * @throws \Throwable If the stored call or its result does not fit the schema.
	 */
	private static function execute( McpServer $server, array $task ): array {
		$schema  = $server->get_schemas()->forVersion( Schemas::V2026_07_28 );
		$message = (object) array(
			'jsonrpc' => '2.0',
			'id'      => $task['id'],
			'method'  => 'tools/call',
			'params'  => (object) array(
				'name'      => $task['tool'],
				'arguments' => json_decode( $task['arguments'], false, 512, JSON_THROW_ON_ERROR ),
				'_meta'     => (object) array(
					'io.modelcontextprotocol/protocolVersion' => Schemas::V2026_07_28,
					'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
				),
			),
		);

		$handler = new ToolsHandler( $server );
		$result  = $handler->call_tool_for_task(
			$schema->fromValue( CallToolRequest::class, $message ),
			new McpRequestContext( $schema, new \stdClass(), null, 'task' )
		);

		// A protocol error fails the task. A tool error is a completed call with `isError`.
		if ( isset( $result['error'] ) && is_array( $result['error'] ) ) {
			return array(
				'status'         => McpTaskManager::STATUS_FAILED,
				'status_message' => is_string( $result['error']['message'] ?? null ) ? $result['error']['message'] : 'The tool call failed.',
				'error'          => $result['error'],
			);
		}

		$result['resultType'] = 'complete';
		$encoded              = wp_json_encode( $schema->fromArray( CallToolResult::class, $result ), JSON_THROW_ON_ERROR );
		if ( false === $encoded ) {
			throw new \RuntimeException( 'Tool result cannot be JSON encoded.' );
		}

		return array(
			'status' => McpTaskManager::STATUS_COMPLETED,
			'result' => $encoded,
		);
	}

	/**
	 * Build the stored fields for a task that failed outside the tool call.
	 *
	 * @param string $message Reason shown to the client.
	 *
	 * @return array<string, mixed>
	 */
	private static function failure( string $message ): array {
		return array(
			'status'         => McpTaskManager::STATUS_FAILED,
			'status_message' => $message,
			'error'          => McpErrorFactory::create_error( McpErrorFactory::INTERNAL_ERROR, $message ),
		);
	}

	/**
	 * Move a running task to its terminal status.
	 *
	 * @param string               $task_id Task ID.
	 * @param array<string, mixed> $changes Fields to store, including the terminal `status`.
	 *
	 * @return bool Whether the task was updated.
	 */
	private static function finish( string $task_id, array $changes ): bool {
		unset( self::$running[ $task_id ] );
		$changes['updated_at'] = McpTaskManager::now();
		// The input is not needed again and may be sensitive.
		$changes['arguments'] = '';

		return null !== McpTaskManager::store()->transition( $task_id, array( McpTaskManager::STATUS_RUNNING ), $changes );
	}

	/**
	 * Find the server a task was created on.
	 *
	 * Servers are registered on `rest_api_init`, which a cron request never fires.
	 *
	 * @param string $server_id Server ID.
	 */
	private static function server( string $server_id ): ?McpServer {
		$adapter = McpAdapter::instance();
		$adapter->init();

		return $adapter->get_server( $server_id );
	}
}
