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
 * Requires at least: 6.8
 * Version:           0.1.0
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

/**
 * Define the plugin constants.
 */
function constants(): void {
	/**
	 * Shortcut constant to the path of this file.
	 */
	define( 'WP_MCP_DIR', plugin_dir_path( __FILE__ ) );

	/**
	 * Version of the plugin.
	 */
	define( 'WP_MCP_VERSION', '0.1.0' );
}

constants();
require_once __DIR__ . '/includes/Autoloader.php';

// If autoloader failed, we cannot proceed.
if ( ! Autoloader::autoload() ) {
	return;
}

// Load the plugin.
if ( class_exists( Plugin::class ) ) {
	Plugin::instance();
}
