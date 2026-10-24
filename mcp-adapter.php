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
 * Version:           0.7.1
 * Requires at least: 6.9
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

// Another copy's entry point may already have declared the loader, when a plugin that loads first
// includes its bundled copy's mcp-adapter.php. Requiring ours would then be a fatal "Cannot declare
// class" on every request, so stand down and report which copy is running.
if ( class_exists( Autoloader::class, false ) ) {
	$wp_mcp_running_loader = ( new \ReflectionClass( Autoloader::class ) )->getFileName();
	if ( realpath( (string) $wp_mcp_running_loader ) !== realpath( __DIR__ . '/includes/Autoloader.php' ) ) {
		( require __DIR__ . '/includes/loaded-elsewhere-notice.php' )( (string) $wp_mcp_running_loader );
		unset( $wp_mcp_running_loader );
		return;
	}
	unset( $wp_mcp_running_loader );
}

require_once __DIR__ . '/includes/Autoloader.php';

// If autoloader failed, we cannot proceed.
if ( ! Autoloader::autoload() ) {
	return;
}

// Load the plugin.
if ( class_exists( Plugin::class ) ) {
	Plugin::instance();
}
