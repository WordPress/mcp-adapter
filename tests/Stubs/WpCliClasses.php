<?php
/**
 * WP-CLI core classes for testing.
 *
 * Contains the essential WP-CLI classes extracted from php-stubs/wp-cli-stubs
 * for use in testing environments.
 *
 * @package mcp-adapter
 */

// WP-CLI core classes in global namespace
if ( ! class_exists( 'WP_CLI_Command' ) ) {
	/**
	 * Base class for WP-CLI commands
	 *
	 * @package wp-cli
	 */
	abstract class WP_CLI_Command {
		public function __construct() {
		}
	}
}

if ( ! class_exists( 'WP_CLI' ) ) {
	/**
	 * Various utilities for WP-CLI commands.
	 */
	class WP_CLI {
		private static $logger;
		private static $hooks = array();
		private static $hooks_passed = array();
		private static $capture_exit = false;
		private static $deferred_additions = array();

		/**
		 * Display informational message without prefix, and ignore --quiet.
		 *
		 * @param string $message Message to display to the user.
		 */
		public static function line( $message = '' ) {
			// Stub implementation for testing
		}

	/**
	 * Display error message prefixed with "Error:" and exit with error code.
	 *
	 * @param string $message Message to display to the user.
	 * @param bool   $exit    Whether to exit or return.
	 */
	public static function error( $message, $exit = true ) {
		// For testing, throw exception instead of exiting
		throw new \Exception( 'WP_CLI Error: ' . $message );
	}

	/**
	 * Display debug message when in debug mode.
	 *
	 * @param string $message Message to display to the user.
	 */
	public static function debug( $message ) {
		// Stub implementation for testing
	}
}
}
