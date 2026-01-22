<?php

/**
 * MCP Validator utility class for validating MCP component data.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Domain\Utils;

use DateTime;

/**
 * Utility class for validating MCP component data according to MCP specification.
 *
 * Provides shared validation implementations used across multiple MCP component
 * validators and registration classes. Each method focuses on a specific validation concern.
 */
class McpValidator {

	/**
	 * Allowed MIME types for MCP icons per specification.
	 *
	 * MUST support: image/png, image/jpeg, image/jpg
	 * SHOULD support: image/svg+xml, image/webp
	 *
	 * @since n.e.x.t
	 *
	 * @var array<string>
	 */
	private static array $allowed_icon_mime_types = array(
		'image/png',
		'image/jpeg',
		'image/jpg',
		'image/svg+xml',
		'image/webp',
	);

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
	 * @since n.e.x.t
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
	 * Validate an array of icons.
	 *
	 * Returns valid icons and logs warnings for invalid ones.
	 * Invalid icons are filtered out (graceful degradation).
	 *
	 * @param array $icons Array of icon data.
	 * @param bool $log_warnings Whether to log warnings for invalid icons. Default true.
	 *
	 * @return array{valid: array, errors: array} Array with 'valid' icons and 'errors' details.
	 * @since n.e.x.t
	 *
	 */
	public static function validate_icons_array( array $icons, bool $log_warnings = true ): array {
		$valid_icons = array();
		$all_errors  = array();

		foreach ( $icons as $index => $icon ) {
			if ( ! is_array( $icon ) ) {
				$all_errors[] = array(
					'index'  => $index,
					'errors' => array( __( 'Icon must be an array', 'mcp-adapter' ) ),
				);
				continue;
			}

			$errors = self::get_icon_validation_errors( $icon );

			if ( empty( $errors ) ) {
				$valid_icons[] = $icon;
			} else {
				$all_errors[] = array(
					'index'  => $index,
					'errors' => $errors,
				);

				if ( $log_warnings ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log(
						sprintf(
							'MCP Adapter: Invalid icon at index %d skipped: %s',
							$index,
							implode( '; ', $errors )
						)
					);
				}
			}
		}

		return array(
			'valid'  => $valid_icons,
			'errors' => $all_errors,
		);
	}

	/**
	 * Get validation errors for an MCP icon object.
	 *
	 * Validates icon fields per MCP 2025-11-25 specification:
	 * - src (required): Valid URL or data: URI
	 * - mimeType (optional): One of allowed image MIME types
	 * - sizes (optional): Array of size strings in WxH format or "any"
	 * - theme (optional): "light" or "dark"
	 *
	 * @param array $icon The icon data to validate.
	 *
	 * @return array Array of validation errors, empty if valid.
	 * @since n.e.x.t
	 *
	 */
	public static function get_icon_validation_errors( array $icon ): array {
		$errors = array();

		// src is required.
		if ( ! isset( $icon['src'] ) ) {
			$errors[] = __( 'Icon must have a src field', 'mcp-adapter' );
		} elseif ( ! is_string( $icon['src'] ) ) {
			$errors[] = __( 'Icon src must be a string', 'mcp-adapter' );
		} elseif ( ! self::validate_icon_src( $icon['src'] ) ) {
			$errors[] = __( 'Icon src must be a valid URL (http/https) or data: URI', 'mcp-adapter' );
		}

		// mimeType is optional but must be valid if present.
		if ( isset( $icon['mimeType'] ) ) {
			if ( ! is_string( $icon['mimeType'] ) ) {
				$errors[] = __( 'Icon mimeType must be a string', 'mcp-adapter' );
			} elseif ( ! self::validate_icon_mime_type( $icon['mimeType'] ) ) {
				$errors[] = sprintf(
				/* translators: %s: comma-separated list of allowed MIME types */
					__( 'Icon mimeType must be one of: %s', 'mcp-adapter' ),
					implode( ', ', self::$allowed_icon_mime_types )
				);
			}
		}

		// sizes is optional but must be valid if present.
		if ( isset( $icon['sizes'] ) ) {
			if ( ! is_array( $icon['sizes'] ) ) {
				$errors[] = __( 'Icon sizes must be an array', 'mcp-adapter' );
			} else {
				foreach ( $icon['sizes'] as $index => $size ) {
					if ( ! is_string( $size ) ) {
						$errors[] = sprintf(
						/* translators: %d: array index */
							__( 'Icon size at index %d must be a string', 'mcp-adapter' ),
							$index
						);
					} elseif ( ! self::validate_icon_size( $size ) ) {
						$errors[] = sprintf(
						/* translators: 1: size value, 2: array index */
							__( 'Icon size "%1$s" at index %2$d must be in WxH format (e.g., "48x48") or "any"', 'mcp-adapter' ),
							$size,
							$index
						);
					}
				}
			}
		}

		// theme is optional but must be valid if present.
		if ( isset( $icon['theme'] ) ) {
			if ( ! is_string( $icon['theme'] ) ) {
				$errors[] = __( 'Icon theme must be a string', 'mcp-adapter' );
			} elseif ( ! self::validate_icon_theme( $icon['theme'] ) ) {
				$errors[] = __( 'Icon theme must be "light" or "dark"', 'mcp-adapter' );
			}
		}

		return $errors;
	}

	/**
	 * Validate an icon source (src) value.
	 *
	 * Icon src must be a valid URL (http/https) or a data: URI with base64-encoded image data.
	 *
	 * @param string $src The icon source to validate.
	 *
	 * @return bool True if valid, false otherwise.
	 * @since n.e.x.t
	 *
	 */
	public static function validate_icon_src( string $src ): bool {
		$src = trim( $src );

		if ( empty( $src ) ) {
			return false;
		}

		// Check for data: URI.
		if ( str_starts_with( $src, 'data:' ) ) {
			// data:[<mediatype>][;base64],<data>
			// Simplified validation: must have data: prefix and contain comma.
			return str_contains( $src, ',' );
		}

		// Check for http/https URL.
		if ( str_starts_with( $src, 'http://' ) || str_starts_with( $src, 'https://' ) ) {
			return filter_var( $src, FILTER_VALIDATE_URL ) !== false;
		}

		return false;
	}

	/**
	 * Validate an icon MIME type.
	 *
	 * Per MCP spec, clients MUST support image/png, image/jpeg (and image/jpg).
	 * Clients SHOULD support image/svg+xml, image/webp.
	 *
	 * @param string $mime_type The MIME type to validate.
	 *
	 * @return bool True if valid icon MIME type, false otherwise.
	 * @since n.e.x.t
	 *
	 */
	public static function validate_icon_mime_type( string $mime_type ): bool {
		return in_array( strtolower( trim( $mime_type ) ), self::$allowed_icon_mime_types, true );
	}

	/**
	 * Validate an icon size string.
	 *
	 * Icon sizes must be in WxH format (e.g., "48x48", "96x96") or "any" for scalable formats.
	 * Both width and height must be positive integers (no zero dimensions, no leading zeros).
	 *
	 * @param string $size The size string to validate.
	 *
	 * @return bool True if valid, false otherwise.
	 * @since n.e.x.t
	 *
	 */
	public static function validate_icon_size( string $size ): bool {
		$size = trim( $size );

		if ( empty( $size ) ) {
			return false;
		}

		// "any" is valid for scalable formats like SVG.
		if ( 'any' === strtolower( $size ) ) {
			return true;
		}

		// Must match WxH format with positive integers (no zero dimensions, no leading zeros).
		// [1-9]\d* matches: 1, 2, ..., 9, 10, 11, ..., 99, 100, etc.
		return (bool) preg_match( '/^[1-9]\d*x[1-9]\d*$/', $size );
	}

	/**
	 * Validate an icon theme value.
	 *
	 * Valid themes are "light" or "dark".
	 *
	 * @param string $theme The theme to validate.
	 *
	 * @return bool True if valid, false otherwise.
	 * @since n.e.x.t
	 *
	 */
	public static function validate_icon_theme( string $theme ): bool {
		return in_array( strtolower( trim( $theme ) ), array( 'light', 'dark' ), true );
	}

	/**
	 * Get validation errors for shared MCP annotations.
	 *
	 * Validates shared annotation fields per MCP 2025-11-25 specification:
	 * - audience must be an array of valid Role values ("user", "assistant")
	 * - lastModified must be a valid ISO 8601 formatted string
	 * - priority must be a number between 0.0 and 1.0
	 *
	 * Only validates known shared annotation fields. Unknown fields are ignored.
	 * Used by resources and content types (text, image, audio).
	 *
	 * Note: Tools use ToolAnnotations which is a separate type validated by McpToolValidator.
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
		foreach ( $roles as $role ) {
			if ( ! is_string( $role ) || ! self::validate_role( $role ) ) {
				return false;
			}
		}

		return true;
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
		$datetime = DateTime::createFromFormat( DateTime::ATOM, $timestamp );
		if ( $datetime && $datetime->format( DateTime::ATOM ) === $timestamp ) {
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
			$datetime = DateTime::createFromFormat( $format, $timestamp );
			if ( $datetime && $datetime->format( $format ) === $timestamp ) {
				return true;
			}
		}

		return false;
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
		// RFC 2045 compliant: allows +, ., and other valid MIME type characters.
		// Examples: image/svg+xml, application/vnd.api+json, text/plain.
		return (bool) preg_match( '/^[a-zA-Z0-9][a-zA-Z0-9!#$&^_.+-]*\/[a-zA-Z0-9][a-zA-Z0-9!#$&^_.+-]*$/', $mime_type );
	}
}
