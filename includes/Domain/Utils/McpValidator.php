<?php

/**
 * MCP Validator utility class for validating MCP component data.
 *
 * @package McpAdapter
 */

declare(strict_types=1);

namespace WP\MCP\Domain\Utils;

use WP\McpSchema\Server\Prompts\Prompt;
use WP\McpSchema\Server\Resources\Resource;
use WP\McpSchema\Server\Tools\Tool;

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
	 * Validates that a name follows MCP naming conventions per MCP 2025-11-25 spec:
	 * - Must not be empty
	 * - Must not exceed the maximum length
	 * - Must only contain letters, numbers, hyphens (-), underscores (_), and dots (.)
	 *
	 * @since n.e.x.t
	 *
	 * @param string $name The name to validate.
	 * @param int    $max_length Maximum allowed length. Default is 128 per MCP spec.
	 *
	 * @return bool True if valid, false otherwise.
	 */
	public static function validate_name( string $name, int $max_length = 128 ): bool {
		// Names should not be empty.
		if ( empty( $name ) ) {
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
	 * Validate a tool or prompt name per MCP 2025-11-25 spec.
	 *
	 * Tool and prompt names must be 1-128 characters using charset [A-Za-z0-9_.-].
	 *
	 * @since n.e.x.t
	 *
	 * @param string $name The name to validate.
	 *
	 * @return bool True if valid, false otherwise.
	 */
	public static function validate_tool_or_prompt_name( string $name ): bool {
		return self::validate_name( $name, 128 );
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
	 * Validates tool annotation fields per MCP 2025-11-25 specification:
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
	 * Validate an icon source (src) value.
	 *
	 * Icon src must be a valid URL (http/https) or a data: URI with base64-encoded image data.
	 *
	 * @since n.e.x.t
	 *
	 * @param string $src The icon source to validate.
	 *
	 * @return bool True if valid, false otherwise.
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
	 * @since n.e.x.t
	 *
	 * @param string $mime_type The MIME type to validate.
	 *
	 * @return bool True if valid icon MIME type, false otherwise.
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
	 * @since n.e.x.t
	 *
	 * @param string $size The size string to validate.
	 *
	 * @return bool True if valid, false otherwise.
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
	 * @since n.e.x.t
	 *
	 * @param string $theme The theme to validate.
	 *
	 * @return bool True if valid, false otherwise.
	 */
	public static function validate_icon_theme( string $theme ): bool {
		return in_array( strtolower( trim( $theme ) ), array( 'light', 'dark' ), true );
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
	 * @since n.e.x.t
	 *
	 * @param array $icon The icon data to validate.
	 *
	 * @return array Array of validation errors, empty if valid.
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
	 * Validate an array of icons.
	 *
	 * Returns valid icons and logs warnings for invalid ones.
	 * Invalid icons are filtered out (graceful degradation).
	 *
	 * @since n.e.x.t
	 *
	 * @param array $icons    Array of icon data.
	 * @param bool  $log_warnings Whether to log warnings for invalid icons. Default true.
	 *
	 * @return array{valid: array, errors: array} Array with 'valid' icons and 'errors' details.
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
	 * Get validation errors for shared MCP annotations.
	 *
	 * Validates shared annotation fields per MCP 2025-11-25 specification:
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

	/**
	 * Validate a Tool DTO.
	 *
	 * @param \WP\McpSchema\Server\Tools\Tool $tool The tool DTO to validate.
	 *
	 * @return bool|\WP_Error True if valid, WP_Error otherwise.
	 */
	public static function validate_tool_dto( Tool $tool ) {
		$errors = array();

		// Validate name.
		if ( ! self::validate_tool_or_prompt_name( $tool->getName() ) ) {
			$errors[] = __( 'Tool name must be 1-128 characters and contain only [A-Za-z0-9_.-]', 'mcp-adapter' );
		}

		// Validate icons if present.
		$icons = $tool->getIcons();
		if ( ! empty( $icons ) ) {
			// Convert DTO icons to arrays for validation.
			$icons_array  = array_map( static fn( $icon ) => $icon->toArray(), $icons );
			$icons_result = self::validate_icons_array( $icons_array );
			if ( ! empty( $icons_result['errors'] ) ) {
				foreach ( $icons_result['errors'] as $error_group ) {
					foreach ( $error_group['errors'] as $error ) {
						$errors[] = sprintf(
							/* translators: 1: icon index, 2: error message */
							__( 'Icon at index %1$d: %2$s', 'mcp-adapter' ),
							$error_group['index'],
							$error
						);
					}
				}
			}
		}

		// Validate annotations if present.
		$annotations = $tool->getAnnotations();
		if ( $annotations ) {
			$annotations_array = $annotations->toArray();
			$shared_errors     = self::get_annotation_validation_errors( $annotations_array );
			$tool_errors       = self::get_tool_annotation_validation_errors( $annotations_array );
			$errors            = array_merge( $errors, $shared_errors, $tool_errors );
		}

		if ( ! empty( $errors ) ) {
			return new \WP_Error(
				'mcp_tool_validation_failed',
				sprintf(
					/* translators: %s: list of validation errors */
					__( 'Tool validation failed: %s', 'mcp-adapter' ),
					implode( '; ', $errors )
				)
			);
		}

		return true;
	}

	/**
	 * Validate a Prompt DTO.
	 *
	 * @param \WP\McpSchema\Server\Prompts\Prompt $prompt The prompt DTO to validate.
	 *
	 * @return bool|\WP_Error True if valid, WP_Error otherwise.
	 */
	public static function validate_prompt_dto( Prompt $prompt ) {
		$errors = array();

		// Validate name.
		if ( ! self::validate_tool_or_prompt_name( $prompt->getName() ) ) {
			$errors[] = __( 'Prompt name must be 1-128 characters and contain only [A-Za-z0-9_.-]', 'mcp-adapter' );
		}

		// Validate icons if present.
		$icons = $prompt->getIcons();
		if ( ! empty( $icons ) ) {
			$icons_array  = array_map( static fn( $icon ) => $icon->toArray(), $icons );
			$icons_result = self::validate_icons_array( $icons_array );
			if ( ! empty( $icons_result['errors'] ) ) {
				foreach ( $icons_result['errors'] as $error_group ) {
					foreach ( $error_group['errors'] as $error ) {
						$errors[] = sprintf(
							/* translators: 1: icon index, 2: error message */
							__( 'Icon at index %1$d: %2$s', 'mcp-adapter' ),
							$error_group['index'],
							$error
						);
					}
				}
			}
		}

		// Validate annotations if present (shared annotations).
		// Currently Prompt DTO doesn't have annotations field in spec, but if it did, we'd validate here.
		// BaseMetadata has title and name, handled separately.

		if ( ! empty( $errors ) ) {
			return new \WP_Error(
				'mcp_prompt_validation_failed',
				sprintf(
					/* translators: %s: list of validation errors */
					__( 'Prompt validation failed: %s', 'mcp-adapter' ),
					implode( '; ', $errors )
				)
			);
		}

		return true;
	}

	/**
	 * Validate a Resource DTO.
	 *
	 * @param \WP\McpSchema\Server\Resources\Resource $resource The resource DTO to validate.
	 *
	 * @return bool|\WP_Error True if valid, WP_Error otherwise.
	 */
	public static function validate_resource_dto( Resource $resource ) {
		$errors = array();

		// Validate URI.
		if ( ! self::validate_resource_uri( $resource->getUri() ) ) {
			$errors[] = __( 'Resource URI must be a valid URI string', 'mcp-adapter' );
		}

		// Validate MIME type if present.
		$mime_type = $resource->getMimeType();
		if ( $mime_type && ! self::validate_mime_type( $mime_type ) ) {
			$errors[] = __( 'Resource MIME type is invalid', 'mcp-adapter' );
		}

		// Validate icons if present.
		$icons = $resource->getIcons();
		if ( ! empty( $icons ) ) {
			$icons_array  = array_map( static fn( $icon ) => $icon->toArray(), $icons );
			$icons_result = self::validate_icons_array( $icons_array );
			if ( ! empty( $icons_result['errors'] ) ) {
				foreach ( $icons_result['errors'] as $error_group ) {
					foreach ( $error_group['errors'] as $error ) {
						$errors[] = sprintf(
							/* translators: 1: icon index, 2: error message */
							__( 'Icon at index %1$d: %2$s', 'mcp-adapter' ),
							$error_group['index'],
							$error
						);
					}
				}
			}
		}

		if ( ! empty( $errors ) ) {
			return new \WP_Error(
				'mcp_resource_validation_failed',
				sprintf(
					/* translators: %s: list of validation errors */
					__( 'Resource validation failed: %s', 'mcp-adapter' ),
					implode( '; ', $errors )
				)
			);
		}

		return true;
	}
}
