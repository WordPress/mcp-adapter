<?php
/**
 * Worker used by the session concurrency integration test.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

use WP\MCP\Transport\Infrastructure\SessionManager;

require dirname( __DIR__ ) . '/bootstrap.php';

$wp_mcp_test_user_id     = isset( $argv[1] ) ? (int) $argv[1] : 0;
$wp_mcp_test_barrier_dir = isset( $argv[2] ) ? $argv[2] : '';
$wp_mcp_test_worker_id   = isset( $argv[3] ) ? $argv[3] : '';
$wp_mcp_test_isolation   = isset( $argv[4] ) ? $argv[4] : '';
$wp_mcp_test_result_file = $wp_mcp_test_barrier_dir . '/result-' . $wp_mcp_test_worker_id . '.json';

try {
	$wp_mcp_session_meta_key = 'mcp_adapter_sessions';
	if ( is_multisite() ) {
		$wp_mcp_current_blog_id = (int) get_current_blog_id();
		if ( $wp_mcp_current_blog_id >= 1 ) {
			$wp_mcp_session_meta_key .= '_' . $wp_mcp_current_blog_id;
		}
	}

	if ( 'read-committed' === $wp_mcp_test_isolation ) {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Session settings of this test worker's own connection.
		$wpdb->query( 'SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED' );
		// Read by the test's delay trigger: staggers each worker's session row insert.
		$wpdb->query( $wpdb->prepare( 'SET @mcp_test_insert_delay = %f', 0.3 * (int) $wp_mcp_test_worker_id ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	// Hold every worker before its first session write or write lock, after each has read the map.
	add_filter(
		'query',
		static function ( $query ) use ( $wp_mcp_test_barrier_dir, $wp_mcp_test_worker_id, $wp_mcp_session_meta_key ) {
			global $wpdb;

			$is_session_write = preg_match( '/^\s*(?:INSERT|UPDATE)\b/i', $query )
				&& false !== strpos( $query, $wpdb->usermeta )
				&& false !== strpos( $query, "'" . $wp_mcp_session_meta_key . "'" );
			if ( ! $is_session_write && ! preg_match( '/^\s*SELECT GET_LOCK\(/i', $query ) ) {
				return $query;
			}

			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents -- Test-owned synchronization file under the system temporary directory.
			file_put_contents( $wp_mcp_test_barrier_dir . '/ready-' . $wp_mcp_test_worker_id, '' );
			$deadline = microtime( true ) + 10;

			while ( ! file_exists( $wp_mcp_test_barrier_dir . '/go' ) ) {
				if ( microtime( true ) >= $deadline ) {
					throw new \RuntimeException( 'Timed out waiting for the concurrency barrier.' );
				}

				usleep( 1000 );
			}

			return $query;
		}
	);

	$wp_mcp_test_session_id = SessionManager::create_session( $wp_mcp_test_user_id, array( 'worker' => $wp_mcp_test_worker_id ) );
	// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents -- Test-owned result file under the system temporary directory.
	file_put_contents(
		$wp_mcp_test_result_file,
		wp_json_encode(
			array(
				'session_id' => $wp_mcp_test_session_id,
				'error'      => null,
			)
		)
	);
} catch ( \Throwable $wp_mcp_test_throwable ) {
	// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents -- Test-owned result file under the system temporary directory.
	file_put_contents(
		$wp_mcp_test_result_file,
		wp_json_encode(
			array(
				'session_id' => false,
				'error'      => $wp_mcp_test_throwable->getMessage(),
			)
		)
	);
	exit( 1 );
}
