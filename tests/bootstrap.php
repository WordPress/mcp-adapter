<?php
/**
 * PHPUnit bootstrap file for mcp-adapter (WordPress-style with unit fallback).
 */

// Load Composer autoloader for the library code under test.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Fast unit mode avoids loading the full WP test suite and provides shims.
if ( getenv( 'MCP_ADAPTER_FAST_UNIT' ) ) {
    // Minimal global state for tests expecting logged-in checks.
    if ( ! function_exists( 'wp_set_current_user' ) ) {
        function wp_set_current_user( $user_id ) {
            $GLOBALS['__mcp_current_user_id'] = (int) $user_id;
        }
    }
    if ( ! function_exists( 'get_current_user_id' ) ) {
        function get_current_user_id() {
            return isset( $GLOBALS['__mcp_current_user_id'] ) ? (int) $GLOBALS['__mcp_current_user_id'] : 0;
        }
    }
    if ( ! function_exists( 'get_current_blog_id' ) ) {
        function get_current_blog_id() {
            return 1;
        }
    }
    if ( ! function_exists( 'is_user_logged_in' ) ) {
        function is_user_logged_in() {
            return get_current_user_id() > 0;
        }
    }

    // Actions & filters minimal implementation.
    if ( ! function_exists( 'add_action' ) ) {
        function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
            $GLOBALS['__mcp_hooks']['actions'][ $hook ][ $priority ][] = array( $callback, $accepted_args );
        }
    }
    if ( ! function_exists( 'do_action' ) ) {
        function do_action( $hook, ...$args ) {
            $GLOBALS['__mcp_did_actions'][ $hook ] = ( $GLOBALS['__mcp_did_actions'][ $hook ] ?? 0 ) + 1;
            if ( empty( $GLOBALS['__mcp_hooks']['actions'][ $hook ] ) ) {
                return;
            }
            foreach ( $GLOBALS['__mcp_hooks']['actions'][ $hook ] as $callbacks ) {
                foreach ( $callbacks as $callback ) {
                    call_user_func_array( $callback[0], array_slice( $args, 0, (int) $callback[1] ) );
                }
            }
        }
    }
    if ( ! function_exists( 'did_action' ) ) {
        function did_action( $hook ) {
            return (int) ( $GLOBALS['__mcp_did_actions'][ $hook ] ?? 0 );
        }
    }
    if ( ! function_exists( 'add_filter' ) ) {
        function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
            $GLOBALS['__mcp_hooks']['filters'][ $hook ][ $priority ][] = array( $callback, $accepted_args );
        }
    }
    if ( ! function_exists( '__return_false' ) ) {
        function __return_false() { return false; }
    }
    if ( ! function_exists( '__return_true' ) ) {
        function __return_true() { return true; }
    }
    if ( ! function_exists( 'apply_filters' ) ) {
        function apply_filters( $hook, $value, ...$args ) {
            if ( empty( $GLOBALS['__mcp_hooks']['filters'][ $hook ] ) ) {
                return $value;
            }
            foreach ( $GLOBALS['__mcp_hooks']['filters'][ $hook ] as $callbacks ) {
                foreach ( $callbacks as $callback ) {
                    $value = call_user_func_array( $callback[0], array_merge( array( $value ), array_slice( $args, 0, (int) $callback[1] - 1 ) ) );
                }
            }
            return $value;
        }
    }
    if ( ! function_exists( 'remove_filter' ) ) {
        function remove_filter( $hook, $callback, $priority = 10 ) {
            if ( empty( $GLOBALS['__mcp_hooks']['filters'][ $hook ][ $priority ] ) ) {
                return false;
            }
            foreach ( $GLOBALS['__mcp_hooks']['filters'][ $hook ][ $priority ] as $i => $cb ) {
                if ( $cb[0] === $callback ) {
                    unset( $GLOBALS['__mcp_hooks']['filters'][ $hook ][ $priority ][ $i ] );
                    return true;
                }
            }
            return false;
        }
    }

    // i18n and escaping shims.
    if ( ! function_exists( '__' ) ) {
        function __( $text, $domain = null ) { // phpcs:ignore
            return (string) $text;
        }
    }
    if ( ! function_exists( 'esc_html__' ) ) {
        function esc_html__( $text, $domain = null ) { // phpcs:ignore
            return (string) $text;
        }
    }
    if ( ! function_exists( 'esc_html' ) ) {
        function esc_html( $text ) {
            return (string) $text;
        }
    }
    if ( ! function_exists( 'wp_json_encode' ) ) {
        function wp_json_encode( $data ) {
            return json_encode( $data );
        }
    }
    if ( ! function_exists( '_doing_it_wrong' ) ) {
        function _doing_it_wrong( $function, $message, $version ) { // phpcs:ignore
            // no-op in unit mode
        }
    }

    // REST validation and error shims used by abilities API.
    if ( ! class_exists( 'WP_Error' ) ) {
        class WP_Error {
            public function get_error_message() { return 'error'; }
        }
    }
    if ( ! function_exists( 'is_wp_error' ) ) {
        function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
    }
    if ( ! function_exists( 'rest_validate_value_from_schema' ) ) {
        function rest_validate_value_from_schema( $value, $schema ) { return true; }
    }

    // Fire abilities init so tests can register abilities.
    do_action( 'abilities_api_init' );

    return;
}

// Otherwise, load the full WordPress test suite bootstrap.
$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
    $_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

$_phpunit_polyfills_path = getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' );
if ( false !== $_phpunit_polyfills_path ) {
    define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $_phpunit_polyfills_path );
}

if ( ! file_exists( "{$_tests_dir}/includes/functions.php" ) ) {
    echo "Could not find {$_tests_dir}/includes/functions.php, have you run bin/install-wp-tests.sh ?" . PHP_EOL;
    exit( 1 );
}

require_once "{$_tests_dir}/includes/functions.php";

function _mcp_adapter_tests_bootstrap() {
    // For library tests, we don't need to load a plugin file.
}
tests_add_filter( 'muplugins_loaded', '_mcp_adapter_tests_bootstrap' );

require "{$_tests_dir}/includes/bootstrap.php";
