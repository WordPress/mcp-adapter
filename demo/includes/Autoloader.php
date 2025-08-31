<?php
/**
 * Simple autoloader for demo plugin.
 *
 * @package MCP\Demo
 */

declare(strict_types=1);

namespace WP\MCP\Demo;

/**
 * Autoloader for the demo plugin.
 */
class Autoloader {

	/**
	 * Autoload classes.
	 *
	 * @return bool
	 */
	public static function autoload(): bool {
		return spl_autoload_register( array( __CLASS__, 'load_class' ) );
	}

	/**
	 * Load a class.
	 *
	 * @param string $class_name The class name to load.
	 * @return void
	 */
	public static function load_class( string $class_name ): void {
		if ( strpos( $class_name, 'WP\\MCP\\Demo\\' ) !== 0 ) {
			return;
		}

		$relative_class = substr( $class_name, strlen( 'WP\\MCP\\Demo\\' ) );
		$file_path = plugin_dir_path( __DIR__ ) . 'includes/' . str_replace( '\\', DIRECTORY_SEPARATOR, $relative_class ) . '.php';

		if ( file_exists( $file_path ) ) {
			require_once $file_path;
		}
	}
}