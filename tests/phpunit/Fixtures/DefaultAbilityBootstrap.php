<?php
/**
 * Boots WordPress in a fresh process for default ability registration lifecycle tests.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

use WP\MCP\Core\McpAdapter;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;

$wp_mcp_test_configuration = json_decode( $argv[2], true );
$wp_mcp_test_notices       = array();
$wp_mcp_test_early_names   = array();

if ( 'cli' === $wp_mcp_test_configuration['context'] ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Select the real adapter CLI initialization path.
	define( 'WP_CLI', true );
}

// The test bootstrap loads the Composer autoloader. The plugin's Jetpack Autoloader would map WP_CLI to a partial test stub.
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Plugin constant.
define( 'WP_MCP_AUTOLOAD', false );

// phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- Test library path supplied by the parent PHPUnit process.
require_once $argv[1] . '/includes/functions.php';

// A theme can install this filter after the adapter plugin has loaded.
tests_add_filter(
	'after_setup_theme',
	static function () use ( $wp_mcp_test_configuration ) {
		if ( $wp_mcp_test_configuration['enabled'] ) {
			return;
		}

		add_filter( 'mcp_adapter_create_default_server', '__return_false' );
	}
);

// Another plugin initializes the Abilities API registry before the adapter initializes.
tests_add_filter(
	'init',
	static function () use ( $wp_mcp_test_configuration, &$wp_mcp_test_early_names ) {
		if ( ! $wp_mcp_test_configuration['early_registry'] ) {
			return;
		}

		$wp_mcp_test_early_names = array_keys( WP_Abilities_Registry::get_instance()->get_all_registered() );
	},
	0
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
	static function ( $function_name, $message ) use ( &$wp_mcp_test_notices ) {
		if ( ! in_array( $function_name, array( 'wp_register_ability', 'wp_register_ability_category' ), true ) ) {
			return;
		}

		$wp_mcp_test_notices[] = $message;
	},
	10,
	2
);

ob_start();
require dirname( __DIR__ ) . '/bootstrap.php';

if ( 'rest' === $wp_mcp_test_configuration['context'] ) {
	rest_get_server();
}

$wp_mcp_test_result = array(
	'abilities'        => array_keys( WP_Abilities_Registry::get_instance()->get_all_registered() ),
	'categories'       => array_keys( WP_Ability_Categories_Registry::get_instance()->get_all_registered() ),
	'early_abilities'  => $wp_mcp_test_early_names,
	'server_created'   => null !== McpAdapter::instance()->get_server( 'mcp-adapter-default-server' ),
	'notices'          => $wp_mcp_test_notices,
	'errors'           => DummyErrorHandler::$logs,
	'bootstrap_output' => ob_get_clean(),
);

echo wp_json_encode( $wp_mcp_test_result );
