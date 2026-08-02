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
	public function set_up(): void {
		parent::set_up();

		$this->test_user_id = self::factory()->user->create();
	}

	/**
	 * Delete the test user.
	 */
	public function tear_down(): void {
		wp_delete_user( $this->test_user_id );
		self::commit_transaction();

		parent::tear_down();
	}

	/**
	 * Test concurrent creation preserves every session in an established map.
	 */
	public function test_concurrent_session_creation_preserves_every_session(): void {
		if ( ! function_exists( 'proc_open' ) ) {
			$this->markTestSkipped( 'proc_open() is required for the concurrency integration test.' );
		}

		$existing_session_id = SessionManager::create_session( $this->test_user_id, array( 'worker' => 'existing' ) );
		$this->assertIsString( $existing_session_id );
		self::commit_transaction();

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
					'%s %s %d %s %d',
					escapeshellarg( PHP_BINARY ),
					escapeshellarg( $worker_script ),
					$this->test_user_id,
					escapeshellarg( $barrier_dir ),
					$worker_id
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

			$this->assertCount( $worker_count + 1, $sessions );
			$this->assertArrayHasKey( $existing_session_id, $sessions );
			foreach ( $session_ids as $session_id ) {
				$this->assertArrayHasKey( $session_id, $sessions );
			}
			$this->assertCount( 1, get_user_meta( $this->test_user_id, 'mcp_adapter_sessions_' . get_current_blog_id(), false ) );
		} finally {
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
