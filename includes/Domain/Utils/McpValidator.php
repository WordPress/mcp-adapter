<?php

/**
 * MCP Validator utility class for validating MCP component data.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Domain\Utils;

use DateTime;
use DateTimeZone;

/**
 * Adapter-side checks that the schema package cannot express.
 *
 * The php-mcp-schema package is the contract for every emitted field; each
 * component is projected through it and rejected when it does not fit. This
 * class keeps only the checks that package cannot make and that the adapter
 * depends on: the tool/prompt name rule that feeds `McpNameSanitizer` and the
 * registry lookups, the resource URI scheme that `fold_uri_scheme` relies on,
 * and the `lastModified` form the official client requires.
 */
class McpValidator {

	/**
	 * URI scheme grammar per RFC 3986 §3.1 (unanchored regex fragment).
	 *
	 * Shared by URI validation and scheme folding so the two can never drift
	 * apart on what counts as a scheme.
	 *
	 * @since 0.6.0
	 *
	 * @var string
	 */
	private const URI_SCHEME_PATTERN = '[a-zA-Z][a-zA-Z0-9+.-]*';

	/**
	 * Validate an MCP component name.
	 *
	 * Validates that a name follows MCP naming conventions per MCP 2025-11-25 spec:
	 * - Must not be empty
	 * - Must not exceed the maximum length
	 * - Must only contain letters, numbers, hyphens (-), underscores (_), and dots (.)
	 *
	 * @param string $name The name to validate.
	 * @param int $max_length Maximum allowed length. Default is 128 per MCP spec.
	 *
	 * @return bool True if valid, false otherwise.
	 * @since 0.5.0
	 *
	 */
	public static function validate_name( string $name, int $max_length = 128 ): bool {
		// Names should not be empty (but allow "0" since it matches the regex).
		if ( '' === $name ) {
			return false;
		}

		// Check length constraints.
		if ( strlen( $name ) > $max_length ) {
			return false;
		}

		// Only allow letters, numbers, hyphens, underscores, and dots per MCP spec.
		return (bool) preg_match( '/^[a-zA-Z0-9_.-]+$/', $name );
	}

	/**
	 * Get validation errors for shared MCP annotations.
	 *
	 * The official JSON schema declares `lastModified` as a plain string, but the
	 * official TypeScript SDK client parses it with `z.string().datetime()` and
	 * rejects a whole `resources/list` result when any member carries a value
	 * without a time part or a time zone. This is the one adapter check kept for
	 * client compatibility rather than for the schema; every other annotation
	 * field is left to the schema package.
	 *
	 * @param array $annotations The annotations to validate.
	 *
	 * @return array Array of validation errors, empty if valid.
	 */
	public static function get_annotation_validation_errors( array $annotations ): array {
		if ( ! array_key_exists( 'lastModified', $annotations ) ) {
			return array();
		}

		$value = $annotations['lastModified'];
		if ( ! is_string( $value ) || ! self::validate_iso8601_timestamp( $value ) ) {
			return array( __( 'Annotation field lastModified must be an ISO 8601 timestamp with a time zone', 'mcp-adapter' ) );
		}

		return array();
	}

	/**
	 * Validate ISO 8601 timestamp format.
	 *
	 * Mirrors the official TypeScript SDK client rule (`z.iso.datetime({ offset: true })`
	 * in `@modelcontextprotocol/sdk` 1.27): a calendar date, `T`, a time with seconds and
	 * any number of fractional digits, and a `Z` or `±HH:MM` zone. The client also
	 * accepts a time without seconds and rejects an offset without a colon; both are
	 * matched here so the adapter never emits what the client would refuse.
	 *
	 * @param string $timestamp The timestamp to validate.
	 *
	 * @return bool True if valid ISO 8601 timestamp, false otherwise.
	 */
	public static function validate_iso8601_timestamp( string $timestamp ): bool {
		// \z rather than $: a dollar anchor would also match before a trailing newline.
		$matched = preg_match(
			'/^(\d{4}-\d{2}-\d{2})T([01]\d|2[0-3]):[0-5]\d(?::[0-5]\d(?:\.\d+)?)?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)\z/',
			$timestamp,
			$matches
		);
		if ( 1 !== $matched ) {
			return false;
		}

		// The pattern bounds the clock fields; the calendar date still needs a real-day
		// check. Parse in UTC so a zone that skipped a civil day (Pacific/Apia skipped
		// 2011-12-30) cannot make a valid date fail under that site's default time zone.
		$date = DateTime::createFromFormat( '!Y-m-d', $matches[1], new DateTimeZone( 'UTC' ) );

		return $date instanceof DateTime && $date->format( 'Y-m-d' ) === $matches[1];
	}

	/**
	 * Check that a resource URI starts with a scheme.
	 *
	 * The schema types `uri` as `format: uri`, which the schema package does not
	 * enforce. The adapter checks the scheme only, because the registry folds it
	 * for lookups. Anything after the colon is left to the author: RFC 3986 allows
	 * an empty path, so a bare `scheme:` passes. There is no length cap.
	 *
	 * @param string $uri The URI to check.
	 *
	 * @return bool True when the URI starts with an RFC 3986 scheme and a colon.
	 */
	public static function validate_resource_uri( string $uri ): bool {
		return (bool) preg_match( '/^' . self::URI_SCHEME_PATTERN . ':/', $uri );
	}

	/**
	 * Lowercase the scheme (the part before the first ":") of a URI.
	 *
	 * URI schemes are case-insensitive per RFC 3986, so "Foo://x" and "foo://x"
	 * identify the same resource. Lowercasing the scheme on both sides of a
	 * comparison lets the two forms match. Everything after the scheme is left
	 * untouched, because case may be meaningful there.
	 *
	 * @param string $uri Resource URI.
	 *
	 * @return string Same URI with a lowercased scheme.
	 * @since 0.6.0
	 */
	public static function fold_uri_scheme( string $uri ): string {
		// On PCRE failure preg_replace_callback() returns null; keep the URI as-is.
		return preg_replace_callback(
			'/^(' . self::URI_SCHEME_PATTERN . '):/',
			static fn( array $matches ): string => strtolower( $matches[1] ) . ':',
			$uri
		) ?? $uri;
	}
}
