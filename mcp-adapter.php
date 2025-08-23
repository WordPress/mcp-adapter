<?php
/**
 * Plugin Name: WordPress MCP Adapter
 * Plugin URI: https://github.com/wordpress/mcp-adapter
 * Description: Adapter for abilities API, letting the abilities be used as MCP tools, resources or prompts. Provides a Model Context Protocol server for WordPress.
 * Version: 0.1.0
 * Author: WordPress Team
 *
 * @package McpAdapterRegistry
 */

declare( strict_types=1 );
use WP\MCP\McpAdapter;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants
define( 'MCP_ADAPTER_VERSION', '0.1.0' );
define( 'MCP_ADAPTER_PLUGIN_FILE', __FILE__ );
define( 'MCP_ADAPTER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MCP_ADAPTER_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MCP_ADAPTER_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Initialize the MCP Adapter plugin.
 *
 * This function sets up the autoloader and initializes the MCP adapter
 * when WordPress is ready.
 *
 * @return void
 */
function mcp_adapter_init(): void {
	// Load Composer autoloader
	require_once  __DIR__ . '/vendor/autoload.php';

    $mcp_adapter = new McpAdapter();
}

// Initialize the plugin when WordPress is ready
add_action( 'init', 'mcp_adapter_init', 10 );
