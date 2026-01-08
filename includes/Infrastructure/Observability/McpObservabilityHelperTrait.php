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
	 * Patterns that indicate sensitive data in keys or values.
	 *
	 * These patterns are designed to match both camelCase and snake_case variants:
	 * - apiKey, api_key, API_KEY
	 * - authToken, auth_token, AUTH_TOKEN
	 * - secretKey, secret_key, SECRET_KEY
	 *
	 * @var string[]
	 */
	private static array $sensitive_patterns = array(
		'password',
		'passwd',
		'pwd',
		'secret',
		'token',
		'bearer',
		'credential',
		'private',
		'apikey',
		'api_key',
		'authtoken',
		'auth_token',
		'accesstoken',
		'access_token',
		'refreshtoken',
		'refresh_token',
		'clientsecret',
		'client_secret',
		'privatekey',
		'private_key',
		'secretkey',
		'secret_key',
		'authorization',
		'authenticate',
		'encryption',
	);

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
			// Convert key to string and limit length to prevent log bloat.
			$key = substr( (string) $key, 0, 64 );

			// Check if the key itself indicates sensitive data.
			if ( self::is_sensitive_key( $key ) ) {
				$sanitized[ $key ] = '[REDACTED]';
				continue;
			}

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

			// Limit value length to prevent log bloat.
			if ( strlen( $value ) > 1024 ) {
				$value = substr( $value, 0, 1024 ) . '...[truncated]';
			}

			// Remove potentially sensitive information patterns from values.
			$value = self::redact_sensitive_values( $value );

			$sanitized[ $key ] = $value;
		}

		return $sanitized;
	}

	/**
	 * Check if a key name indicates sensitive data.
	 *
	 * Matches patterns in camelCase, snake_case, and SCREAMING_CASE.
	 *
	 * @param string $key The key name to check.
	 *
	 * @return bool True if the key appears to contain sensitive data.
	 */
	public static function is_sensitive_key( string $key ): bool {
		// Normalize: lowercase and remove underscores/hyphens for pattern matching.
		$normalized = strtolower( str_replace( array( '_', '-' ), '', $key ) );

		foreach ( self::$sensitive_patterns as $pattern ) {
			// Remove underscores from pattern for normalized comparison.
			$normalized_pattern = str_replace( '_', '', $pattern );

			if ( str_contains( $normalized, $normalized_pattern ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Redact sensitive values from a string.
	 *
	 * Uses a more comprehensive pattern that catches compound words.
	 *
	 * @param string $value The value to redact.
	 *
	 * @return string The value with sensitive patterns redacted.
	 */
	public static function redact_sensitive_values( string $value ): string {
		// Build a regex pattern that matches sensitive words as substrings.
		// This catches camelCase (apiKey), snake_case (api_key), and standalone words.
		$pattern = '/(?:' . implode( '|', array_map( 'preg_quote', self::$sensitive_patterns ) ) . ')/i';

		return (string) preg_replace( $pattern, '[REDACTED]', $value );
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
