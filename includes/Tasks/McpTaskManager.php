<?php
/**
 * Task lifecycle for the MCP Tasks extension.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Tasks;

use WP\MCP\Core\McpRequestContext;
use WP\MCP\Core\McpServer;
use WP\MCP\Domain\Tools\McpTool;
use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;
use WP\MCP\Tasks\Contracts\McpTaskStoreInterface;
use WP\McpSchema\Schemas;
use WP_Error;

/**
 * Creates, reads and cancels tasks, and maps them to the wire shape.
 *
 * A task moves `queued` → `running` → `completed` or `failed`, or from
 * `queued` to `cancelled`. Clients see `queued` and `running` as `working`.
 *
 * @internal
 *
 * @since 0.8.0
 *
 * @phpstan-type Task array{id: string, server_id: string, tool: string, arguments: string, user_id: int, status: string, created_at: int, updated_at: int, ttl: int, status_message?: mixed, result?: mixed, error?: mixed}
 */
final class McpTaskManager {

	/**
	 * Extension identifier used in client and server capabilities.
	 *
	 * @var string
	 */
	public const EXTENSION = 'io.modelcontextprotocol/tasks';

	/**
	 * Cron hook that runs one task.
	 *
	 * @var string
	 */
	public const RUN_HOOK = 'mcp_adapter_run_task';

	/**
	 * Cron hook that deletes one task after its TTL.
	 *
	 * @var string
	 */
	public const DELETE_HOOK = 'mcp_adapter_delete_task';

	public const SUPPORT_OPTIONAL = 'optional';
	public const SUPPORT_REQUIRED = 'required';

	public const STATUS_QUEUED    = 'queued';
	public const STATUS_RUNNING   = 'running';
	public const STATUS_COMPLETED = 'completed';
	public const STATUS_FAILED    = 'failed';
	public const STATUS_CANCELLED = 'cancelled';

	/**
	 * Get the task store.
	 *
	 * @since 0.8.0
	 */
	public static function store(): McpTaskStoreInterface {
		$default = new OptionsTaskStore();

		/**
		 * Filters where MCP tasks are stored.
		 *
		 * @since 0.8.0
		 *
		 * @param \WP\MCP\Tasks\Contracts\McpTaskStoreInterface $store The task store. Default stores each task as an option.
		 */
		$store = apply_filters( 'mcp_adapter_task_store', $default );

		return $store instanceof McpTaskStoreInterface ? $store : $default;
	}

	/**
	 * Whether the client declared the Tasks extension on this request.
	 *
	 * Capabilities are per request under 2026-07-28, the only revision the
	 * extension is defined for.
	 *
	 * @since 0.8.0
	 *
	 * @param \WP\MCP\Core\McpRequestContext $request_context Request context.
	 */
	public static function client_supports_tasks( McpRequestContext $request_context ): bool {
		if ( Schemas::V2026_07_28 !== $request_context->revision() ) {
			return false;
		}

		$extensions = $request_context->client_capabilities()->extensions ?? null;

		return $extensions instanceof \stdClass && ( $extensions->{self::EXTENSION} ?? null ) instanceof \stdClass;
	}

	/**
	 * Build the error for a client that did not declare the Tasks extension.
	 *
	 * @since 0.8.0
	 *
	 * @param string|int|float|null $request_id Request ID.
	 *
	 * @return array<string, mixed> JSON-RPC error envelope.
	 */
	public static function missing_capability_error( $request_id ): array {
		return McpErrorFactory::create_error_response(
			$request_id,
			McpErrorFactory::MISSING_CAPABILITY,
			'Missing required client capability',
			array(
				'requiredCapabilities' => (object) array(
					'extensions' => (object) array( self::EXTENSION => new \stdClass() ),
				),
			)
		);
	}

	/**
	 * Store a task for a tool call and schedule its background run.
	 *
	 * @since 0.8.0
	 *
	 * @param \WP\MCP\Core\McpServer       $server    Server the tool was called on.
	 * @param \WP\MCP\Domain\Tools\McpTool $tool      Tool to run.
	 * @param \stdClass                    $arguments Validated tool arguments as the client sent them.
	 *
	 * @return array<string, mixed>|\WP_Error The stored task.
	 *
	 * @phpstan-return Task|\WP_Error
	 */
	public static function create( McpServer $server, McpTool $tool, \stdClass $arguments ) {
		$arguments_json = wp_json_encode( $arguments );
		if ( false === $arguments_json ) {
			return new WP_Error( 'mcp_task_invalid_arguments', 'Task arguments cannot be JSON encoded.' );
		}

		/**
		 * Filters how long a task is kept, counted from its creation.
		 *
		 * The task and its result are deleted after this time, whether or not
		 * the client fetched the result.
		 *
		 * @since 0.8.0
		 *
		 * @param int                          $ttl    Time to live in seconds. Default one hour, minimum one minute.
		 * @param \WP\MCP\Domain\Tools\McpTool $tool   The tool being called.
		 * @param \WP\MCP\Core\McpServer       $server The MCP server instance.
		 */
		$ttl = max( MINUTE_IN_SECONDS, (int) apply_filters( 'mcp_adapter_task_ttl', HOUR_IN_SECONDS, $tool, $server ) );
		$now = self::now();

		$task = array(
			// Task IDs must not be guessable. wp_generate_uuid4() is not random enough for that.
			'id'         => bin2hex( random_bytes( 20 ) ),
			'server_id'  => $server->get_server_id(),
			'tool'       => $tool->get_name(),
			'arguments'  => $arguments_json,
			'user_id'    => get_current_user_id(),
			'status'     => self::STATUS_QUEUED,
			'created_at' => $now,
			'updated_at' => $now,
			'ttl'        => $ttl,
		);

		$store = self::store();
		if ( ! $store->create( $task ) ) {
			return new WP_Error( 'mcp_task_not_stored', 'The task could not be stored.' );
		}

		$scheduled = wp_schedule_single_event( time(), self::RUN_HOOK, array( $task['id'] ), true );
		if ( is_wp_error( $scheduled ) ) {
			$store->delete( $task['id'] );

			return $scheduled;
		}
		wp_schedule_single_event( time() + $ttl, self::DELETE_HOOK, array( $task['id'] ) );
		self::spawn_cron();

		return $task;
	}

	/**
	 * Read a task the current user created on this server.
	 *
	 * A task that exists but belongs to another user or server is reported
	 * as missing, so task IDs cannot be probed.
	 *
	 * @since 0.8.0
	 *
	 * @param mixed  $task_id   Client-supplied task ID.
	 * @param string $server_id ID of the server handling the request.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @phpstan-return Task|null
	 */
	public static function get_for_current_user( $task_id, string $server_id ): ?array {
		if ( ! is_string( $task_id ) || 1 !== preg_match( '/^[a-f0-9]{40}$/D', $task_id ) ) {
			return null;
		}

		$task = self::store()->get( $task_id );
		if ( null === $task || ! self::is_complete_record( $task ) ) {
			return null;
		}
		if ( $task['server_id'] !== $server_id || get_current_user_id() !== $task['user_id'] ) {
			return null;
		}
		if ( self::now() >= $task['created_at'] + $task['ttl'] * 1000 ) {
			self::delete( $task_id );

			return null;
		}

		return $task;
	}

	/**
	 * Cancel a task that has not started.
	 *
	 * PHP cannot interrupt a running callback, so a running task finishes
	 * and keeps its outcome.
	 *
	 * @since 0.8.0
	 *
	 * @param string $task_id Task ID.
	 */
	public static function cancel( string $task_id ): void {
		$cancelled = self::store()->transition(
			$task_id,
			array( self::STATUS_QUEUED ),
			array(
				'status'         => self::STATUS_CANCELLED,
				'arguments'      => '',
				'status_message' => 'The task was cancelled before it started.',
				'updated_at'     => self::now(),
			)
		);
		if ( null === $cancelled ) {
			return;
		}

		wp_clear_scheduled_hook( self::RUN_HOOK, array( $task_id ) );
	}

	/**
	 * Delete a task and its pending events.
	 *
	 * @since 0.8.0
	 *
	 * @param string $task_id Task ID.
	 */
	public static function delete( string $task_id ): void {
		self::store()->delete( $task_id );
		wp_clear_scheduled_hook( self::RUN_HOOK, array( $task_id ) );
		wp_clear_scheduled_hook( self::DELETE_HOOK, array( $task_id ) );
	}

	/**
	 * Ask WordPress to run due cron events now.
	 *
	 * Called when a task is created and again while a client polls a task
	 * that has not started, so a site without visitors still runs it.
	 *
	 * @since 0.8.0
	 */
	public static function spawn_cron(): void {
		// A site that runs cron from the system scheduler has opted out of loopback requests.
		if ( defined( 'DISABLE_WP_CRON' ) && constant( 'DISABLE_WP_CRON' ) ) {
			return;
		}

		spawn_cron();
	}

	/**
	 * Map a stored task to the `Task` fields shared by every task response.
	 *
	 * @since 0.8.0
	 *
	 * @param array<string, mixed> $task Stored task.
	 *
	 * @phpstan-param Task $task
	 *
	 * @return array<string, mixed>
	 */
	public static function to_wire( array $task ): array {
		$status = $task['status'];
		if ( self::STATUS_QUEUED === $status || self::STATUS_RUNNING === $status ) {
			$status = 'working';
		}

		$wire = array(
			'taskId' => $task['id'],
			'status' => $status,
		);
		if ( isset( $task['status_message'] ) && is_string( $task['status_message'] ) ) {
			$wire['statusMessage'] = $task['status_message'];
		}
		$wire['createdAt']     = self::iso8601( $task['created_at'] );
		$wire['lastUpdatedAt'] = self::iso8601( $task['updated_at'] );
		$wire['ttlMs']         = $task['ttl'] * 1000;

		/**
		 * Filters how often a client is asked to poll a task.
		 *
		 * @since 0.8.0
		 *
		 * @param int                  $interval Interval in milliseconds. Default 2000.
		 * @param array<string, mixed> $task     The stored task.
		 */
		$wire['pollIntervalMs'] = max( 1, (int) apply_filters( 'mcp_adapter_task_poll_interval', 2000, $task ) );

		return $wire;
	}

	/**
	 * Current time in integer milliseconds.
	 *
	 * Integers survive serialization unchanged, which the store's
	 * compare-and-swap depends on.
	 *
	 * @since 0.8.0
	 */
	public static function now(): int {
		return (int) round( microtime( true ) * 1000 );
	}

	/**
	 * Whether a stored value has the fields every task needs.
	 *
	 * @param array<string, mixed> $task Stored value.
	 *
	 * @phpstan-assert-if-true Task $task
	 */
	public static function is_complete_record( array $task ): bool {
		foreach ( array( 'id', 'server_id', 'tool', 'arguments', 'status' ) as $field ) {
			if ( ! isset( $task[ $field ] ) || ! is_string( $task[ $field ] ) ) {
				return false;
			}
		}
		foreach ( array( 'user_id', 'created_at', 'updated_at', 'ttl' ) as $field ) {
			if ( ! isset( $task[ $field ] ) || ! is_int( $task[ $field ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Format a millisecond timestamp as ISO 8601 in UTC.
	 *
	 * @param int $milliseconds Unix time in milliseconds.
	 */
	private static function iso8601( int $milliseconds ): string {
		return gmdate( 'Y-m-d\TH:i:s', intdiv( $milliseconds, 1000 ) ) . sprintf( '.%03dZ', $milliseconds % 1000 );
	}
}
