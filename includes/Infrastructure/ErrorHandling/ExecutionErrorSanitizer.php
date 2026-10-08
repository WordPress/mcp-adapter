<?php
/**
 * Sanitizer for WP_Error code/data exposed to MCP clients.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Infrastructure\ErrorHandling;

/**
 * Normalizes WP_Error code/data into a shape safe to serialize to MCP clients.
 *
 * Shared by any error path that converts a WP_Error into wire data
 * (tool execution errors, the execute-ability meta-tool), so the bounds
 * and defaults stay consistent regardless of which layer the error
 * originated in.
 *
 * @since 0.7.0
 */
final class ExecutionErrorSanitizer {

	/**
	 * Fallback code used when a WP_Error has no usable code.
	 *
	 * @var string
	 */
	public const DEFAULT_CODE = 'mcp_execution_failed';

	/**
	 * Maximum recursion depth for sanitize_data().
	 *
	 * @var int
	 */
	private const MAX_DEPTH = 4;

	/**
	 * Maximum number of keys kept per array level in sanitize_data().
	 *
	 * @var int
	 */
	private const MAX_ARRAY_KEYS = 50;

	/**
	 * Maximum string length kept in sanitize_data().
	 *
	 * @var int
	 */
	private const MAX_STRING_LENGTH = 5000;

	/**
	 * Normalize a WP_Error code into a safe machine-readable identifier.
	 *
	 * @param mixed $code The raw error code.
	 *
	 * @return string The sanitized error code. Never empty.
	 */
	public static function sanitize_code( $code ): string {
		if ( ! is_string( $code ) || '' === trim( $code ) ) {
			return self::DEFAULT_CODE;
		}

		$sanitized = sanitize_key( $code );

		return '' !== $sanitized ? $sanitized : self::DEFAULT_CODE;
	}

	/**
	 * Recursively strip error data down to JSON-safe values.
	 *
	 * Keeps null, booleans, numbers, strings, and arrays of those. Drops
	 * objects (except throwables, reduced to their class name) and
	 * resources, and bounds depth/breadth/length so a single failure can
	 * neither leak internals nor bloat the response.
	 *
	 * @param mixed $data  The raw error data.
	 * @param int   $depth Current recursion depth.
	 *
	 * @return mixed The sanitized error data. Null when nothing safe remains.
	 */
	public static function sanitize_data( $data, int $depth = 0 ) {
		if ( self::MAX_DEPTH < $depth ) {
			return null;
		}

		if ( null === $data || is_bool( $data ) || is_int( $data ) || is_float( $data ) ) {
			return $data;
		}

		if ( is_string( $data ) ) {
			return function_exists( 'mb_substr' )
				? mb_substr( $data, 0, self::MAX_STRING_LENGTH, 'UTF-8' )
				: substr( $data, 0, self::MAX_STRING_LENGTH );
		}

		if ( is_array( $data ) ) {
			$sanitized = array();

			foreach ( $data as $key => $value ) {
				if ( self::MAX_ARRAY_KEYS <= count( $sanitized ) ) {
					break;
				}

				// PHP arrays only carry int|string keys, both JSON-safe.
				$sanitized[ $key ] = self::sanitize_data( $value, $depth + 1 );
			}

			return $sanitized;
		}

		if ( $data instanceof \Throwable ) {
			return array( 'error_type' => get_class( $data ) );
		}

		return null;
	}
}
