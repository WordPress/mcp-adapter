<?php
/**
 * Boots WordPress in a fresh process for the `wordpress/abilities` consumption tests.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

use WP\MCP\Core\McpAdapter;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\McpSchema\Schemas;

$wp_abilities_test_configuration = json_decode( $argv[2], true );
$wp_abilities_test_notices       = array();

// The test bootstrap loads the Composer autoloader. The plugin's Jetpack Autoloader
// would map WP_CLI to a partial test stub.
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Test fixture constant.
define( 'WP_MCP_AUTOLOAD', false );

// phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- Test library path supplied by the parent PHPUnit process.
require_once $argv[1] . '/includes/functions.php';

/*
 * The opt-out filter must be in place before the adapter plugin loads: the filter is
 * consulted inside `McpAdapter::instance()` while `muplugins_loaded` fires, and the
 * stock bootstrap activates the plugin at priority 10. Priority 5 wins that race.
 */
tests_add_filter(
	'muplugins_loaded',
	static function () use ( $wp_abilities_test_configuration ): void {
		if ( $wp_abilities_test_configuration['opt_in'] ) {
			return;
		}

		add_filter( 'mcp_adapter_register_wordpress_abilities', '__return_false' );
	},
	5
);

tests_add_filter(
	'mcp_adapter_default_server_config',
	static function ( $config ) {
		$config['error_handler'] = DummyErrorHandler::class;
		return $config;
	}
);

tests_add_filter(
	'doing_it_wrong_run',
	static function ( $function_name ) use ( &$wp_abilities_test_notices ): void {
		if ( ! in_array( $function_name, array( 'wp_register_ability', 'wp_register_ability_category' ), true ) ) {
			return;
		}

		$wp_abilities_test_notices[] = $function_name;
	}
);

ob_start();
require dirname( __DIR__ ) . '/bootstrap.php';

rest_get_server();

$adapter   = McpAdapter::instance();
$server    = $adapter->get_server( 'mcp-adapter-default-server' );
$tool_names = null !== $server
	? array_keys( $server->get_tools( $server->get_schemas()->forVersion( Schemas::V2025_11_25 ) ) )
	: array();

$wp_abilities_test_result = array(
	'abilities'       => array_keys( WP_Abilities_Registry::get_instance()->get_all_registered() ),
	'categories'      => array_keys( WP_Ability_Categories_Registry::get_instance()->get_all_registered() ),
	'server_created'  => null !== $server,
	'default_tools'   => $tool_names,
	'notices'         => $wp_abilities_test_notices,
	'errors'          => DummyErrorHandler::$logs,
	'bootstrap_output' => ob_get_clean(),
);

echo wp_json_encode( $wp_abilities_test_result );