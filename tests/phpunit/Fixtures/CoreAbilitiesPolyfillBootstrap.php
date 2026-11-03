<?php
/**
 * Boots WordPress in a fresh process for core abilities polyfill lifecycle tests.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

use WP\MCP\Compat\CoreAbilitiesPolyfill;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;

$wp_mcp_test_configuration = json_decode( $argv[2], true );
$wp_mcp_test_notices       = array();
$wp_mcp_test_core_provides = false;

// The test bootstrap loads the Composer autoloader. The plugin's Jetpack Autoloader would map WP_CLI to a partial test stub.
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Plugin constant.
define( 'WP_MCP_AUTOLOAD', false );

// phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- Test library path supplied by the parent PHPUnit process.
require_once $argv[1] . '/includes/functions.php';

// Record whether this WordPress ships the core ability classes, before the plugin loads (priority 10).
tests_add_filter(
	'muplugins_loaded',
	static function () use ( &$wp_mcp_test_core_provides ) {
		$wp_mcp_test_core_provides = class_exists( 'WP_Abilities_Content' )
			&& class_exists( 'WP_Abilities_Settings' )
			&& class_exists( 'WP_Abilities_Users' );
	},
	0
);

// A theme can disable the polyfill after the adapter plugin has loaded.
tests_add_filter(
	'after_setup_theme',
	static function () use ( $wp_mcp_test_configuration ) {
		if ( $wp_mcp_test_configuration['enabled'] ) {
			return;
		}

		add_filter( 'mcp_adapter_register_core_abilities', '__return_false' );
	}
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
		$watched = array(
			'wp_register_ability',
			'wp_register_ability_category',
			'WP_Ability::__construct',
			'WP_Abilities_Registry::register',
			'WP_Abilities_Settings::get_exposed_settings',
		);
		if ( ! in_array( $function_name, $watched, true ) ) {
			return;
		}

		$wp_mcp_test_notices[] = $message;
	},
	10,
	2
);

ob_start();
require dirname( __DIR__ ) . '/bootstrap.php';

rest_get_server();

$wp_mcp_test_settings_get_denies_anonymous = null;
$wp_mcp_test_settings_get_admin_keys       = null;
$wp_mcp_test_users_query_ok                = null;

if ( wp_has_ability( 'core/settings-get' ) ) {
	wp_set_current_user( 0 );
	$wp_mcp_test_settings_get_denies_anonymous = is_wp_error( wp_get_ability( 'core/settings-get' )->execute( array() ) );

	wp_set_current_user( 1 );
	$wp_mcp_test_admin_result            = wp_get_ability( 'core/settings-get' )->execute( array() );
	$wp_mcp_test_settings_get_admin_keys = is_array( $wp_mcp_test_admin_result )
		? array_keys( $wp_mcp_test_admin_result )
		: $wp_mcp_test_admin_result->get_error_code();
}

if ( wp_has_ability( 'core/users-query' ) ) {
	wp_set_current_user( 1 );
	$wp_mcp_test_users_result    = wp_get_ability( 'core/users-query' )->execute( array() );
	$wp_mcp_test_users_query_ok  = is_array( $wp_mcp_test_users_result ) && ! empty( $wp_mcp_test_users_result['items'] );
}

$wp_mcp_test_result = array(
	'core_provides_abilities'       => $wp_mcp_test_core_provides,
	'abilities'                     => array_keys( WP_Abilities_Registry::get_instance()->get_all_registered() ),
	'categories'                    => array_keys( WP_Ability_Categories_Registry::get_instance()->get_all_registered() ),
	'abilities_post_types'          => array_values( get_post_types( array( 'show_in_abilities' => true ) ) ),
	'polyfill_hooked'               => false !== has_action( 'wp_abilities_api_init', array( CoreAbilitiesPolyfill::class, 'register_core_abilities' ) ),
	'settings_get_denies_anonymous' => $wp_mcp_test_settings_get_denies_anonymous,
	'settings_get_admin_keys'       => $wp_mcp_test_settings_get_admin_keys,
	'users_query_ok'                => $wp_mcp_test_users_query_ok,
	'notices'                       => $wp_mcp_test_notices,
	'errors'                        => DummyErrorHandler::$logs,
	'bootstrap_output'              => ob_get_clean(),
);

echo wp_json_encode( $wp_mcp_test_result );
