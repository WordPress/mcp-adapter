<?php
/**
 * MCP Prompt Validator class for validating MCP prompts according to the specification.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Domain\Prompts;

/**
 * Validates MCP prompts against the Model Context Protocol specification.
 *
 * Provides minimal, resource-efficient validation to ensure prompts conform
 * to the MCP schema requirements without heavy processing overhead.
 *
 * @link https://modelcontextprotocol.io/specification/2025-06-18/server/prompts
 */
class McpPromptValidator {

	/**
	 * Validate an MCP prompt data array against the MCP schema.
	 *
	 * @param array  $prompt_data The prompt data to validate.
	 * @param string $context Optional context for error messages.
	 *
	 * @return bool|\WP_Error True if valid, WP_Error if validation fails.
	 */
	public static function validate_prompt_data( array $prompt_data, string $context = '' ) {
		$validation_errors = self::get_validation_errors( $prompt_data );

		if ( ! empty( $validation_errors ) ) {
			$error_message  = $context ? "[{$context}] " : '';
			$error_message .= sprintf(
			/* translators: %s: comma-separated list of validation errors */
				__( 'Prompt validation failed: %s', 'mcp-adapter' ),
				implode( ', ', $validation_errors )
			);
			return new \WP_Error( 'prompt_validation_failed', esc_html( $error_message ) );
		}

		return true;
	}

	/**
	 * Validate an McpPrompt instance against the MCP schema.
	 *
	 * @param \WP\MCP\Domain\Prompts\McpPrompt $prompt The prompt instance to validate.
	 * @param string    $context Optional context for error messages.
	 *
	 * @return bool|\WP_Error True if valid, WP_Error if validation fails.
	 */
	public static function validate_prompt_instance( McpPrompt $prompt, string $context = '' ) {
		$uniqueness_result = self::validate_prompt_uniqueness( $prompt, $context );
		if ( is_wp_error( $uniqueness_result ) ) {
			return $uniqueness_result;
		}

		return self::validate_prompt_data( $prompt->to_array(), $context );
	}


	/**
	 * Validate that the resource is unique within the MCP server.
	 *
	 * @param \WP\MCP\Domain\Prompts\McpPrompt $prompt The resource instance to validate.
	 * @param string    $context Optional context for error messages.
	 *
	 * @return bool|\WP_Error True if unique, WP_Error if the prompt name is not unique.
	 */
	public static function validate_prompt_uniqueness( McpPrompt $prompt, string $context = '' ) {
		$this_prompt_name  = $prompt->get_name();
		$existing_resource = $prompt->get_mcp_server()->get_prompt( $this_prompt_name );
		if ( $existing_resource ) {
			$error_message  = $context ? "[{$context}] " : '';
			$error_message .= sprintf(
			/* translators: %s is the prompt name */
				__( "Prompt name '%s' is not unique. It already exists in the MCP server.", 'mcp-adapter' ),
				$this_prompt_name
			);
			return new \WP_Error( 'prompt_not_unique', esc_html( $error_message ) );
		}

		return true;
	}

	/**
	 * Get validation error details for debugging purposes.
	 * This is the core validation method - all other validation methods use this.
	 *
	 * @param array $prompt_data The prompt data to validate.
	 *
	 * @return array Array of validation errors, empty if valid.
	 */
	public static function get_validation_errors( array $prompt_data ): array {
		$errors = array();

		// Sanitize string inputs
		if ( isset( $prompt_data['name'] ) && is_string( $prompt_data['name'] ) ) {
			$prompt_data['name'] = trim( $prompt_data['name'] );
		}
		if ( isset( $prompt_data['title'] ) && is_string( $prompt_data['title'] ) ) {
			$prompt_data['title'] = trim( $prompt_data['title'] );
		}
		if ( isset( $prompt_data['description'] ) && is_string( $prompt_data['description'] ) ) {
			$prompt_data['description'] = trim( $prompt_data['description'] );
		}

		// Check required fields
		if ( empty( $prompt_data['name'] ) || ! is_string( $prompt_data['name'] ) || ! self::validate_prompt_name( $prompt_data['name'] ) ) {
			$errors[] = __( 'Prompt name is required and must only contain letters, numbers, hyphens (-), and underscores (_), and be 255 characters or less', 'mcp-adapter' );
		}

		// Check optional fields if present
		if ( isset( $prompt_data['title'] ) && ! is_string( $prompt_data['title'] ) ) {
			$errors[] = __( 'Prompt title must be a string if provided', 'mcp-adapter' );
		}

		if ( isset( $prompt_data['description'] ) && ! is_string( $prompt_data['description'] ) ) {
			$errors[] = __( 'Prompt description must be a string if provided', 'mcp-adapter' );
		}

		// Validate arguments (optional field)
		if ( isset( $prompt_data['arguments'] ) ) {
			$arguments_errors = self::get_arguments_validation_errors( $prompt_data['arguments'] );
			if ( ! empty( $arguments_errors ) ) {
				$errors = array_merge( $errors, $arguments_errors );
			}
		}

		// Validate annotations (optional field)
		if ( isset( $prompt_data['annotations'] ) && ! is_array( $prompt_data['annotations'] ) ) {
			$errors[] = __( 'Prompt annotations must be an array if provided', 'mcp-adapter' );
		}

		return $errors;
	}

	/**
	 * Get detailed validation errors for prompt arguments.
	 *
	 * @param array|mixed $arguments The arguments to validate.
	 *
	 * @return array Array of validation errors, empty if valid.
	 */
	private static function get_arguments_validation_errors( $arguments ): array {
		$errors = array();

		// Arguments must be an array
		if ( ! is_array( $arguments ) ) {
			return array( __( 'Prompt arguments must be an array if provided', 'mcp-adapter' ) );
		}

		// Validate each argument
		foreach ( $arguments as $index => $argument ) {
			if ( ! is_array( $argument ) ) {
				$errors[] = sprintf(
				/* translators: %d: argument index */
					__( 'Prompt argument at index %d must be an object', 'mcp-adapter' ),
					$index
				);
				continue;
			}

			// Check required name field
			if ( empty( $argument['name'] ) || ! is_string( $argument['name'] ) ) {
				$errors[] = sprintf(
				/* translators: %d: argument index */
					__( 'Prompt argument at index %d must have a non-empty name string', 'mcp-adapter' ),
					$index
				);
				continue;
			}

			// Validate argument name format
			if ( ! self::validate_argument_name( $argument['name'] ) ) {
				$errors[] = sprintf(
				/* translators: %s: argument name */
					__( 'Prompt argument \'%s\' name must only contain letters, numbers, hyphens (-), and underscores (_), and be 64 characters or less', 'mcp-adapter' ),
					$argument['name']
				);
			}

			// Check optional description field
			if ( isset( $argument['description'] ) && ! is_string( $argument['description'] ) ) {
				$errors[] = sprintf(
				/* translators: %s: argument name */
					__( 'Prompt argument \'%s\' description must be a string if provided', 'mcp-adapter' ),
					$argument['name']
				);
			}

			// Check optional required field
			if ( ! isset( $argument['required'] ) || is_bool( $argument['required'] ) ) {
				continue;
			}

			$errors[] = sprintf(
			/* translators: %s: argument name */
				__( 'Prompt argument \'%s\' required field must be a boolean if provided', 'mcp-adapter' ),
				$argument['name']
			);
		}

		return $errors;
	}

	/**
	 * Validate prompt messages array (used when getting a prompt with messages).
	 *
	 * @param array $messages The messages to validate.
	 *
	 * @return array Array of validation errors, empty if valid.
	 */
	public static function validate_prompt_messages( array $messages ): array {
		$errors = array();

		foreach ( $messages as $index => $message ) {
			if ( ! is_array( $message ) ) {
				$errors[] = sprintf(
				/* translators: %d: message index */
					__( 'Message at index %d must be an object', 'mcp-adapter' ),
					$index
				);
				continue;
			}

			// Check the required role field
			if ( empty( $message['role'] ) || ! is_string( $message['role'] ) ) {
				$errors[] = sprintf(
				/* translators: %d: message index */
					__( 'Message at index %d must have a role field', 'mcp-adapter' ),
					$index
				);
				continue;
			}

			// Validate role value
			if ( ! in_array( $message['role'], array( 'user', 'assistant' ), true ) ) {
				$errors[] = sprintf(
				/* translators: %d: message index */
					__( 'Message at index %d role must be either \'user\' or \'assistant\'', 'mcp-adapter' ),
					$index
				);
			}

			// Check the required content field
			if ( empty( $message['content'] ) || ! is_array( $message['content'] ) ) {
				$errors[] = sprintf(
				/* translators: %d: message index */
					__( 'Message at index %d must have a content object', 'mcp-adapter' ),
					$index
				);
				continue;
			}

			// Validate content
			$content_errors = self::get_content_validation_errors( $message['content'], $index );
			if ( empty( $content_errors ) ) {
				continue;
			}

			$errors = array_merge( $errors, $content_errors );
		}

		return $errors;
	}

	/**
	 * Get validation errors for message content.
	 *
	 * @param array $content The content to validate.
	 * @param int   $message_index The message index for error reporting.
	 *
	 * @return array Array of validation errors, empty if valid.
	 */
	private static function get_content_validation_errors( array $content, int $message_index ): array {
		$errors = array();

		// Check the required type field
		if ( empty( $content['type'] ) || ! is_string( $content['type'] ) ) {
			return array(
				sprintf(
				/* translators: %d: message index */
					__( 'Message %d content must have a type field', 'mcp-adapter' ),
					$message_index
				),
			);
		}

		$type = $content['type'];

		switch ( $type ) {
			case 'text':
				if ( empty( $content['text'] ) || ! is_string( $content['text'] ) ) {
					$errors[] = sprintf(
					/* translators: %d: message index */
						__( 'Message %d text content must have a non-empty text field', 'mcp-adapter' ),
						$message_index
					);
				}
				break;

			case 'image':
				if ( empty( $content['data'] ) || ! is_string( $content['data'] ) ) {
					$errors[] = sprintf(
					/* translators: %d: message index */
						__( 'Message %d image content must have a data field with base64-encoded image', 'mcp-adapter' ),
						$message_index
					);
				} elseif ( ! self::validate_base64( $content['data'] ) ) {
					$errors[] = sprintf(
					/* translators: %d: message index */
						__( 'Message %d image content data must be valid base64', 'mcp-adapter' ),
						$message_index
					);
				}

				if ( empty( $content['mimeType'] ) || ! is_string( $content['mimeType'] ) ) {
					$errors[] = sprintf(
					/* translators: %d: message index */
						__( 'Message %d image content must have a mimeType field', 'mcp-adapter' ),
						$message_index
					);
				} elseif ( ! self::validate_image_mime_type( $content['mimeType'] ) ) {
					$errors[] = sprintf(
					/* translators: %d: message index */
						__( 'Message %d image content must have a valid image MIME type', 'mcp-adapter' ),
						$message_index
					);
				}
				break;

			case 'audio':
				if ( empty( $content['data'] ) || ! is_string( $content['data'] ) ) {
					$errors[] = sprintf(
					/* translators: %d: message index */
						__( 'Message %d audio content must have a data field with base64-encoded audio', 'mcp-adapter' ),
						$message_index
					);
				} elseif ( ! self::validate_base64( $content['data'] ) ) {
					$errors[] = sprintf(
					/* translators: %d: message index */
						__( 'Message %d audio content data must be valid base64', 'mcp-adapter' ),
						$message_index
					);
				}

				if ( empty( $content['mimeType'] ) || ! is_string( $content['mimeType'] ) ) {
					$errors[] = sprintf(
					/* translators: %d: message index */
						__( 'Message %d audio content must have a mimeType field', 'mcp-adapter' ),
						$message_index
					);
				} elseif ( ! self::validate_audio_mime_type( $content['mimeType'] ) ) {
					$errors[] = sprintf(
					/* translators: %d: message index */
						__( 'Message %d audio content must have a valid audio MIME type', 'mcp-adapter' ),
						$message_index
					);
				}
				break;

			case 'resource':
				if ( empty( $content['resource'] ) || ! is_array( $content['resource'] ) ) {
					$errors[] = sprintf(
					/* translators: %d: message index */
						__( 'Message %d resource content must have a resource object', 'mcp-adapter' ),
						$message_index
					);
				} else {
					// Validate embedded resource using resource validator
					$resource_errors = self::get_validation_errors( $content['resource'] );
					foreach ( $resource_errors as $resource_error ) {
						$errors[] = sprintf(
						/* translators: %1$d: message index, %2$s: resource error */
							__( 'Message %1$d embedded resource: %2$s', 'mcp-adapter' ),
							$message_index,
							$resource_error
						);
					}
				}
				break;

			default:
				$errors[] = sprintf(
				/* translators: %1$d: message index, %2$s: content type */
					__( 'Message %1$d content type \'%2$s\' is not supported. Must be \'text\', \'image\', \'audio\', or \'resource\'', 'mcp-adapter' ),
					$message_index,
					$type
				);
				break;
		}

		// Check optional annotations
		if ( isset( $content['annotations'] ) ) {
			$annotation_errors = self::get_content_annotation_validation_errors( $content['annotations'], $message_index );
			if ( ! empty( $annotation_errors ) ) {
				$errors = array_merge( $errors, $annotation_errors );
			}
		}

		return $errors;
	}

	/**
	 * Get validation errors for message content annotations.
	 *
	 * @param array|mixed $annotations The annotations to validate.
	 * @param int         $message_index The message index for error reporting.
	 *
	 * @return array Array of validation errors, empty if valid.
	 */
	private static function get_content_annotation_validation_errors( $annotations, int $message_index ): array {
		$errors = array();

		// Annotations must be an array
		if ( ! is_array( $annotations ) ) {
			return array(
				sprintf(
				/* translators: %d: message index */
					__( 'Message %d content annotations must be an array if provided', 'mcp-adapter' ),
					$message_index
				),
			);
		}

		// Validate audience field if present
		if ( isset( $annotations['audience'] ) ) {
			if ( ! is_array( $annotations['audience'] ) ) {
				$errors[] = sprintf(
				/* translators: %d: message index */
					__( 'Message %d content annotation \'audience\' must be an array', 'mcp-adapter' ),
					$message_index
				);
			} else {
				$valid_audiences = array( 'user', 'assistant' );
				foreach ( $annotations['audience'] as $audience ) {
					if ( in_array( $audience, $valid_audiences, true ) ) {
						continue;
					}

					$errors[] = sprintf(
					/* translators: %1$d: message index, %2$s: audience value */
						__( 'Message %1$d content annotation audience \'%2$s\' must be \'user\' or \'assistant\'', 'mcp-adapter' ),
						$message_index,
						$audience
					);
				}
			}
		}

		// Validate priority field if present
		if ( isset( $annotations['priority'] ) ) {
			if ( ! is_numeric( $annotations['priority'] ) ) {
				$errors[] = sprintf(
				/* translators: %d: message index */
					__( 'Message %d content annotation \'priority\' must be a number', 'mcp-adapter' ),
					$message_index
				);
			} elseif ( $annotations['priority'] < 0.0 || $annotations['priority'] > 1.0 ) {
				$errors[] = sprintf(
				/* translators: %d: message index */
					__( 'Message %d content annotation \'priority\' must be between 0.0 and 1.0', 'mcp-adapter' ),
					$message_index
				);
			}
		}

		// Validate lastModified field if present
		if ( isset( $annotations['lastModified'] ) ) {
			if ( ! is_string( $annotations['lastModified'] ) ) {
				$errors[] = sprintf(
				/* translators: %d: message index */
					__( 'Message %d content annotation \'lastModified\' must be a string', 'mcp-adapter' ),
					$message_index
				);
			} elseif ( ! self::validate_iso8601_timestamp( $annotations['lastModified'] ) ) {
				$errors[] = sprintf(
				/* translators: %d: message index */
					__( 'Message %d content annotation \'lastModified\' must be a valid ISO 8601 timestamp', 'mcp-adapter' ),
					$message_index
				);
			}
		}

		return $errors;
	}

	/**
	 * Check if prompt data is valid without throwing exceptions.
	 *
	 * @param array $prompt_data The prompt data to validate.
	 *
	 * @return bool True if valid, false otherwise.
	 */
	public static function is_valid_prompt_data( array $prompt_data ): bool {
		return empty( self::get_validation_errors( $prompt_data ) );
	}

	/**
	 * Check if a prompt name follows MCP naming conventions.
	 *
	 * @param string $name The prompt name to validate.
	 *
	 * @return bool True if valid, false otherwise.
	 */
	public static function validate_prompt_name( string $name ): bool {
		// Prompt names should not be empty
		if ( empty( $name ) ) {
			return false;
		}

		// Check length constraints (reasonable limits)
		if ( strlen( $name ) > 255 ) {
			return false;
		}

		// Only allow letters, numbers, hyphens, and underscores
		return (bool) preg_match( '/^[a-zA-Z0-9_-]+$/', $name );
	}

	/**
	 * Check if an argument name follows MCP naming conventions.
	 *
	 * @param string $name The argument name to validate.
	 *
	 * @return bool True if valid, false otherwise.
	 */
	public static function validate_argument_name( string $name ): bool {
		// Argument names should not be empty
		if ( empty( $name ) ) {
			return false;
		}

		// Check length constraints (shorter than prompt names)
		if ( strlen( $name ) > 64 ) {
			return false;
		}

		// Only allow letters, numbers, hyphens, and underscores
		return (bool) preg_match( '/^[a-zA-Z0-9_-]+$/', $name );
	}

	/**
	 * Validate base64 content.
	 *
	 * @param string $content The content to validate as base64.
	 *
	 * @return bool True if valid base64, false otherwise.
	 */
	public static function validate_base64( string $content ): bool {
		// Base64 content should not be empty
		if ( empty( $content ) ) {
			return false;
		}

		// Check if it's valid base64 encoding
		return base64_decode( $content, true ) !== false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
	}

	/**
	 * Validate image MIME type.
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
	 * Validate ISO 8601 timestamp format.
	 *
	 * @param string $timestamp The timestamp to validate.
	 *
	 * @return bool True if valid ISO 8601 timestamp, false otherwise.
	 */
	public static function validate_iso8601_timestamp( string $timestamp ): bool {
		// Try to parse as DateTime with ISO 8601 format
		$datetime = \DateTime::createFromFormat( \DateTime::ATOM, $timestamp );
		if ( $datetime && $datetime->format( \DateTime::ATOM ) === $timestamp ) {
			return true;
		}

		// Try alternative ISO 8601 formats
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
}
