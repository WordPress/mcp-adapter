<?php
/**
 * MCP Prompt Validator class for validating MCP prompts according to the specification.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Domain\Prompts;

use WP\MCP\Domain\Resources\McpResourceValidator;
use WP\MCP\Domain\Utils\McpValidator;
use WP\McpSchema\Server\Prompts\DTO\Prompt as PromptDto;
use WP_Error;

/**
 * Validates MCP prompts against the Model Context Protocol specification.
 *
 * Provides minimal, resource-efficient validation to ensure prompts conform
 * to the MCP schema requirements without heavy processing overhead.
 *
 * @link https://modelcontextprotocol.io/specification/2025-11-25/server/prompts
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
			return new WP_Error( 'mcp_prompt_validation_failed', esc_html( $error_message ) );
		}

		return true;
	}

	/**
	 * Validate a Prompt DTO against the MCP schema.
	 *
	 * @param \WP\McpSchema\Server\Prompts\DTO\Prompt $prompt The prompt DTO to validate.
	 *
	 * @return bool|\WP_Error True if valid, WP_Error otherwise.
	 */
	public static function validate_prompt_dto( PromptDto $prompt ) {
		$errors = array();

		// Validate name.
		if ( ! McpValidator::validate_name( $prompt->getName() ) ) {
			$errors[] = __( 'Prompt name must be 1-128 characters and contain only [A-Za-z0-9_.-]', 'mcp-adapter' );
		}

		// Validate icons if present.
		$icons = $prompt->getIcons();
		if ( ! empty( $icons ) ) {
			$icons_array  = array_map( static fn( $icon ) => $icon->toArray(), $icons );
			$icons_result = McpValidator::validate_icons_array( $icons_array );
			$icons_errors = self::format_icon_validation_errors( $icons_result );
			$errors       = array_merge( $errors, $icons_errors );
		}

		// Validate annotations if present (shared annotations).
		// Currently Prompt DTO doesn't have annotations field in spec, but if it did, we'd validate here.
		// BaseMetadata has title and name, handled separately.

		if ( ! empty( $errors ) ) {
			return new WP_Error(
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
	 * Validate an McpPrompt instance against the MCP schema.
	 *
	 * @param \WP\MCP\Domain\Prompts\McpPrompt $prompt The prompt instance to validate.
	 *
	 * @return bool|\WP_Error True if valid, WP_Error if validation fails.
	 */
	public static function validate_prompt_instance( McpPrompt $prompt ) {
		return self::validate_prompt_dto( $prompt->get_protocol_dto() );
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

		// Check required fields
		if ( empty( $prompt_data['name'] ) || ! is_string( $prompt_data['name'] ) || ! McpValidator::validate_name( $prompt_data['name'] ) ) {
			$errors[] = __( 'Prompt name is required and must be 1-128 characters and contain only [A-Za-z0-9_.-]', 'mcp-adapter' );
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

			// Validate argument name format (uses standard 128-char limit per MCP spec).
			if ( ! McpValidator::validate_name( $argument['name'] ) ) {
				$errors[] = sprintf(
				/* translators: %s: argument name */
					__( 'Prompt argument \'%s\' name must only contain letters, numbers, hyphens (-), underscores (_), and dots (.), and be 128 characters or less', 'mcp-adapter' ),
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
	 * Format icon validation errors from the validation result.
	 *
	 * @param array{valid: array, errors: array} $icons_result The result from validate_icons_array.
	 *
	 * @return array Array of formatted error messages.
	 */
	private static function format_icon_validation_errors( array $icons_result ): array {
		$errors = array();

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
				if ( ! isset( $content['text'] ) || ! is_string( $content['text'] ) ) {
					$errors[] = sprintf(
					/* translators: %d: message index */
						__( 'Message %d text content must have a text field', 'mcp-adapter' ),
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
				} elseif ( ! McpValidator::validate_base64( $content['data'] ) ) {
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
				} elseif ( ! McpValidator::validate_image_mime_type( $content['mimeType'] ) ) {
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
				} elseif ( ! McpValidator::validate_base64( $content['data'] ) ) {
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
				} elseif ( ! McpValidator::validate_audio_mime_type( $content['mimeType'] ) ) {
					$errors[] = sprintf(
					/* translators: %d: message index */
						__( 'Message %d audio content must have a valid audio MIME type', 'mcp-adapter' ),
						$message_index
					);
				}
				break;

			case 'resource_link':
				// ResourceLink is a metadata-only reference (same shape as Resource + type discriminator).
				if ( empty( $content['name'] ) || ! is_string( $content['name'] ) ) {
					$errors[] = sprintf(
					/* translators: %d: message index */
						__( 'Message %d resource_link content must have a name field', 'mcp-adapter' ),
						$message_index
					);
				}

				if ( empty( $content['uri'] ) || ! is_string( $content['uri'] ) ) {
					$errors[] = sprintf(
					/* translators: %d: message index */
						__( 'Message %d resource_link content must have a uri field', 'mcp-adapter' ),
						$message_index
					);
				} elseif ( ! McpValidator::validate_resource_uri( $content['uri'] ) ) {
					$errors[] = sprintf(
					/* translators: %d: message index */
						__( 'Message %d resource_link content uri must be a valid URI format', 'mcp-adapter' ),
						$message_index
					);
				}

				if ( isset( $content['mimeType'] ) ) {
					if ( ! is_string( $content['mimeType'] ) ) {
						$errors[] = sprintf(
						/* translators: %d: message index */
							__( 'Message %d resource_link content mimeType must be a string if provided', 'mcp-adapter' ),
							$message_index
						);
					} elseif ( ! McpValidator::validate_mime_type( $content['mimeType'] ) ) {
						$errors[] = sprintf(
						/* translators: %d: message index */
							__( 'Message %d resource_link content mimeType must be a valid MIME type format', 'mcp-adapter' ),
							$message_index
						);
					}
				}

				if ( isset( $content['size'] ) && ! is_int( $content['size'] ) ) {
					$errors[] = sprintf(
					/* translators: %d: message index */
						__( 'Message %d resource_link content size must be an integer if provided', 'mcp-adapter' ),
						$message_index
					);
				}

				if ( isset( $content['title'] ) && ! is_string( $content['title'] ) ) {
					$errors[] = sprintf(
					/* translators: %d: message index */
						__( 'Message %d resource_link content title must be a string if provided', 'mcp-adapter' ),
						$message_index
					);
				}

				if ( isset( $content['description'] ) && ! is_string( $content['description'] ) ) {
					$errors[] = sprintf(
					/* translators: %d: message index */
						__( 'Message %d resource_link content description must be a string if provided', 'mcp-adapter' ),
						$message_index
					);
				}

				if ( isset( $content['icons'] ) ) {
					if ( ! is_array( $content['icons'] ) ) {
						$errors[] = sprintf(
						/* translators: %d: message index */
							__( 'Message %d resource_link content icons must be an array if provided', 'mcp-adapter' ),
							$message_index
						);
					} else {
						$icons_result = McpValidator::validate_icons_array( $content['icons'], false );
						$errors       = array_merge( $errors, self::format_icon_validation_errors( $icons_result ) );
					}
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
					// Validate embedded resource using the resource validator for strict MCP compliance.
					$resource_errors = McpResourceValidator::get_validation_errors( $content['resource'] );
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
					__( 'Message %1$d content type \'%2$s\' is not supported. Must be \'text\', \'image\', \'audio\', \'resource\', or \'resource_link\'', 'mcp-adapter' ),
					$message_index,
					$type
				);
				break;
		}

		// Check optional annotations
		if ( isset( $content['annotations'] ) ) {
			if ( ! is_array( $content['annotations'] ) ) {
				$errors[] = sprintf(
				/* translators: %d: message index */
					__( 'Message %d content annotations must be an array if provided', 'mcp-adapter' ),
					$message_index
				);
			} else {
				$annotation_errors = McpValidator::get_annotation_validation_errors( $content['annotations'] );
				if ( ! empty( $annotation_errors ) ) {
					$errors = array_merge( $errors, $annotation_errors );
				}
			}
		}

		return $errors;
	}
}
