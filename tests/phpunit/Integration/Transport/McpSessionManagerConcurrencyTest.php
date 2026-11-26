<?php
/**
 * Integration tests for concurrent MCP session mutations.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Integration\Transport;

use WP\MCP\Tests\TestCase;
use WP\MCP\Transport\Infrastructure\SessionManager;

/**
 * Test session writes from independent PHP processes.
 */
final class McpSessionManagerConcurrencyTest extends TestCase {

	/**
	 * Test user ID.
	 *
	 * @var int
	 */
	private int $test_user_id;

	/**
	 * Create the test user.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->test_user_id = self::factory()->user->create();
	}

	/**
	 * Delete the test user.
	 */
	public function tearDown(): void {
		wp_delete_user( $this->test_user_id );
		self::commit_transaction();

		parent::tearDown();
	}

	/**
	 * Stored session maps the concurrent creations start from.
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public static function initial_session_map_provider(): array {
		return array(
			'established map'            => array( 'established', false ),
			'absent row'                 => array( 'absent', false ),
			'empty map'                  => array( 'empty', false ),
			'absent row, read committed' => array( 'absent', true ),
		);
	}

	/**
	 * Test concurrent creation preserves every session, including a user's first sessions.
	 *
	 * @dataProvider initial_session_map_provider
	 *
	 * @param string $initial_map    Stored map before the workers start: established, absent or empty.
	 * @param bool   $read_committed Whether workers use READ COMMITTED with staggered session row inserts.
	 */
	public function test_concurrent_session_creation_preserves_every_session( string $initial_map, bool $read_committed ): void {
		global $wpdb;

		if ( ! function_exists( 'proc_open' ) ) {
			$this->markTestSkipped( 'proc_open() is required for the concurrency integration test.' );
		}

		$existing_session_ids = array();
		if ( 'established' === $initial_map ) {
			$existing_session_ids[] = SessionManager::create_session( $this->test_user_id, array( 'worker' => 'existing' ) );
			$this->assertIsString( $existing_session_ids[0] );
		} elseif ( 'empty' === $initial_map ) {
			$this->assertNotFalse( update_user_meta( $this->test_user_id, self::session_meta_key(), array() ) );
		}
		self::commit_transaction();

		// Delays each worker's insert by its own @mcp_test_insert_delay, after its NOT EXISTS check has run.
		$trigger = $wpdb->prefix . 'mcp_test_session_insert_delay';
		if ( $read_committed ) {
			$wpdb->query( "DROP TRIGGER IF EXISTS {$trigger}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Test-owned trigger name built from the table prefix.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Test-owned trigger; the meta key is prepared.
			$created = $wpdb->query( $wpdb->prepare( "CREATE TRIGGER {$trigger} BEFORE INSERT ON {$wpdb->usermeta} FOR EACH ROW SET @mcp_test_slept = IF( NEW.meta_key = %s, SLEEP( COALESCE( @mcp_test_insert_delay, 0 ) ), 0 )", self::session_meta_key() ) );
			if ( false === $created ) {
				$this->markTestSkipped( 'Creating a trigger is required to force overlapping inserts: ' . $wpdb->last_error );
			}
		}

		$worker_count = 4;
		$barrier_dir  = sys_get_temp_dir() . '/mcp-session-concurrency-' . wp_generate_uuid4();
		$this->assertTrue( wp_mkdir_p( $barrier_dir ) );

		$worker_script = dirname( __DIR__, 2 ) . '/Fixtures/ConcurrentSessionWorker.php';
		$processes     = array();
		$environment   = getenv();
		if ( ! is_array( $environment ) ) {
			$environment = array();
		}
		$environment['WP_TESTS_SKIP_INSTALL'] = '1';

		try {
			for ( $worker_id = 0; $worker_id < $worker_count; ++$worker_id ) {
				$command = sprintf(
					'%s %s %d %s %d %s',
					escapeshellarg( PHP_BINARY ),
					escapeshellarg( $worker_script ),
					$this->test_user_id,
					escapeshellarg( $barrier_dir ),
					$worker_id,
					$read_committed ? 'read-committed' : 'default'
				);

				$pipes = array();
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Independent PHP processes are the behavior under test.
				$process = proc_open(
					$command,
					array(
						0 => array( 'pipe', 'r' ),
						1 => array( 'pipe', 'w' ),
						2 => array( 'pipe', 'w' ),
					),
					$pipes,
					null,
					$environment
				);

				$this->assertIsResource( $process );
				fclose( $pipes[0] );
				$processes[] = array(
					'process' => $process,
					'stdout'  => $pipes[1],
					'stderr'  => $pipes[2],
				);
			}

			$deadline           = microtime( true ) + 10;
			$ready_workers      = glob( $barrier_dir . '/ready-*' ) ?: array();
			$ready_worker_count = count( $ready_workers );
			while ( $ready_worker_count < $worker_count && microtime( true ) < $deadline ) {
				usleep( 1000 );
				$ready_workers      = glob( $barrier_dir . '/ready-*' ) ?: array();
				$ready_worker_count = count( $ready_workers );
			}

			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents -- Test-owned synchronization file under the system temporary directory.
			file_put_contents( $barrier_dir . '/go', '' );
			$this->assertCount( $worker_count, $ready_workers, 'Not every worker reached the write barrier.' );

			foreach ( $processes as &$worker_process ) {
				$stdout = stream_get_contents( $worker_process['stdout'] );
				$stderr = stream_get_contents( $worker_process['stderr'] );
				fclose( $worker_process['stdout'] );
				fclose( $worker_process['stderr'] );
				$exit_code                 = proc_close( $worker_process['process'] );
				$worker_process['process'] = null;
				$worker_process['stdout']  = null;
				$worker_process['stderr']  = null;

				$this->assertSame( 0, $exit_code, $stdout . $stderr );
			}
			unset( $worker_process );

			$session_ids = array();
			for ( $worker_id = 0; $worker_id < $worker_count; ++$worker_id ) {
				$result_file = $barrier_dir . '/result-' . $worker_id . '.json';
				// phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- Reads a local test result file.
				$result = json_decode( (string) file_get_contents( $result_file ), true );

				$this->assertNull( $result['error'] );
				$this->assertIsString( $result['session_id'] );
				$session_ids[] = $result['session_id'];
			}

			wp_cache_delete( $this->test_user_id, 'user_meta' );
			$sessions = SessionManager::get_all_user_sessions( $this->test_user_id );

			$this->assertCount( $worker_count + count( $existing_session_ids ), $sessions );
			foreach ( array_merge( $existing_session_ids, $session_ids ) as $session_id ) {
				$this->assertArrayHasKey( $session_id, $sessions );
			}
			$this->assertCount( 1, get_user_meta( $this->test_user_id, self::session_meta_key(), false ) );
		} finally {
			if ( $read_committed ) {
				$wpdb->query( "DROP TRIGGER IF EXISTS {$trigger}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Test-owned trigger name built from the table prefix.
			}

			if ( ! file_exists( $barrier_dir . '/go' ) ) {
				// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents -- Releases test-owned worker processes during cleanup.
				file_put_contents( $barrier_dir . '/go', '' );
			}

			foreach ( $processes as $worker_process ) {
				if ( is_resource( $worker_process['process'] ) ) {
					$status = proc_get_status( $worker_process['process'] );
					if ( $status['running'] ) {
						proc_terminate( $worker_process['process'] );
					}
				}
				if ( is_resource( $worker_process['stdout'] ) ) {
					fclose( $worker_process['stdout'] );
				}
				if ( is_resource( $worker_process['stderr'] ) ) {
					fclose( $worker_process['stderr'] );
				}
				if ( ! is_resource( $worker_process['process'] ) ) {
					continue;
				}

				proc_close( $worker_process['process'] );
			}

			foreach ( glob( $barrier_dir . '/*' ) ?: array() as $temporary_file ) {
				// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink -- Removes a test-owned temporary file.
				unlink( $temporary_file );
			}
			if ( is_dir( $barrier_dir ) ) {
				// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.directory_rmdir -- Removes the test-owned empty temporary directory.
				rmdir( $barrier_dir );
			}
		}
	}
}
