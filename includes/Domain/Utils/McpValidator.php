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
		return str_starts_with( strtolower( $mime_type ), 'image/' );
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
		return str_starts_with( strtolower( $mime_type ), 'audio/' );
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

		// Reject whitespace-only strings (they decode to empty string but aren't valid base64 content).
		if ( trim( $content ) === '' ) {
			return false;
		}

		// Check if it's valid base64 encoding.
		return base64_decode( $content, true ) !== false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
	}

	/**
	 * Validate a resource URI format.
	 *
	 * Per MCP spec: "The URI can use any protocol; it is up to the server how to interpret it."
	 * This validates basic URI structure per RFC 3986.
	 *
	 * @param string $uri The URI to validate.
	 *
	 * @return bool True if valid, false otherwise.
	 */
	public static function validate_resource_uri( string $uri ): bool {
		// URI should not be empty.
		if ( empty( $uri ) ) {
			return false;
		}

		// Check reasonable length constraints.
		if ( strlen( $uri ) > 2048 ) {
			return false;
		}

		// Basic URI validation: must have scheme followed by colon (RFC 3986).
		// This accepts any protocol as per MCP specification.
		return (bool) preg_match( '/^[a-zA-Z][a-zA-Z0-9+.-]*:.+/', $uri );
	}

	/**
	 * Validate a role value according to MCP specification.
	 *
	 * Valid roles are "user" or "assistant".
	 *
	 * @param string $role The role to validate.
	 *
	 * @return bool True if valid, false otherwise.
	 */
	public static function validate_role( string $role ): bool {
		return in_array( $role, array( 'user', 'assistant' ), true );
	}

	/**
	 * Validate an array of roles according to MCP specification.
	 *
	 * All roles must be strings and must be either "user" or "assistant".
	 *
	 * @param array $roles The roles array to validate.
	 *
	 * @return bool True if all roles are valid, false otherwise.
	 */
	public static function validate_roles_array( array $roles ): bool {
		if ( empty( $roles ) ) {
			return false;
		}

		foreach ( $roles as $role ) {
			if ( ! is_string( $role ) || ! self::validate_role( $role ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Validate a priority value according to MCP specification.
	 *
	 * Priority must be a number between 0.0 and 1.0 (inclusive).
	 *
	 * @param mixed $priority The priority value to validate.
	 *
	 * @return bool True if valid, false otherwise.
	 */
	public static function validate_priority( $priority ): bool {
		if ( ! is_numeric( $priority ) ) {
			return false;
		}

		$priority_float = (float) $priority;
		return $priority_float >= 0.0 && $priority_float <= 1.0;
	}

	/**
	 * Get validation errors for tool-specific MCP annotations.
	 *
	 * Validates tool annotation fields per MCP 2025-06-18 specification:
	 * - readOnlyHint, destructiveHint, idempotentHint, openWorldHint must be booleans
	 * - title must be a non-empty string
	 *
	 * Only validates known tool annotation fields. Unknown fields are ignored.
	 *
	 * @param array $annotations The annotations to validate.
	 *
	 * @return array Array of validation errors, empty if valid.
	 */
	public static function get_tool_annotation_validation_errors( array $annotations ): array {
		$errors = array();

		foreach ( $annotations as $field => $value ) {
			switch ( $field ) {
				case 'readOnlyHint':
				case 'destructiveHint':
				case 'idempotentHint':
				case 'openWorldHint':
					if ( ! is_bool( $value ) ) {
						$errors[] = sprintf(
							/* translators: %s: annotation field name */
							__( 'Tool annotation field %s must be a boolean', 'mcp-adapter' ),
							$field
						);
					}
					break;

				case 'title':
					if ( ! is_string( $value ) ) {
						$errors[] = sprintf(
							/* translators: %s: annotation field name */
							__( 'Tool annotation field %s must be a string', 'mcp-adapter' ),
							$field
						);
						break;
					}
					if ( empty( trim( $value ) ) ) {
						$errors[] = sprintf(
							/* translators: %s: annotation field name */
							__( 'Tool annotation field %s must be a non-empty string', 'mcp-adapter' ),
							$field
						);
					}
					break;

				default:
					// Unknown fields are ignored to allow forward compatibility.
					break;
			}
		}

		return $errors;
	}

	/**
	 * Get validation errors for shared MCP annotations.
	 *
	 * Validates shared annotation fields per MCP 2025-06-18 specification:
	 * - audience must be a non-empty array of valid Role values ("user", "assistant")
	 * - lastModified must be a valid ISO 8601 formatted string
	 * - priority must be a number between 0.0 and 1.0
	 *
	 * Only validates known shared annotation fields. Unknown fields are ignored.
	 * Used by resources, prompts, and tools.
	 *
	 * @param array $annotations The annotations to validate.
	 *
	 * @return array Array of validation errors, empty if valid.
	 */
	public static function get_annotation_validation_errors( array $annotations ): array {
		$errors = array();

		foreach ( $annotations as $field => $value ) {
			switch ( $field ) {
				case 'audience':
					if ( ! is_array( $value ) ) {
						$errors[] = __( 'Annotation field audience must be an array', 'mcp-adapter' );
						break;
					}
					if ( empty( $value ) ) {
						$errors[] = __( 'Annotation field audience must be a non-empty array', 'mcp-adapter' );
						break;
					}
					if ( ! self::validate_roles_array( $value ) ) {
						$errors[] = __( 'Annotation field audience must contain only valid roles ("user" or "assistant")', 'mcp-adapter' );
					}
					break;

				case 'lastModified':
					if ( ! is_string( $value ) || empty( trim( $value ) ) ) {
						$errors[] = __( 'Annotation field lastModified must be a non-empty string', 'mcp-adapter' );
						break;
					}
					if ( ! self::validate_iso8601_timestamp( trim( $value ) ) ) {
						$errors[] = __( 'Annotation field lastModified must be a valid ISO 8601 timestamp', 'mcp-adapter' );
					}
					break;

				case 'priority':
					if ( ! is_numeric( $value ) ) {
						$errors[] = __( 'Annotation field priority must be a number', 'mcp-adapter' );
						break;
					}
					if ( ! self::validate_priority( $value ) ) {
						$errors[] = __( 'Annotation field priority must be between 0.0 and 1.0', 'mcp-adapter' );
					}
					break;

				default:
					// Unknown fields are ignored to allow forward compatibility.
					break;
			}
		}

		return $errors;
	}
}
