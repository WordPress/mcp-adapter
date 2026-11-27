<?php
/**
 * MCP Tasks extension behavior through the exact request and response boundary.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Integration;

use WP\MCP\Abilities\ExecuteAbilityAbility;
use WP\MCP\Core\McpAdapter;
use WP\MCP\Core\McpServer;
use WP\MCP\Domain\Tools\McpTool;
use WP\MCP\Tasks\Contracts\McpTaskStoreInterface;
use WP\MCP\Tasks\McpTaskManager;
use WP\MCP\Tasks\OptionsTaskStore;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\TestCase;
use WP\MCP\Transport\Infrastructure\McpWireOrchestrator;
use WP\McpSchema\Schemas;

/** Exercises task creation, the background run and the tasks/* methods. */
final class TasksExtensionTest extends TestCase {

	/** Number of times the test abilities executed. */
	private static int $executions = 0;

	/** User the ability last ran as. */
	private static int $executed_as = -1;

	/** Whether the test abilities' permission callback allows the call. */
	private static bool $allowed = true;

	/** Server registered with the adapter for the current test. */
	private McpServer $server;

	/** User who creates the tasks. */
	private int $user_id;

	/** JSON of the most recent response. */
	private string $last_json = '';

	/** Adapter state to restore after the test. */
	private array $adapter_state = array();

	public function set_up(): void {
		parent::set_up();

		self::$executions  = 0;
		self::$executed_as = -1;
		self::$allowed     = true;

		$this->user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->user_id );

		// Creating a task spawns cron over a loopback request.
		add_filter( 'pre_http_request', '__return_empty_array' );

		foreach ( array( 'optional', 'required' ) as $support ) {
			$this->register_ability_in_hook(
				'test/task-' . $support,
				array(
					'label'               => 'Task ' . $support,
					'description'         => 'Runs as a background task.',
					'category'            => 'test',
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array( 'value' => array( 'type' => 'integer' ) ),
					),
					'permission_callback' => static function (): bool {
						return self::$allowed;
					},
					'execute_callback'    => static function ( array $args ) {
						++self::$executions;
						self::$executed_as = get_current_user_id();
						if ( -1 === ( $args['value'] ?? 0 ) ) {
							return new \WP_Error( 'test_failure', 'The ability failed.' );
						}

						return array( 'doubled' => 2 * ( $args['value'] ?? 0 ) );
					},
					'meta'                => array(
						'mcp' => array(
							'public' => true,
							'task'   => $support,
						),
					),
				)
			);
		}

		$tools = array();
		foreach ( array( 'test/task-optional', 'test/task-required', 'mcp-adapter/execute-ability' ) as $ability ) {
			$tool = McpTool::fromAbility( wp_get_ability( $ability ) );
			$this->assertInstanceOf( McpTool::class, $tool );
			$tools[] = $tool;
		}
		$this->server = $this->makeServer( $tools );

		// The runner looks its server up through the adapter, as a cron request would.
		foreach ( array(
			'servers'     => array( 'srv' => $this->server ),
			'initialized' => true,
		) as $name => $value ) {
			$property = new \ReflectionProperty( McpAdapter::class, $name );
			if ( PHP_VERSION_ID < 80100 ) {
				$property->setAccessible( true );
			}
			$object                       = 'servers' === $name ? McpAdapter::instance() : null;
			$this->adapter_state[ $name ] = $property->getValue( $object );
			$property->setValue( $object, $value );
		}
	}

	public function tear_down(): void {
		foreach ( $this->adapter_state as $name => $value ) {
			$property = new \ReflectionProperty( McpAdapter::class, $name );
			if ( PHP_VERSION_ID < 80100 ) {
				$property->setAccessible( true );
			}
			$property->setValue( 'servers' === $name ? McpAdapter::instance() : null, $value );
		}
		remove_filter( 'pre_http_request', '__return_empty_array' );

		parent::tear_down();
	}

	/** Discovery declares the extension as an empty settings object. */
	public function test_discover_declares_the_extension(): void {
		$response = $this->request( 'server/discover', array() );

		$this->assertSame( array(), $response['result']['capabilities']['extensions'][ McpTaskManager::EXTENSION ] );
		$this->assertStringContainsString( '"io.modelcontextprotocol\/tasks":{}', $this->last_json );
	}

	/** A supporting client gets a task handle, and the result after the background run. */
	public function test_task_is_created_run_and_fetched(): void {
		$created = $this->call_tool( 'optional', array( 'value' => 21 ) )['result'];

		$this->assertSame( 'task', $created['resultType'] );
		$this->assertSame( 'working', $created['status'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{40}$/', $created['taskId'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/', $created['createdAt'] );
		$this->assertSame( HOUR_IN_SECONDS * 1000, $created['ttlMs'] );
		$this->assertSame( 2000, $created['pollIntervalMs'] );
		$this->assertArrayNotHasKey( 'content', $created );
		$this->assertSame( 0, self::$executions );
		$this->assertNotFalse( wp_next_scheduled( McpTaskManager::RUN_HOOK, array( $created['taskId'] ) ) );
		$this->assertNotFalse( wp_next_scheduled( McpTaskManager::DELETE_HOOK, array( $created['taskId'] ) ) );

		$working = $this->task_request( 'tasks/get', $created['taskId'] )['result'];
		$this->assertSame( 'complete', $working['resultType'] );
		$this->assertSame( 'working', $working['status'] );
		$this->assertArrayNotHasKey( 'result', $working );

		// Cron runs without a logged-in user.
		wp_set_current_user( 0 );
		do_action( McpTaskManager::RUN_HOOK, $created['taskId'] );
		$this->assertSame( 0, get_current_user_id() );
		$this->assertSame( 1, self::$executions );
		$this->assertSame( $this->user_id, self::$executed_as );

		wp_set_current_user( $this->user_id );
		$done = $this->task_request( 'tasks/get', $created['taskId'] )['result'];
		$this->assertSame( 'completed', $done['status'] );
		$this->assertSame( 'complete', $done['result']['resultType'] );
		$this->assertFalse( $done['result']['isError'] );
		$this->assertSame( array( 'doubled' => 42 ), $done['result']['structuredContent'] );
		$this->assertSame( '{"doubled":42}', $done['result']['content'][0]['text'] );

		$stored = McpTaskManager::store()->get( $created['taskId'] );
		$this->assertSame( '', $stored['arguments'] ?? null, 'Arguments are dropped once the task is done.' );

		// A second run of the same event must not execute the ability again.
		do_action( McpTaskManager::RUN_HOOK, $created['taskId'] );
		$this->assertSame( 1, self::$executions );
	}

	/** An optional tool runs inline for a client that did not declare the extension. */
	public function test_optional_tool_runs_inline_without_the_capability(): void {
		$result = $this->call_tool( 'optional', array( 'value' => 4 ), array() )['result'];

		$this->assertSame( 'complete', $result['resultType'] );
		$this->assertSame( array( 'doubled' => 8 ), $result['structuredContent'] );
		$this->assertSame( 1, self::$executions );

		$legacy = $this->request(
			'tools/call',
			array(
				'name'      => 'test-task-optional',
				'arguments' => array( 'value' => 5 ),
			),
			null,
			Schemas::V2025_11_25
		)['result'];
		$this->assertSame( array( 'doubled' => 10 ), $legacy['structuredContent'] );
	}

	/** A required tool cannot be served to a client that did not declare the extension. */
	public function test_required_tool_needs_the_capability(): void {
		$error = $this->call_tool( 'required', array( 'value' => 4 ), array() )['error'];

		$this->assertSame( -32021, $error['code'] );
		$this->assertSame( array( 'extensions' => array( McpTaskManager::EXTENSION => array() ) ), $error['data']['requiredCapabilities'] );
		$this->assertSame( 0, self::$executions );

		$this->assertSame( 'task', $this->call_tool( 'required', array( 'value' => 4 ) )['result']['resultType'] );
	}

	/** The default server's gateway tool follows the task support of the ability it targets. */
	public function test_execute_ability_tool_follows_the_target_ability(): void {
		$params = array(
			'name'      => 'mcp-adapter-execute-ability',
			'arguments' => array(
				'ability_name' => 'test/task-optional',
				'parameters'   => array( 'value' => 5 ),
			),
		);

		$created = $this->request( 'tools/call', $params )['result'];
		$this->assertSame( 'task', $created['resultType'] );
		$this->assertSame( 0, self::$executions );

		do_action( McpTaskManager::RUN_HOOK, $created['taskId'] );
		$done = $this->task_request( 'tasks/get', $created['taskId'] )['result'];
		$this->assertSame( 'completed', $done['status'] );
		$this->assertSame( array( 'doubled' => 10 ), $done['result']['structuredContent']['data'] );

		$params['arguments']['ability_name'] = 'mcp-adapter/discover-abilities';
		$params['arguments']['parameters']   = new \stdClass();
		$this->assertSame( 'complete', $this->request( 'tools/call', $params )['result']['resultType'] );
	}

	/** A denied caller gets the usual tool error and no task. */
	public function test_permission_is_checked_before_a_task_is_created(): void {
		self::$allowed = false;

		$result = $this->call_tool( 'required', array( 'value' => 1 ) )['result'];

		$this->assertSame( 'complete', $result['resultType'] );
		$this->assertTrue( $result['isError'] );
	}

	/** Permission is checked again when the task runs. */
	public function test_permission_is_rechecked_when_the_task_runs(): void {
		$task_id       = $this->call_tool( 'optional', array( 'value' => 1 ) )['result']['taskId'];
		self::$allowed = false;

		do_action( McpTaskManager::RUN_HOOK, $task_id );

		$task = $this->task_request( 'tasks/get', $task_id )['result'];
		$this->assertSame( 'completed', $task['status'] );
		$this->assertTrue( $task['result']['isError'] );
		$this->assertSame( 0, self::$executions );
	}

	/** A tool error completes the task; only protocol errors fail it. */
	public function test_tool_error_completes_and_protocol_error_fails(): void {
		$tool_error = $this->call_tool( 'optional', array( 'value' => -1 ) )['result']['taskId'];
		do_action( McpTaskManager::RUN_HOOK, $tool_error );
		$task = $this->task_request( 'tasks/get', $tool_error )['result'];
		$this->assertSame( 'completed', $task['status'] );
		$this->assertTrue( $task['result']['isError'] );
		$this->assertSame( 'The ability failed.', $task['result']['content'][0]['text'] );

		$orphaned = $this->call_tool( 'optional', array( 'value' => 1 ) )['result']['taskId'];
		$other    = self::factory()->user->create();
		$stored   = McpTaskManager::store()->get( $orphaned );
		$this->assertIsArray( $stored );
		McpTaskManager::store()->transition( $orphaned, array( 'queued' ), array( 'user_id' => $other ) );
		wp_delete_user( $other );
		do_action( McpTaskManager::RUN_HOOK, $orphaned );
		McpTaskManager::store()->transition( $orphaned, array( 'failed' ), array( 'user_id' => $this->user_id ) );

		$task = $this->task_request( 'tasks/get', $orphaned )['result'];
		$this->assertSame( 'failed', $task['status'] );
		$this->assertSame( -32603, $task['error']['code'] );
		$this->assertSame( 'The user who created this task no longer exists.', $task['statusMessage'] );
		$this->assertArrayNotHasKey( 'result', $task );
	}

	/** Cancelling a task that has not started keeps it from running. */
	public function test_cancel_before_the_run(): void {
		$task_id = $this->call_tool( 'optional', array( 'value' => 1 ) )['result']['taskId'];

		$ack = $this->task_request( 'tasks/cancel', $task_id )['result'];
		$this->assertSame( 'complete', $ack['resultType'] );
		$this->assertArrayNotHasKey( 'taskId', $ack );
		$this->assertFalse( wp_next_scheduled( McpTaskManager::RUN_HOOK, array( $task_id ) ) );

		do_action( McpTaskManager::RUN_HOOK, $task_id );
		$this->assertSame( 0, self::$executions );
		$this->assertSame( 'cancelled', $this->task_request( 'tasks/get', $task_id )['result']['status'] );
	}

	/** Cancelling a finished task is acknowledged and changes nothing. */
	public function test_cancel_after_the_run_keeps_the_result(): void {
		$task_id = $this->call_tool( 'optional', array( 'value' => 3 ) )['result']['taskId'];
		do_action( McpTaskManager::RUN_HOOK, $task_id );

		$this->assertArrayHasKey( 'result', $this->task_request( 'tasks/cancel', $task_id ) );
		$this->assertSame( 'completed', $this->task_request( 'tasks/get', $task_id )['result']['status'] );
	}

	/** Input responses are acknowledged, since no input request is ever outstanding. */
	public function test_update_is_acknowledged(): void {
		$task_id = $this->call_tool( 'optional', array( 'value' => 1 ) )['result']['taskId'];

		$ack = $this->task_request( 'tasks/update', $task_id, array( 'inputResponses' => array( 'unknown' => array( 'action' => 'cancel' ) ) ) );
		$this->assertSame( 'complete', $ack['result']['resultType'] );

		$this->assertSame( -32602, $this->task_request( 'tasks/update', $task_id )['error']['code'] );
		$this->assertSame( -32602, $this->task_request( 'tasks/update', $task_id, array( 'requestState' => 'state' ) )['error']['code'] );
	}

	/** Tasks are visible only to the user and server that created them. */
	public function test_tasks_are_bound_to_their_creator(): void {
		$task_id = $this->call_tool( 'optional', array( 'value' => 1 ) )['result']['taskId'];

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		foreach ( array( 'tasks/get', 'tasks/cancel', 'tasks/update' ) as $method ) {
			$extra = 'tasks/update' === $method ? array( 'inputResponses' => new \stdClass() ) : array();
			$error = $this->task_request( $method, $task_id, $extra )['error'];
			$this->assertSame( -32602, $error['code'], $method );
			$this->assertStringContainsString( 'Task not found', $error['message'], $method );
		}

		wp_set_current_user( $this->user_id );
		$this->assertSame( 'working', $this->task_request( 'tasks/get', $task_id )['result']['status'] );

		$other_server = new McpServer( 'other', 'mcp/v1', '/other', 'Other', 'desc', '0.0.1', array(), null, null );
		$this->assertSame( -32602, $this->task_request( 'tasks/get', $task_id, array(), $other_server )['error']['code'] );
	}

	/** Unknown and malformed IDs are invalid params; a missing capability is its own error. */
	public function test_protocol_errors(): void {
		foreach ( array( str_repeat( 'a', 40 ), 'not-a-task', 7, null ) as $task_id ) {
			$this->assertSame( -32602, $this->task_request( 'tasks/get', $task_id )['error']['code'] );
		}

		$task_id = $this->call_tool( 'optional', array( 'value' => 1 ) )['result']['taskId'];
		$this->assertSame( -32021, $this->task_request( 'tasks/get', $task_id, array(), null, array() )['error']['code'] );

		$legacy = $this->request( 'tasks/get', array( 'taskId' => $task_id ), null, Schemas::V2025_11_25 );
		$this->assertSame( -32601, $legacy['error']['code'] );
	}

	/** A task past its TTL is deleted and reported as missing. */
	public function test_expired_task_is_deleted(): void {
		$task_id = $this->call_tool( 'optional', array( 'value' => 1 ) )['result']['taskId'];
		McpTaskManager::store()->transition( $task_id, array( 'queued' ), array( 'created_at' => McpTaskManager::now() - HOUR_IN_SECONDS * 1000 ) );

		$this->assertSame( -32602, $this->task_request( 'tasks/get', $task_id )['error']['code'] );
		$this->assertNull( McpTaskManager::store()->get( $task_id ) );
		$this->assertFalse( wp_next_scheduled( McpTaskManager::RUN_HOOK, array( $task_id ) ) );
	}

	/** The cleanup event removes the task. */
	public function test_delete_hook_removes_the_task(): void {
		$task_id = $this->call_tool( 'optional', array( 'value' => 1 ) )['result']['taskId'];

		do_action( McpTaskManager::DELETE_HOOK, $task_id );

		$this->assertNull( McpTaskManager::store()->get( $task_id ) );
	}

	/** Over HTTP the Mcp-Name header must carry the task ID. */
	public function test_http_requires_the_task_id_header(): void {
		$task_id = $this->call_tool( 'optional', array( 'value' => 1 ) )['result']['taskId'];
		$headers = array(
			'mcp-protocol-version' => Schemas::V2026_07_28,
			'mcp-method'           => 'tasks/get',
			'mcp-name'             => $task_id,
		);

		$this->assertSame( 'working', $this->task_request( 'tasks/get', $task_id, array(), null, null, $headers )['result']['status'] );

		$headers['mcp-name'] = str_repeat( 'b', 40 );
		$this->assertSame( -32020, $this->task_request( 'tasks/get', $task_id, array(), null, null, $headers )['error']['code'] );
	}

	/** A task whose server is gone fails instead of staying in progress. */
	public function test_task_fails_when_its_server_is_gone(): void {
		$task_id  = $this->call_tool( 'optional', array( 'value' => 1 ) )['result']['taskId'];
		$property = new \ReflectionProperty( McpAdapter::class, 'servers' );
		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}
		$property->setValue( McpAdapter::instance(), array() );

		do_action( McpTaskManager::RUN_HOOK, $task_id );
		do_action( McpTaskManager::RUN_HOOK, array( 'not-a-task-id' ) );

		$task = $this->task_request( 'tasks/get', $task_id )['result'];
		$this->assertSame( 'failed', $task['status'] );
		$this->assertSame( 'The MCP server for this task is no longer registered.', $task['statusMessage'] );
		$this->assertSame( 0, self::$executions );
	}

	/** A stored call that can no longer be replayed fails with a generic message and is logged. */
	public function test_unreadable_stored_call_fails_the_task(): void {
		$task_id = $this->call_tool( 'optional', array( 'value' => 1 ) )['result']['taskId'];
		McpTaskManager::store()->transition( $task_id, array( 'queued' ), array( 'arguments' => '{broken' ) );

		do_action( McpTaskManager::RUN_HOOK, $task_id );

		$task = $this->task_request( 'tasks/get', $task_id )['result'];
		$this->assertSame( 'failed', $task['status'] );
		$this->assertSame( 'The task could not be completed.', $task['statusMessage'] );
		$this->assertSame( -32603, $task['error']['code'] );
		$this->assertContains( 'Error running task', array_column( DummyErrorHandler::$logs, 'message' ) );
	}

	/** A result the store rejects fails the task instead of leaving it in progress. */
	public function test_result_that_cannot_be_stored_fails_the_task(): void {
		$store  = new class() implements McpTaskStoreInterface {
			/** @var \WP\MCP\Tasks\OptionsTaskStore */
			private $inner;

			public function __construct() {
				$this->inner = new OptionsTaskStore();
			}

			public function create( array $task ): bool {
				return $this->inner->create( $task );
			}

			public function get( string $task_id ): ?array {
				return $this->inner->get( $task_id );
			}

			public function transition( string $task_id, array $expected, array $changes ): ?array {
				return 'completed' === ( $changes['status'] ?? null ) ? null : $this->inner->transition( $task_id, $expected, $changes );
			}

			public function delete( string $task_id ): void {
				$this->inner->delete( $task_id );
			}
		};
		$filter = static function () use ( $store ) {
			return $store;
		};
		add_filter( 'mcp_adapter_task_store', $filter );

		$task_id = $this->call_tool( 'optional', array( 'value' => 1 ) )['result']['taskId'];
		do_action( McpTaskManager::RUN_HOOK, $task_id );
		$task = $this->task_request( 'tasks/get', $task_id )['result'];
		remove_filter( 'mcp_adapter_task_store', $filter );

		$this->assertSame( 'failed', $task['status'] );
		$this->assertSame( 'The task result could not be stored.', $task['statusMessage'] );
	}

	/** A task that cannot be stored or scheduled is a protocol error, and nothing is left behind. */
	public function test_task_creation_failures(): void {
		$store  = new class() implements McpTaskStoreInterface {
			public function create( array $task ): bool {
				return false;
			}

			public function get( string $task_id ): ?array {
				return null;
			}

			public function transition( string $task_id, array $expected, array $changes ): ?array {
				return null;
			}

			public function delete( string $task_id ): void {}
		};
		$filter = static function () use ( $store ) {
			return $store;
		};
		add_filter( 'mcp_adapter_task_store', $filter );
		$error = $this->call_tool( 'optional', array( 'value' => 1 ) )['error'];
		remove_filter( 'mcp_adapter_task_store', $filter );
		$this->assertSame( -32603, $error['code'] );
		$this->assertContains( 'Task creation failed', array_column( DummyErrorHandler::$logs, 'message' ) );

		$block = static function () {
			return new \WP_Error( 'blocked', 'Scheduling is blocked.' );
		};
		add_filter( 'pre_schedule_event', $block );
		$error = $this->call_tool( 'optional', array( 'value' => 1 ) )['error'];
		remove_filter( 'pre_schedule_event', $block );
		$this->assertSame( -32603, $error['code'] );
		$this->assertSame( 0, self::$executions );

		// A filter that returns something else leaves the default store in place.
		add_filter( 'mcp_adapter_task_store', '__return_false' );
		$this->assertInstanceOf( OptionsTaskStore::class, McpTaskManager::store() );
		remove_filter( 'mcp_adapter_task_store', '__return_false' );
	}

	/** The options store refuses malformed input and missing tasks. */
	public function test_options_store_edges(): void {
		$store = new OptionsTaskStore();

		$this->assertFalse( $store->create( array( 'status' => 'queued' ) ) );
		$this->assertNull( $store->transition( str_repeat( 'c', 40 ), array( 'queued' ), array( 'status' => 'running' ) ) );

		// A stored value without the fields a task needs is treated as missing.
		$task_id = str_repeat( 'd', 40 );
		$this->assertTrue( $store->create( array( 'id' => $task_id ) ) );
		$this->assertSame( -32602, $this->task_request( 'tasks/get', $task_id )['error']['code'] );
		do_action( McpTaskManager::RUN_HOOK, $task_id );
		$this->assertSame( array( 'id' => $task_id ), $store->get( $task_id ) );
	}

	/** The gateway filter leaves other tools and unknown abilities alone. */
	public function test_execute_ability_task_support_filter_edges(): void {
		$gateway = $this->server->get_mcp_tool( 'mcp-adapter-execute-ability' );
		$other   = $this->server->get_mcp_tool( 'test-task-optional' );
		$this->assertInstanceOf( McpTool::class, $gateway );
		$this->assertInstanceOf( McpTool::class, $other );

		$this->assertSame( 'required', ExecuteAbilityAbility::task_support( 'required', array( 'ability_name' => 'test/task-optional' ), $other ) );
		$this->assertNull( ExecuteAbilityAbility::task_support( null, array( 'ability_name' => 'test/does-not-exist' ), $gateway ) );
		$this->assertNull( ExecuteAbilityAbility::task_support( null, 'not-an-array', $gateway ) );
		$this->assertSame( 'required', ExecuteAbilityAbility::task_support( null, array( 'ability_name' => 'test/task-required' ), $gateway ) );
		$this->assertNull( $gateway->get_task_support( array( 'ability_name' => 'mcp-adapter/discover-abilities' ) ) );
	}

	/** An unknown `meta.mcp.task` value keeps the ability from becoming a tool. */
	public function test_invalid_task_support_is_rejected(): void {
		$this->register_ability_in_hook(
			'test/task-invalid',
			array(
				'label'               => 'Invalid task support',
				'description'         => 'Declares an unknown task support value.',
				'category'            => 'test',
				'permission_callback' => '__return_true',
				'execute_callback'    => '__return_empty_array',
				'meta'                => array( 'mcp' => array( 'task' => 'always' ) ),
			)
		);

		$tool = McpTool::fromAbility( wp_get_ability( 'test/task-invalid' ) );

		$this->assertWPError( $tool );
		$this->assertSame( 'mcp_ability_invalid_task_support', $tool->get_error_code() );
	}

	/**
	 * Call one of the task tools.
	 *
	 * @param string     $support      'optional' or 'required'.
	 * @param array      $arguments    Tool arguments.
	 * @param array|null $capabilities Client capabilities; null declares the Tasks extension.
	 */
	private function call_tool( string $support, array $arguments, ?array $capabilities = null ): array {
		return $this->request(
			'tools/call',
			array(
				'name'      => 'test-task-' . $support,
				'arguments' => $arguments,
			),
			$capabilities
		);
	}

	/**
	 * Send one tasks/* request.
	 *
	 * @param string          $method       Method name.
	 * @param mixed           $task_id      Task ID.
	 * @param array           $extra        Additional params.
	 * @param McpServer|null  $server       Server to send to; defaults to the test server.
	 * @param array|null      $capabilities Client capabilities; null declares the Tasks extension.
	 * @param array|null      $headers      HTTP headers; null sends over STDIO.
	 */
	private function task_request( string $method, $task_id, array $extra = array(), ?McpServer $server = null, ?array $capabilities = null, ?array $headers = null ): array {
		return $this->request( $method, array_merge( array( 'taskId' => $task_id ), $extra ), $capabilities, Schemas::V2026_07_28, $server, $headers );
	}

	/** Send one raw message through a newly constructed orchestrator. */
	private function request( string $method, array $params, ?array $capabilities = null, string $revision = Schemas::V2026_07_28, ?McpServer $server = null, ?array $headers = null ): array {
		if ( Schemas::V2026_07_28 === $revision ) {
			$params['_meta'] = array(
				'io.modelcontextprotocol/protocolVersion'    => Schemas::V2026_07_28,
				'io.modelcontextprotocol/clientCapabilities' => (object) ( $capabilities ?? array( 'extensions' => array( McpTaskManager::EXTENSION => new \stdClass() ) ) ),
			);
		}
		$wire     = new McpWireOrchestrator( ( $server ?? $this->server )->create_transport_context() );
		$message  = $wire->decode(
			(string) wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => $method,
					'params'  => (object) $params,
				)
			)
		);
		$response = $wire->process(
			$message,
			null === $headers ? 'STDIO' : 'HTTP',
			null === $headers ? array() : array(
				'headers'          => $headers,
				'protocol_version' => $headers['mcp-protocol-version'] ?? null,
			),
			Schemas::V2025_11_25 === $revision ? array( 'capabilities' => new \stdClass() ) : null
		)['response'];

		$this->last_json = (string) wp_json_encode( $response );

		return json_decode( $this->last_json, true );
	}
}
