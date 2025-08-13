<?php
/**
 * Helper trait for MCP observability handlers providing shared utility methods.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Infrastructure\Observability;

use Throwable;

/**
 * Trait McpObservabilityHelperTrait
 *
 * Provides shared utility methods for observability handlers including
 * tag management, metric formatting, and sanitization functionality.
 */
trait McpObservabilityHelperTrait {

	/**
	 * Get default tags that should be included with all metrics.
	 *
	 * @return array
	 */
	public static function get_default_tags(): array {
		return array(
			'site_id'   => function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 0,
			'user_id'   => function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0,
			'timestamp' => time(),
		);
	}

	/**
	 * Sanitize tags to ensure they are safe for logging and don't contain sensitive data.
	 *
	 * @param array $tags The tags to sanitize.
	 *
	 * @return array
	 */
	public static function sanitize_tags( array $tags ): array {
		$sanitized = array();

		foreach ( $tags as $key => $value ) {
			// Convert to string and limit length to prevent log bloat.
			$key   = substr( (string) $key, 0, 50 );
			$value = substr( (string) $value, 0, 100 );

			// Remove potentially sensitive information patterns.
			$value = preg_replace( '/\b(?:password|token|key|secret|auth)\b/i', '[REDACTED]', $value );

			$sanitized[ $key ] = $value;
		}

		return $sanitized;
	}

	/**
	 * Format metric name to follow consistent naming conventions.
	 *
	 * @param string $metric The raw metric name.
	 *
	 * @return string
	 */
	public static function format_metric_name( string $metric ): string {
		// Ensure metric starts with 'mcp.' prefix.
		if ( ! str_starts_with( $metric, 'mcp.' ) ) {
			$metric = 'mcp.' . $metric;
		}

		// Convert to lowercase and replace spaces/special chars with dots.
		$metric = strtolower( $metric );
		$metric = preg_replace( '/[^a-z0-9_\.]/', '.', $metric );
		$metric = preg_replace( '/\.+/', '.', $metric ); // Remove duplicate dots.
		$metric = trim( $metric, '.' ); // Remove leading/trailing dots.

		return $metric;
	}

	/**
	 * Merge default tags with provided tags.
	 *
	 * @param array $tags The user-provided tags.
	 *
	 * @return array
	 */
	public static function merge_tags( array $tags ): array {
		$default_tags = self::get_default_tags();
		$merged_tags  = array_merge( $default_tags, $tags );

		return self::sanitize_tags( $merged_tags );
	}

	/**
	 * Record an error event with standardized error categorization.
	 *
	 * @param string     $base_event The base event name (e.g., 'mcp.tool.execution').
	 * @param Throwable $exception The exception that occurred.
	 * @param array      $additional_tags Additional context tags.
	 *
	 * @return void
	 */
	public static function record_error_event( string $base_event, Throwable $exception, array $additional_tags = array() ): void {
		$error_tags = array_merge(
			array(
				'error_type'         => get_class( $exception ),
				'error_category'     => self::categorize_error( $exception ),
				'error_message_hash' => substr( md5( $exception->getMessage() ), 0, 8 ), // For grouping similar errors.
			),
			$additional_tags
		);

		static::record_event( $base_event . '_failed', $error_tags );
	}

	/**
	 * Categorize an exception into a general error category.
	 *
	 * @param Throwable $exception The exception to categorize.
	 *
	 * @return string
	 */
	public static function categorize_error( Throwable $exception ): string {
		return match ( get_class( $exception ) ) {
			'InvalidArgumentException' => 'validation',
			'RuntimeException' => 'execution',
			'LogicException' => 'logic',
			'Error' => 'system',
			'TypeError' => 'type',
			'ArgumentCountError' => 'arguments',
			default => 'unknown'
		};
	}
}
