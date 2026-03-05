<?php
/**
 * MCP Name Sanitizer utility for normalizing component names.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Domain\Utils;

use WP_Error;

/**
 * Utility class for sanitizing names to MCP-valid format.
 *
 * Implements best-effort sanitization per MCP 2025-11-25 spec:
 * - Length: 1-128 characters
 * - Charset: A-Za-z0-9_.-
 *
 * Used for both tools and prompts (same naming rules).
 * NOT used for resources (which use URIs as identifiers).
 *
 * @since n.e.x.t
 */
class McpNameSanitizer {

	/**
	 * Maximum length for MCP tool/prompt names per spec.
	 *
	 * @var int
	 */
	public const MAX_LENGTH = 128;

	/**
	 * Length of the hash suffix used for truncation uniqueness.
	 *
	 * @var int
	 */
	public const HASH_LENGTH = 12;

	/**
	 * Maximum characters to keep when truncating (MAX_LENGTH - 1 separator - HASH_LENGTH).
	 *
	 * @var int
	 */
	public const TRUNCATE_LENGTH = 115;

	/**
	 * Sanitize a name to be MCP-valid for tools and prompts.
	 *
	 * Normalization steps:
	 * 1. Trim whitespace
	 * 2. Replace `/` with `-` (forward slash not allowed in MCP)
	 * 3. If valid, return as-is
	 * 4. Otherwise: transliterate accents, replace invalid chars, collapse hyphens, trim edges
	 * 5. If too long: truncate + add hash suffix for uniqueness
	 * 6. If still invalid/empty: return WP_Error
	 *
	 * @param string $name Original name.
	 *
	 * @return string|\WP_Error Sanitized name or WP_Error if unsalvageable.
	 * @since n.e.x.t
	 *
	 */
	public static function sanitize_name( string $name ) {
		$original = $name;

		// Step 1: Trim whitespace.
		$name = trim( $name );

		// Step 2: Replace / with - (forward slash not allowed in MCP).
		$name = str_replace( '/', '-', $name );

		// Step 3: Early validation - if already valid, return as-is.
		if ( McpValidator::validate_name( $name ) ) {
			return $name;
		}

		// Step 4a: Transliterate accented characters to ASCII equivalents.
		// Uses WordPress core function: é→e, ü→u, ñ→n, etc.
		$name = remove_accents( $name );

		// Step 4b: Replace any remaining non-allowed chars with hyphen.
		$name = (string) preg_replace( '/[^a-zA-Z0-9_.-]/', '-', $name );

		// Step 4c: Collapse consecutive hyphens.
		$name = (string) preg_replace( '/-+/', '-', $name );

		// Step 4d: Trim leading/trailing hyphens and underscores.
		$name = trim( $name, '-_' );

		// Step 5: Handle length > 128.
		if ( strlen( $name ) > self::MAX_LENGTH ) {
			$hash = substr( md5( $original ), 0, self::HASH_LENGTH );
			$name = substr( $name, 0, self::TRUNCATE_LENGTH ) . '-' . $hash;
		}

		// Step 6: Final check - only empty is possible failure after sanitization.
		// Characters are guaranteed valid (replaced), length is handled (truncated).
		if ( empty( $name ) ) {
			return new WP_Error(
				'mcp_name_invalid',
				sprintf(
				/* translators: %s: original ability name */
					__( 'Unable to derive valid MCP name from: %s', 'mcp-adapter' ),
					$original
				)
			);
		}

		return $name;
	}
}
