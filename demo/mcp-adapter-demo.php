<?php
/**
 * MCP Adapter Demo Plugin
 *
 * @package     mcp-adapter-demo
 * @author      WordPress.org Contributors
 * @copyright   2025 Plugin Contributors
 * @license     GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       MCP Adapter Demo
 * Plugin URI:        https://github.com/WordPress/mcp-adapter
 * Description:       Demo plugin showcasing MCP Adapter functionality with admin interface and examples.
 * Requires at least: 6.8
 * Requires Plugins:  mcp-adapter
 * Version:           0.1.0
 * Requires PHP:      7.4
 * Author:            WordPress.org Contributors
 * Author URI:        https://github.com/WordPress/mcp-adapter/graphs/contributors
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain:       mcp-adapter-demo
 */

declare(strict_types=1);

namespace WP\MCP\Demo;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit();

// Ensure MCP Adapter core is available - defer loading if not ready
if ( ! class_exists( '\WP\MCP\Core\McpAdapter' ) ) {
	// Try to load after plugins_loaded hook
	add_action( 'plugins_loaded', function() {
		if ( ! class_exists( '\WP\MCP\Core\McpAdapter' ) ) {
			add_action( 'admin_notices', function() {
				echo '<div class="notice notice-error"><p>MCP Adapter Demo requires the MCP Adapter plugin to be installed and activated.</p></div>';
			});
			return;
		}
		
		// Initialize demo plugin now that core is available
		require_once __DIR__ . '/includes/Autoloader.php';
		if ( \WP\MCP\Demo\Autoloader::autoload() ) {
			\WP\MCP\Demo\DemoPlugin::instance();
		}
	}, 20 ); // Load after core adapter
	return;
}

/**
 * Define the demo plugin constants.
 */
function constants(): void {
	define( 'WP_MCP_DEMO_DIR', plugin_dir_path( __FILE__ ) );
	define( 'WP_MCP_DEMO_VERSION', '0.1.0' );
}

constants();

require_once __DIR__ . '/includes/Autoloader.php';

// Initialize autoloader
if ( ! \WP\MCP\Demo\Autoloader::autoload() ) {
	return;
}

// Initialize the demo plugin
\WP\MCP\Demo\DemoPlugin::instance();