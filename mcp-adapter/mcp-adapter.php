<?php
/**
 * Plugin Name: MCP Adapter
 * Description: WordPress MCP Adapter module.
 * Version: 1.0.0
 */

if ( ! defined( 'MCP_VERSION' ) ) {
	define( 'MCP_VERSION', '1.0.0' );
}

// Load plugin classes or bootstrap logic
require_once __DIR__ . '/src/Loader.php';

\WP\MCP\Loader::init();
