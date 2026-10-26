<?php
/**
 * Isolated cache backend doubles exercising the real STDIO serving loop.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Cli;

use WP\MCP\Core\McpServer;
use WP\MCP\Domain\Tools\McpTool;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\Fixtures\DummyObservabilityHandler;

/** @return bool|null */
function wp_using_ext_object_cache() {
	global $wp_mcp_test_configuration;
	return $wp_mcp_test_configuration['external'];
}

/** @param string $feature Cache capability. */
function wp_cache_supports( string $feature ): bool {
	global $wp_mcp_test_configuration;
	if ( 'flush_runtime' !== $feature ) {
		throw new \LogicException( 'Unexpected cache feature.' );
	}
	return $wp_mcp_test_configuration['supported'];
}

/** @return bool|null */
function wp_cache_flush() {
	return wp_mcp_test_flush( false );
}

/** @return bool|null */
function wp_cache_flush_runtime() {
	return wp_mcp_test_flush( true );
}

/**
 * @param bool $runtime Whether only local entries should be cleared.
 * @return bool|null
 */
function wp_mcp_test_flush( bool $runtime ) {
	global $wp_mcp_test_configuration, $wp_mcp_test_state;
	++$wp_mcp_test_state[ $runtime ? 'runtime_calls' : 'full_calls' ];
	if ( 'throw' === $wp_mcp_test_configuration['result'] ) {
		throw new \RuntimeException( 'Cache backend test exception.' );
	}
	if ( 'false' === $wp_mcp_test_configuration['result'] ) {
		return false;
	}
	$wp_mcp_test_state['local_entry'] = false;
	if ( ! $runtime ) {
		$wp_mcp_test_state['shared_entry'] = false;
	}
	return 'null' === $wp_mcp_test_configuration['result'] ? null : true;
}

ob_start();
// phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- Test library path supplied by the parent PHPUnit process.
require_once $argv[1] . '/includes/functions.php';
require dirname( __DIR__ ) . '/bootstrap.php';
ob_end_clean();

$wp_mcp_test_configuration = json_decode( $argv[2], true );
$wp_mcp_test_state         = array(
	'full_calls'    => 0,
	'runtime_calls' => 0,
	'executions'    => 0,
	'shared_entry'  => true,
	'local_entry'   => true,
);
if ( $wp_mcp_test_configuration['disabled'] ) {
	add_filter( 'mcp_adapter_stdio_flush_runtime_cache', '__return_false' );
}
$wp_mcp_test_tool = McpTool::fromArray(
	array(
		'name'        => 'cache-probe',
		'inputSchema' => array( 'type' => 'object' ),
		'handler'     => static function (): array {
			global $wp_mcp_test_state;
			++$wp_mcp_test_state['executions'];
			return array( 'ok' => true );
		},
		'permission'  => '__return_true',
	)
);
if ( ! $wp_mcp_test_tool instanceof McpTool ) {
	throw new \RuntimeException( 'Unable to construct cache test tool.' );
}
$wp_mcp_test_server = new McpServer(
	'cache-test',
	'mcp/v1',
	'/cache',
	'Cache test',
	'Cache test',
	'1.0',
	array(),
	DummyErrorHandler::class,
	DummyObservabilityHandler::class,
	array( $wp_mcp_test_tool )
);
( new StdioServerBridge( $wp_mcp_test_server ) )->serve();
// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fwrite,WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Separate test metadata from the protocol stream.
fwrite( STDERR, 'CACHE_TEST_STATE:' . wp_json_encode( $wp_mcp_test_state ) . "\n" );
