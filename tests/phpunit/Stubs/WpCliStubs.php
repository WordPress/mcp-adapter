<?php
/**
 * WP-CLI stubs initialization for testing.
 *
 * This file provides a clean interface to initialize all WP-CLI stubs
 * needed for testing without namespace conflicts.
 *
 * @package mcp-adapter
 */

declare(strict_types=1);

namespace WP\MCP\Tests\Stubs;

/**
 * WP-CLI stubs initialization class.
 *
 * Provides static methods to initialize WP-CLI stubs for testing.
 */
class WpCliStubs {

	/**
	 * Initialize WP-CLI stubs for testing.
	 *
	 * This method loads all necessary WP-CLI classes and functions
	 * for the test environment.
	 */
	public static function init(): void {
		// Load WP-CLI core classes (WP_CLI_Command, WP_CLI)
		require_once __DIR__ . '/WpCliClasses.php';

		// Load WP-CLI utility functions (WP_CLI\Utils\format_items)
		require_once __DIR__ . '/WpCliUtils.php';
	}
}
