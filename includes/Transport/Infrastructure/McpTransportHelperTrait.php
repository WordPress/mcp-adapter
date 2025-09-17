<?php
/**
 * Helper trait for MCP transport implementations providing shared utility methods.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Transport\Infrastructure;

/**
 * Trait McpTransportHelperTrait
 *
 * Provides shared utility methods for transport implementations including
 * naming conventions, error creation, and common operations.
 */
trait McpTransportHelperTrait {

	/**
	 * Get a normalized transport name for tagging purposes.
	 *
	 * Extracts the transport name from the class name for use in observability metrics.
	 *
	 * @return string
	 */
	protected function get_transport_name(): string {
		// Get the class name without namespace.
		$class_name = substr( (string) strrchr( static::class, '\\' ), 1 );

		// Remove common suffixes and convert to lowercase.
		$transport_name = strtolower(
			str_replace( array( 'McpTransport', 'Transport' ), '', $class_name )
		);

		// Fallback to 'unknown' if extraction fails.
		return ! empty( $transport_name ) ? $transport_name : 'unknown';
	}
}
