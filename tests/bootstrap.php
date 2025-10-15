<?php
/**
 * Bootstrap the PHPUnit tests.
 *
 * @package mcp-adapter
 *
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals
 * phpcs:disable WordPressVIPMinimum.Files.IncludingFile.UsingVariable
 */

define( 'TESTS_REPO_ROOT_DIR', dirname( __DIR__ ) );

// Set custom debug log location for tests.
define( 'WP_DEBUG_LOG_FILE', TESTS_REPO_ROOT_DIR . '/tests/_output/debug.log' );

// Load Composer dependencies if applicable.
if ( file_exists( TESTS_REPO_ROOT_DIR . '/vendor/autoload.php' ) ) {
	require_once TESTS_REPO_ROOT_DIR . '/vendor/autoload.php';
}

// Detect where to load the WordPress tests environment from.
if ( false !== getenv( 'WP_TESTS_DIR' ) ) {
	$_test_root = getenv( 'WP_TESTS_DIR' );
} elseif ( false !== getenv( 'WP_DEVELOP_DIR' ) ) {
	$_test_root = getenv( 'WP_DEVELOP_DIR' ) . '/tests/phpunit';
} elseif ( false !== getenv( 'WP_PHPUNIT__DIR' ) ) {
	$_test_root = getenv( 'WP_PHPUNIT__DIR' );
} elseif ( file_exists( TESTS_REPO_ROOT_DIR . '/../../../../../tests/phpunit/includes/functions.php' ) ) {
	$_test_root = TESTS_REPO_ROOT_DIR . '/../../../../../tests/phpunit';
} else { // Fallback.
	$_test_root = '/tmp/wordpress-tests-lib';
}

// Give access to tests_add_filter() function.
require_once $_test_root . '/includes/functions.php';

// Activate the plugin.
tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		// Load the abilities API, next to the MCP adapter.
		require_once dirname( __DIR__, 2 ) . '/abilities-api/abilities-api.php';
		// Load the MCP adapter.
		require_once dirname( __DIR__ ) . '/mcp-adapter.php';
	}
);

// Start up the WP testing environment.
require $_test_root . '/includes/bootstrap.php';

// Load WP-CLI stubs for testing WP-CLI commands
// This includes essential classes and functions extracted from php-stubs/wp-cli-stubs
require_once __DIR__ . '/Stubs/WpCliStubs.php';
\WP\MCP\Tests\Stubs\WpCliStubs::init();

// Mock WordPress functions that may not be available in test environment.
if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	/**
	 * Mock wp_generate_uuid4 function for testing.
	 *
	 * This is a temporary mock for the WordPress function that may not be available
	 * in the test environment. In a production environment, this function is provided
	 * by WordPress core.
	 *
	 * @return string A test session UUID.
	 */
	function wp_generate_uuid4() {
		return 'test-session-' . uniqid();
	}
}
