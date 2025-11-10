<?php
/**
 * MCP Validator utility class for validating MCP component data.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Domain\Utils;

/**
 * Utility class for validating MCP component data according to MCP specification.
 *
 * Provides shared validation implementations used across multiple MCP component
 * validators and registration classes. Each method focuses on a specific validation concern.
 */
class McpValidator {

	/**
	 * Validate ISO 8601 timestamp format.
	 *
	 * Checks if a string is a valid ISO 8601 timestamp by attempting to parse
	 * it using multiple ISO 8601 format variations.
	 *
	 * @param string $timestamp The timestamp to validate.
	 *
	 * @return bool True if valid ISO 8601 timestamp, false otherwise.
	 */
	public static function validate_iso8601_timestamp( string $timestamp ): bool {
		// Try to parse as DateTime with ISO 8601 format.
		$datetime = \DateTime::createFromFormat( \DateTime::ATOM, $timestamp );
		if ( $datetime && $datetime->format( \DateTime::ATOM ) === $timestamp ) {
			return true;
		}

		// Try alternative ISO 8601 formats.
		$formats = array(
			'Y-m-d\TH:i:s\Z',           // UTC format
			'Y-m-d\TH:i:sP',            // With timezone offset
			'Y-m-d\TH:i:s.u\Z',         // With microseconds UTC
			'Y-m-d\TH:i:s.uP',          // With microseconds and timezone
		);

		foreach ( $formats as $format ) {
			$datetime = \DateTime::createFromFormat( $format, $timestamp );
			if ( $datetime && $datetime->format( $format ) === $timestamp ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Validate an MCP component name.
	 *
	 * Validates that a name follows MCP naming conventions:
	 * - Must not be empty
	 * - Must not exceed the maximum length
	 * - Must only contain letters, numbers, hyphens (-), and underscores (_)
	 *
	 * @param string $name The name to validate.
	 * @param int    $max_length Maximum allowed length. Default is 255.
	 *
	 * @return bool True if valid, false otherwise.
	 */
	public static function validate_name( string $name, int $max_length = 255 ): bool {
		// Names should not be empty.
		if ( empty( $name ) ) {
			return false;
		}

		// Check length constraints.
		if ( strlen( $name ) > $max_length ) {
			return false;
		}

		// Only allow letters, numbers, hyphens, and underscores.
		return (bool) preg_match( '/^[a-zA-Z0-9_-]+$/', $name );
	}

	/**
	 * Validate a tool or prompt name (max 255 characters).
	 *
	 * @param string $name The name to validate.
	 *
	 * @return bool True if valid, false otherwise.
	 */
	public static function validate_tool_or_prompt_name( string $name ): bool {
		return self::validate_name( $name, 255 );
	}

	/**
	 * Validate an argument name (max 64 characters).
	 *
	 * @param string $name The name to validate.
	 *
	 * @return bool True if valid, false otherwise.
	 */
	public static function validate_argument_name( string $name ): bool {
		return self::validate_name( $name, 64 );
	}

	/**
	 * Validate general MIME type format.
	 *
	 * Validates that a MIME type follows the standard format: type/subtype
	 * where both type and subtype contain valid characters.
	 *
	 * @param string $mime_type The MIME type to validate.
	 *
	 * @return bool True if valid MIME type format, false otherwise.
	 */
	public static function validate_mime_type( string $mime_type ): bool {
		return (bool) preg_match( '/^[a-zA-Z0-9][a-zA-Z0-9!#$&\-\^_]*\/[a-zA-Z0-9][a-zA-Z0-9!#$&\-\^_]*$/', $mime_type );
	}

	/**
	 * Validate image MIME type.
	 *
	 * Checks if the MIME type is a valid image type according to MCP specification.
	 *
	 * @param string $mime_type The MIME type to validate.
	 *
	 * @return bool True if valid image MIME type, false otherwise.
	 */
	public static function validate_image_mime_type( string $mime_type ): bool {
		$valid_image_types = array(
			'image/jpeg',
			'image/jpg',
			'image/png',
			'image/gif',
			'image/webp',
			'image/bmp',
			'image/svg+xml',
		);

		return in_array( strtolower( $mime_type ), $valid_image_types, true );
	}

	/**
	 * Validate audio MIME type.
	 *
	 * Checks if the MIME type is a valid audio type according to MCP specification.
	 *
	 * @param string $mime_type The MIME type to validate.
	 *
	 * @return bool True if valid audio MIME type, false otherwise.
	 */
	public static function validate_audio_mime_type( string $mime_type ): bool {
		$valid_audio_types = array(
			'audio/wav',
			'audio/mp3',
			'audio/mpeg',
			'audio/ogg',
			'audio/webm',
			'audio/aac',
			'audio/flac',
		);

		return in_array( strtolower( $mime_type ), $valid_audio_types, true );
	}

	/**
	 * Validate base64 content.
	 *
	 * Checks if a string is valid base64-encoded content.
	 *
	 * @param string $content The content to validate as base64.
	 *
	 * @return bool True if valid base64, false otherwise.
	 */
	public static function validate_base64( string $content ): bool {
		// Base64 content should not be empty.
		if ( empty( $content ) ) {
			return false;
		}

		// Check if it's valid base64 encoding.
		return base64_decode( $content, true ) !== false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
	}
}
