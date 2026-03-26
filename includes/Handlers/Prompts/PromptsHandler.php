<?php
/**
 * Prompts method handlers for MCP requests.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Handlers\Prompts;

use WP\MCP\Core\McpServer;
use WP\MCP\Handlers\HandlerHelperTrait;
use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;
use WP\McpSchema\Server\Prompts\DTO\GetPromptResult;
use WP\McpSchema\Server\Prompts\DTO\ListPromptsResult;
use WP\McpSchema\Server\Prompts\DTO\Prompt as PromptDto;
use WP\McpSchema\Server\Prompts\DTO\PromptMessage;

/**
 * Handles prompts-related MCP methods.
 *
 * @since n.e.x.t
 */
class PromptsHandler {
	use HandlerHelperTrait;

	/**
	 * Valid content types from ContentBlockFactory.
	 *
	 * @var list<string>
	 */
	private static $valid_content_types = array( 'text', 'image', 'audio', 'resource_link', 'resource' );

	/**
	 * Valid role values for PromptMessage.
	 *
	 * @var list<string>
	 */
	private static $valid_roles = array( 'user', 'assistant' );

	/**
	 * Default role for messages when not specified.
	 *
	 * @var string
	 */
	private static $default_role = 'user';

	/**
	 * The WordPress MCP instance.
	 *
	 * @var \WP\MCP\Core\McpServer
	 */
	private McpServer $mcp;

	/**
	 * Constructor.
	 *
	 * @param \WP\MCP\Core\McpServer $mcp The WordPress MCP instance.
	 */
	public function __construct( McpServer $mcp ) {
		$this->mcp = $mcp;
	}

	/**
	 * Handles the prompts/list request.
	 *
	 * @return \WP\McpSchema\Server\Prompts\DTO\ListPromptsResult Response with prompts list DTO.
	 */
	public function list_prompts(): ListPromptsResult {
		$prompts = array_values( $this->mcp->get_prompts() );

		/**
		 * Filters the list of prompts before returning to the client.
		 *
		 * Use this filter to filter prompts by context, add dynamic prompts,
		 * or reorder the prompts list.
		 *
		 * @since n.e.x.t
		 *
		 * @param array<\WP\McpSchema\Server\Prompts\DTO\Prompt> $prompts Array of Prompt DTOs.
		 * @param \WP\MCP\Core\McpServer                         $server  The MCP server instance.
		 */
		$prompts = $this->validate_filtered_list(
			apply_filters( 'mcp_adapter_prompts_list', $prompts, $this->mcp ),
			$prompts,
			'mcp_adapter_prompts_list',
			$this->mcp->error_handler
		);

		return ListPromptsResult::fromArray(
			array(
				'prompts' => $prompts,
			)
		);
	}

	/**
	 * Handles the prompts/get request.
	 *
	 * @param array           $params Request parameters.
	 * @param string|int|null $request_id Optional. The request ID for JSON-RPC. Default 0.
	 *
	 * @return \WP\McpSchema\Server\Prompts\DTO\GetPromptResult|\WP\McpSchema\Common\JsonRpc\DTO\JSONRPCErrorResponse Response with prompt execution results or error.
	 */
	public function get_prompt( array $params, $request_id = 0 ) {
		// Extract parameters using helper method.
		$request_params = $this->extract_params( $params );

		if ( ! isset( $request_params['name'] ) ) {
			return McpErrorFactory::missing_parameter( $request_id, 'name' );
		}

		$prompt_name = (string) $request_params['name'];
		$prompt_name = trim( $prompt_name );

		if ( isset( $request_params['arguments'] ) && ! is_array( $request_params['arguments'] ) ) {
			return McpErrorFactory::invalid_params( $request_id, 'arguments must be an object' );
		}

		$mcp_prompt = $this->mcp->get_mcp_prompt( $prompt_name );

		if ( ! $mcp_prompt ) {
			return McpErrorFactory::prompt_not_found( $request_id, $prompt_name );
		}

		/** @var \WP\McpSchema\Server\Prompts\DTO\Prompt $prompt */
		$prompt = $mcp_prompt->get_protocol_dto();

		// Get the arguments for the prompt.
		$arguments = $request_params['arguments'] ?? array();

		try {
			$permission = $mcp_prompt->check_permission( $arguments );
			if ( true !== $permission ) {
				$error_message = 'Access denied for prompt: ' . $prompt_name;
				if ( is_wp_error( $permission ) ) {
					$error_message = $permission->get_error_message();
				}

				return McpErrorFactory::permission_denied( $request_id, $error_message );
			}

			/**
			 * Filters prompt arguments before execution, or short-circuits execution entirely.
			 *
			 * Return the (optionally modified) arguments array to proceed with execution,
			 * or return a WP_Error to block execution and return an error to the client.
			 *
			 * @since n.e.x.t
			 *
			 * @param array                              $arguments   The prompt arguments.
			 * @param string                             $prompt_name The prompt name being retrieved.
			 * @param \WP\MCP\Domain\Prompts\McpPrompt   $mcp_prompt  The MCP prompt instance.
			 * @param \WP\MCP\Core\McpServer             $server      The MCP server instance.
			 */
			$arguments = apply_filters( 'mcp_adapter_pre_prompt_get', $arguments, $prompt_name, $mcp_prompt, $this->mcp );

			// Allow pre-filter to short-circuit execution by returning WP_Error.
			if ( is_wp_error( $arguments ) ) {
				return McpErrorFactory::internal_error( $request_id, $arguments->get_error_message() );
			}

			$result = $mcp_prompt->execute( $arguments );

			/**
			 * Filters the prompt execution result before normalization.
			 *
			 * Use this filter for message transformation, context injection,
			 * content enrichment, or audit logging.
			 *
			 * @since n.e.x.t
			 *
			 * @param mixed|\WP_Error                    $result      The raw execution result (may be WP_Error).
			 * @param array                              $arguments   The prompt arguments used.
			 * @param string                             $prompt_name The prompt name.
			 * @param \WP\MCP\Domain\Prompts\McpPrompt   $mcp_prompt  The MCP prompt instance.
			 * @param \WP\MCP\Core\McpServer             $server      The MCP server instance.
			 */
			$result = apply_filters( 'mcp_adapter_prompt_get_result', $result, $arguments, $prompt_name, $mcp_prompt, $this->mcp );

			if ( is_wp_error( $result ) ) {
				$this->mcp->error_handler->log(
					'Prompt execution returned WP_Error',
					array(
						'prompt_name'   => $prompt_name,
						'error_code'    => $result->get_error_code(),
						'error_message' => $result->get_error_message(),
					)
				);

				return McpErrorFactory::internal_error( $request_id, $result->get_error_message() );
			}

			return $this->normalize_result_to_dto( $result, $prompt, $prompt_name );
		} catch ( \Throwable $e ) {
			$this->mcp->error_handler->log(
				'Prompt execution failed',
				array(
					'prompt_name' => $prompt_name,
					'arguments'   => $arguments,
					'error'       => $e->getMessage(),
				)
			);

			return McpErrorFactory::internal_error( $request_id, 'Prompt execution failed' );
		}
	}

	// =========================================================================
	// Result Normalization (Tiered Convenience Shortcuts)
	// =========================================================================

	/**
	 * Normalize and convert prompt execution result to GetPromptResult DTO.
	 *
	 * Supports tiered return formats:
	 * - Tier 1: Full MCP format with 'messages' array
	 * - Tier 2: Simple 'text' shorthand
	 * - Tier 3: Single message with 'role' and 'content'
	 * - Tier 4: Multi-text with 'texts' array
	 * - Tier 5: Fallback JSON encoding for arbitrary data
	 *
	 * @since n.e.x.t
	 *
	 * @param array                                 $result      Raw result from prompt execution.
	 * @param \WP\McpSchema\Server\Prompts\DTO\Prompt $prompt      The prompt DTO for description fallback.
	 * @param string                                $prompt_name Prompt name for logging.
	 *
	 * @return \WP\McpSchema\Server\Prompts\DTO\GetPromptResult
	 */
	private function normalize_result_to_dto(
		array $result,
		PromptDto $prompt,
		string $prompt_name
	): GetPromptResult {
		// Tier 1: Full MCP format with 'messages' array.
		if ( isset( $result['messages'] ) && is_array( $result['messages'] ) ) {
			return $this->normalize_tier1_messages( $result, $prompt, $prompt_name );
		}

		// Tier 2: Simple 'text' shorthand.
		if ( isset( $result['text'] ) && is_string( $result['text'] ) ) {
			return $this->normalize_tier2_text( $result, $prompt );
		}

		// Tier 3: Single message with 'role' key.
		if ( isset( $result['role'] ) && isset( $result['content'] ) ) {
			return $this->normalize_tier3_single_message( $result, $prompt, $prompt_name );
		}

		// Tier 4: Multi-text with 'texts' array.
		if ( isset( $result['texts'] ) && is_array( $result['texts'] ) ) {
			return $this->normalize_tier4_texts( $result, $prompt );
		}

		// Tier 5: Fallback - JSON encode arbitrary data.
		return $this->normalize_tier5_fallback( $result, $prompt, $prompt_name );
	}

	/**
	 * Tier 1: Full MCP-compliant format with 'messages' array.
	 *
	 * @since n.e.x.t
	 *
	 * @param array                                 $result      Raw result with 'messages' key.
	 * @param \WP\McpSchema\Server\Prompts\DTO\Prompt $prompt      The prompt DTO.
	 * @param string                                $prompt_name Prompt name for logging.
	 *
	 * @return \WP\McpSchema\Server\Prompts\DTO\GetPromptResult
	 */
	private function normalize_tier1_messages(
		array $result,
		PromptDto $prompt,
		string $prompt_name
	): GetPromptResult {
		$message_dtos = array();

		foreach ( $result['messages'] as $index => $message ) {
			if ( ! is_array( $message ) ) {
				$this->mcp->error_handler->log(
					'Invalid message structure in prompt result, skipping',
					array(
						'prompt_name'   => $prompt_name,
						'message_index' => $index,
						'message_type'  => gettype( $message ),
					),
					'warning'
				);
				continue;
			}

			$message_dtos[] = $this->validate_and_create_message( $message, $prompt_name );
		}

		// Ensure we have at least one message.
		if ( empty( $message_dtos ) ) {
			$message_dtos[] = PromptMessage::fromArray(
				array(
					'role'    => self::$default_role,
					'content' => array(
						'type' => 'text',
						'text' => '(No messages returned)',
					),
				)
			);
		}

		return GetPromptResult::fromArray(
			array(
				'messages'    => $message_dtos,
				'description' => $result['description'] ?? $prompt->getDescription(),
			)
		);
	}

	/**
	 * Tier 2: Simple 'text' shorthand.
	 *
	 * Creates a single user message with text content.
	 *
	 * @since n.e.x.t
	 *
	 * @param array                                 $result Raw result with 'text' key.
	 * @param \WP\McpSchema\Server\Prompts\DTO\Prompt $prompt The prompt DTO.
	 *
	 * @return \WP\McpSchema\Server\Prompts\DTO\GetPromptResult
	 */
	private function normalize_tier2_text( array $result, PromptDto $prompt ): GetPromptResult {
		$content = array(
			'type' => 'text',
			'text' => (string) $result['text'],
		);

		// Support optional annotations on the text.
		if ( isset( $result['annotations'] ) && is_array( $result['annotations'] ) ) {
			$content['annotations'] = $result['annotations'];
		}

		$message_dto = PromptMessage::fromArray(
			array(
				'role'    => self::$default_role,
				'content' => $content,
			)
		);

		return GetPromptResult::fromArray(
			array(
				'messages'    => array( $message_dto ),
				'description' => $result['description'] ?? $prompt->getDescription(),
			)
		);
	}

	/**
	 * Tier 3: Single message with 'role' and 'content'.
	 *
	 * @since n.e.x.t
	 *
	 * @param array                                 $result      Raw result with 'role' and 'content' keys.
	 * @param \WP\McpSchema\Server\Prompts\DTO\Prompt $prompt      The prompt DTO.
	 * @param string                                $prompt_name Prompt name for logging.
	 *
	 * @return \WP\McpSchema\Server\Prompts\DTO\GetPromptResult
	 */
	private function normalize_tier3_single_message(
		array $result,
		PromptDto $prompt,
		string $prompt_name
	): GetPromptResult {
		$message_dto = $this->validate_and_create_message( $result, $prompt_name );

		return GetPromptResult::fromArray(
			array(
				'messages'    => array( $message_dto ),
				'description' => $result['description'] ?? $prompt->getDescription(),
			)
		);
	}

	/**
	 * Tier 4: Multi-text with 'texts' array.
	 *
	 * Creates multiple messages with the same role.
	 *
	 * @since n.e.x.t
	 *
	 * @param array                                 $result Raw result with 'texts' key.
	 * @param \WP\McpSchema\Server\Prompts\DTO\Prompt $prompt The prompt DTO.
	 *
	 * @return \WP\McpSchema\Server\Prompts\DTO\GetPromptResult
	 */
	private function normalize_tier4_texts( array $result, PromptDto $prompt ): GetPromptResult {
		$role         = $this->validate_role( $result['role'] ?? self::$default_role, '' );
		$message_dtos = array();

		foreach ( $result['texts'] as $text ) {
			if ( ! is_string( $text ) ) {
				continue;
			}

			$message_dtos[] = PromptMessage::fromArray(
				array(
					'role'    => $role,
					'content' => array(
						'type' => 'text',
						'text' => $text,
					),
				)
			);
		}

		// Ensure we have at least one message.
		if ( empty( $message_dtos ) ) {
			$message_dtos[] = PromptMessage::fromArray(
				array(
					'role'    => $role,
					'content' => array(
						'type' => 'text',
						'text' => '(No texts provided)',
					),
				)
			);
		}

		return GetPromptResult::fromArray(
			array(
				'messages'    => $message_dtos,
				'description' => $result['description'] ?? $prompt->getDescription(),
			)
		);
	}

	/**
	 * Tier 5: Fallback - JSON encode arbitrary data.
	 *
	 * Used when no other tier matches. Logs an observability event.
	 *
	 * @since n.e.x.t
	 *
	 * @param array                                 $result      Raw result (arbitrary structure).
	 * @param \WP\McpSchema\Server\Prompts\DTO\Prompt $prompt      The prompt DTO.
	 * @param string                                $prompt_name Prompt name for logging.
	 *
	 * @return \WP\McpSchema\Server\Prompts\DTO\GetPromptResult
	 */
	private function normalize_tier5_fallback(
		array $result,
		PromptDto $prompt,
		string $prompt_name
	): GetPromptResult {
		// Log observability event for fallback normalization.
		$this->mcp->observability_handler->record_event(
			'prompt_result_fallback_normalization',
			array(
				'prompt_name' => $prompt_name,
				'result_keys' => array_keys( $result ),
			)
		);

		$json_content = wp_json_encode( $result, JSON_PRETTY_PRINT );
		if ( false === $json_content ) {
			$json_content = '{}';
		}

		$message_dto = PromptMessage::fromArray(
			array(
				'role'    => self::$default_role,
				'content' => array(
					'type' => 'text',
					'text' => $json_content,
				),
			)
		);

		return GetPromptResult::fromArray(
			array(
				'messages'    => array( $message_dto ),
				'description' => $prompt->getDescription(),
			)
		);
	}

	// =========================================================================
	// Validation Helpers
	// =========================================================================

	/**
	 * Validate message structure and create PromptMessage DTO.
	 *
	 * Validates role and content type, applying defaults where needed.
	 *
	 * @since n.e.x.t
	 *
	 * @param array  $message     Raw message array.
	 * @param string $prompt_name Prompt name for logging.
	 *
	 * @return \WP\McpSchema\Server\Prompts\DTO\PromptMessage
	 */
	private function validate_and_create_message( array $message, string $prompt_name ): PromptMessage {
		// Validate and normalize role.
		$role = $this->validate_role( $message['role'] ?? self::$default_role, $prompt_name );

		// Validate and normalize content.
		$content = $message['content'] ?? array();
		if ( ! is_array( $content ) ) {
			// If content is a string, wrap it as text.
			$content = array(
				'type' => 'text',
				'text' => is_string( $content ) ? $content : (string) $content,
			);
		}

		$content = $this->validate_content_type( $content, $prompt_name );

		return PromptMessage::fromArray(
			array(
				'role'    => $role,
				'content' => $content,
			)
		);
	}

	/**
	 * Validate content type against ContentBlockFactory registry.
	 *
	 * @since n.e.x.t
	 *
	 * @param array  $content     Content array with 'type' key.
	 * @param string $prompt_name Prompt name for logging.
	 *
	 * @return array Validated content array (may be modified if invalid type).
	 */
	private function validate_content_type( array $content, string $prompt_name ): array {
		$type = $content['type'] ?? null;

		// Check if type is missing.
		if ( null === $type || '' === $type ) {
			$this->mcp->error_handler->log(
				'Missing content type in prompt result, defaulting to text',
				array(
					'prompt_name' => $prompt_name,
				),
				'warning'
			);

			$text = isset( $content['text'] ) ? (string) $content['text'] : wp_json_encode( $content, JSON_PRETTY_PRINT );

			return array(
				'type' => 'text',
				'text' => false === $text ? '{}' : $text,
			);
		}

		// Check if type is valid.
		if ( ! in_array( $type, self::$valid_content_types, true ) ) {
			$this->mcp->error_handler->log(
				'Invalid content type in prompt result, converting to text',
				array(
					'prompt_name'  => $prompt_name,
					'invalid_type' => $type,
					'valid_types'  => self::$valid_content_types,
				),
				'warning'
			);

			// Convert the entire content to a text representation.
			$json_content = wp_json_encode( $content, JSON_PRETTY_PRINT );
			if ( false === $json_content ) {
				$json_content = '{}';
			}

			return array(
				'type' => 'text',
				'text' => $json_content,
			);
		}

		// Type is valid, return content as-is (preserves annotations).
		return $content;
	}

	/**
	 * Validate role value and apply default if invalid.
	 *
	 * @since n.e.x.t
	 *
	 * @param string $role        Role value to validate.
	 * @param string $prompt_name Prompt name for logging (empty to skip logging).
	 *
	 * @return string Valid role value.
	 */
	private function validate_role( string $role, string $prompt_name ): string {
		if ( in_array( $role, self::$valid_roles, true ) ) {
			return $role;
		}

		if ( '' !== $prompt_name ) {
			$this->mcp->error_handler->log(
				'Invalid role in prompt message, defaulting to user',
				array(
					'prompt_name'  => $prompt_name,
					'invalid_role' => $role,
					'valid_roles'  => self::$valid_roles,
				),
				'warning'
			);
		}

		return self::$default_role;
	}
}
