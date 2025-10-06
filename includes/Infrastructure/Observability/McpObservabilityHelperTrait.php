<?php
/**
 * Helper trait for MCP observability handlers providing shared utility methods.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Infrastructure\Observability;

/**
 * Trait McpObservabilityHelperTrait
 *
 * Provides shared utility methods for observability handlers including
 * tag management, metric formatting, and sanitization functionality.
 */
trait McpObservabilityHelperTrait {
	/**
	 * Error categories keyed by throwable class name.
	 *
	 * @used-by ::categorize_error() method.
	*/
	private static array $error_categories = array(
		\ArgumentCountError::class       => 'arguments',
		\Error::class                    => 'system',
		\InvalidArgumentException::class => 'validation',
		\LogicException::class           => 'logic',
		\RuntimeException::class         => 'execution',
		\TypeError::class                => 'type',
	);

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
			$key = substr( (string) $key, 0, 64 );

			// Convert value to string, handling null specially.
			if ( null === $value ) {
				$value = '';
			} elseif ( is_scalar( $value ) ) {
				$value = (string) $value;
			} else {
				$value = wp_json_encode( $value );
				// wp_json_encode can return false on failure, ensure we have a string.
				if ( false === $value ) {
					$value = '';
				}
			}

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
		$metric = (string) preg_replace( '/[^a-z0-9_\.]/', '.', $metric );
		$metric = (string) preg_replace( '/\.+/', '.', $metric ); // Remove duplicate dots.
		// Remove leading/trailing dots.

		return trim( $metric, '.' );
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
	 * Categorize an exception into a general error category.
	 *
	 * @param \Throwable $exception The exception to categorize.
	 *
	 * @return string
	 */
	public static function categorize_error( \Throwable $exception ): string {
		return self::$error_categories[ get_class( $exception ) ] ?? 'unknown';
	}
}
