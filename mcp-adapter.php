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

/*
 * Report copies of the adapter that live outside this plugin.
 *
 * This cannot live in `McpAdapter`, because when another copy wins the autoload
 * race, `McpAdapter` *is* that copy: a release older than this check has no way
 * to report its own takeover. This file, by contrast, always runs while the
 * plugin is active, no matter which copy the autoloader resolved, so it is the
 * one place the check can be relied on.
 *
 * For the same reason it must not call into the loaded classes — the method it
 * would call may not exist in whichever version won, which would turn a notice
 * into a fatal on exactly the sites being warned. Everything here is
 * self-contained.
 *
 * It is a closure rather than a named function for the same reason the constants
 * above are inline: a named function here would fatal on redeclaration before
 * any guard could run.
 */
add_action(
	'init',
	static function (): void {
		// Resolve every path the same way, since symlinked plugin directories are
		// normal in local and Bedrock-style setups and would otherwise look foreign.
		$resolve = static function ( string $path ): string {
			$resolved = realpath( $path );

			return wp_normalize_path( false === $resolved ? $path : $resolved );
		};

		$plugin_dir = trailingslashit( $resolve( WP_MCP_DIR ) );

		/*
		 * Case 1: another copy is the one actually executing.
		 */
		if ( class_exists( Core\McpAdapter::class, false ) ) {
			$reflection = new \ReflectionClass( Core\McpAdapter::class );
			$class_file = $reflection->getFileName();

			if ( is_string( $class_file ) && '' !== $class_file ) {
				$class_file = $resolve( $class_file );

				if ( 0 !== strpos( $class_file, $plugin_dir ) ) {
					$loaded_version = $reflection->getConstant( 'VERSION' );

					_doing_it_wrong(
						'WP\MCP\Core\McpAdapter',
						sprintf(
							/* translators: 1: Version of the copy that loaded, 2: Absolute path to that copy, 3: Version of the installed plugin. */
							esc_html__( 'MCP Adapter %1$s was loaded from %2$s, so it is running instead of the MCP Adapter plugin (version %3$s) installed on this site. Whichever plugin ships that copy should depend on the MCP Adapter plugin with the "Requires Plugins" header rather than bundling its own, since the bundled copy replaces the installed plugin site-wide.', 'mcp-adapter' ),
							esc_html( is_string( $loaded_version ) ? $loaded_version : __( '(unknown version)', 'mcp-adapter' ) ),
							esc_html( $class_file ),
							esc_html( WP_MCP_VERSION )
						),
						'0.6.2'
					);

					return;
				}
			}
		}

		/*
		 * Case 2: this plugin's copy is running, but another one is installed and
		 * could win instead.
		 *
		 * Which copy wins is decided by autoloader registration order, which
		 * follows plugin load order — not by anything either plugin controls. So a
		 * site can look healthy for months and then quietly switch to a bundled
		 * copy because a plugin was deactivated and reactivated. Reporting only the
		 * takeover would mean staying silent right up until it happens.
		 *
		 * Registered Composer autoloaders can be asked where they *would* find a
		 * class without loading it, which finds dormant copies without touching the
		 * filesystem. `findFile()` is duck-typed rather than checked against
		 * `Composer\Autoload\ClassLoader`, so scoped and prefixed builds of Composer
		 * are covered too.
		 */
		$autoloaders = spl_autoload_functions();

		if ( ! is_array( $autoloaders ) ) {
			return;
		}

		$dormant = array();

		foreach ( $autoloaders as $callback ) {
			if ( ! is_array( $callback ) || ! is_object( $callback[0] ) ) {
				continue;
			}

			$find_file = array( $callback[0], 'findFile' );

			if ( ! is_callable( $find_file ) ) {
				continue;
			}

			$candidate = $find_file( 'WP\MCP\Core\McpAdapter' );

			if ( ! is_string( $candidate ) || '' === $candidate ) {
				continue;
			}

			$candidate = $resolve( $candidate );

			if ( 0 === strpos( $candidate, $plugin_dir ) ) {
				continue;
			}

			$dormant[ $candidate ] = true;
		}

		if ( array() === $dormant ) {
			return;
		}

		_doing_it_wrong(
			'WP\MCP\Core\McpAdapter',
			sprintf(
				/* translators: 1: Comma-separated absolute paths to the other copies, 2: Version of the installed plugin. */
				esc_html__( 'Another copy of MCP Adapter is installed at %1$s. Only one copy can run per request, and which one wins is decided by plugin load order, so this site may start running that copy instead of the MCP Adapter plugin (version %2$s) without anything changing. Whichever plugin ships it should depend on the MCP Adapter plugin with the "Requires Plugins" header rather than bundling its own.', 'mcp-adapter' ),
				esc_html( implode( ', ', array_keys( $dormant ) ) ),
				esc_html( WP_MCP_VERSION )
			),
			'0.6.2'
		);
	},
	0
);
