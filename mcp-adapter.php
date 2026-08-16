<?php
/**
 * WordPress MCP Adapter
 *
 * @package     mcp-adapter
 * @author      WordPress.org Contributors
 * @copyright   2025 Plugin Contributors
 * @license     GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       MCP Adapter
 * Plugin URI:        https://github.com/WordPress/mcp-adapter
 * Description:       Adapter for Abilities API, letting the abilities to be used as MCP tools, resources or prompts.
 * Version:           0.6.1
 * Requires at least: 6.9
 * Tested up to:      7.0
 * Requires PHP:      7.4
 * Author:            WordPress.org Contributors
 * Author URI:        https://github.com/WordPress/mcp-adapter/graphs/contributors
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain:       mcp-adapter
 */

declare (strict_types = 1);

namespace WP\MCP;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit();

/*
 * Bail if another copy of MCP Adapter has already bootstrapped.
 *
 * Composer publishes `wordpress/mcp-adapter` as a `wordpress-plugin`, so a
 * project that requires it as a library alongside `composer/installers` gets it
 * relocated to `wp-content/plugins/mcp-adapter/`. If that copy and the
 * canonical plugin are both active, WordPress loads this file twice from two
 * different paths.
 *
 * The constants below are defined inline rather than from a named function on
 * purpose. PHP binds unconditional top-level function declarations while the
 * file is compiled, so a `function constants() {}` here would fatal with
 * "Cannot redeclare" before this guard ever got the chance to run.
 */
if ( defined( 'WP_MCP_DIR' ) ) {
	return;
}

/**
 * Shortcut constant to the path of this file.
 */
define( 'WP_MCP_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Version of the plugin.
 */
define( 'WP_MCP_VERSION', '0.6.1' );

// Another copy loaded as a Composer dependency may have declared this already.
if ( ! class_exists( Autoloader::class, false ) ) {
	require_once __DIR__ . '/includes/Autoloader.php';
}

// If autoloader failed, we cannot proceed.
if ( ! Autoloader::autoload() ) {
	return;
}

// Load the plugin.
if ( class_exists( Plugin::class ) ) {
	Plugin::instance();
}
