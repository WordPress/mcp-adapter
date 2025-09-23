<?php
/**
 * WP-CLI utility functions for testing.
 *
 * Contains WP-CLI utility functions in their proper namespace,
 * extracted from php-stubs/wp-cli-stubs for use in testing environments.
 *
 * @package mcp-adapter
 */

namespace WP_CLI\Utils;

if ( ! function_exists( __NAMESPACE__ . '\format_items' ) ) {
	/**
	 * Render a collection of items as an ASCII table, JSON, CSV, YAML, etc.
	 *
	 * @param string       $format Format to use: 'table', 'json', 'csv', 'yaml', 'ids', 'count'.
	 * @param array<mixed> $items  An array of items to output.
	 * @param array<string>|string $fields Named fields for each item of data. Can be array or comma-separated list.
	 */
	function format_items( $format, $items, $fields ) {
		// Stub implementation for testing
	}
}
