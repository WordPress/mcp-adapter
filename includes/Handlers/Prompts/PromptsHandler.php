<?php
/**
 * Prompt method handlers.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Handlers\Prompts;

use WP\MCP\Core\McpRequestContext;
use WP\MCP\Core\McpServer;
use WP\MCP\Domain\Utils\McpValidator;
use WP\MCP\Handlers\HandlerHelperTrait;
use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;
use WP\McpSchema\Record\GetPromptRequest;
use WP\McpSchema\Record\ListPromptsRequest;
use WP\McpSchema\Record\Prompt;

/**
 * Lists projected prompts and executes prompts through their domain models.
 *
 * Returns logical result data for final revision-specific schema validation.
 */
class PromptsHandler {
	use HandlerHelperTrait;

	/**
	 * Supported prompt content discriminators.
	 *
	 * @var list<string>
	 */
	private static array $valid_content_types = array( 'text', 'image', 'audio', 'resource_link', 'resource' );

	/**
	 * Accepted roles for prompt messages.
	 *
	 * @var list<string>
	 */
	private static array $valid_roles = array( 'user', 'assistant' );

	/**
	 * Role used when a message omits its role or supplies an unsupported one.
	 *
	 * @var string
	 */
	private static string $default_role = 'user';

	/**
	 * Server used for prompt lookup, execution diagnostics, and observability.
	 *
	 * @var \WP\MCP\Core\McpServer
	 */
	private McpServer $mcp;

	/**
	 * Initialize the handler for one server.
	 *
	 * @param \WP\MCP\Core\McpServer $mcp Server providing prompt lookup and diagnostics.
	 */
	public function __construct( McpServer $mcp ) {
		$this->mcp = $mcp;
	}

	/**
	 * Handle prompts/list.
	 *
	 * @param \WP\McpSchema\Record\ListPromptsRequest $request Validated request.
	 * @param \WP\MCP\Core\McpRequestContext $request_context Exact request context.
	 * @return array<string, mixed> Logical prompts-list result.
	 * @since n.e.x.t
	 */
	public function list_prompts( ListPromptsRequest $request, McpRequestContext $request_context ): array {
		unset( $request );
		$schema  = $request_context->schema();
		$prompts = array_values( $this->mcp->get_prompts( $schema ) );

		/**
		 * Filters the list of prompts before returning to the client.
		 *
		 * @since 0.5.0
		 *
		 * @param array<\WP\McpSchema\Record\Prompt> $prompts Prompt records.
		 * @param \WP\MCP\Core\McpServer             $server  MCP server.
		 * @param \WP\McpSchema\Schema                $schema  Selected schema.
		 */
		$prompts = $this->validate_filtered_list(
			apply_filters( 'mcp_adapter_prompts_list', $prompts, $this->mcp, $schema ),
			$prompts,
			'mcp_adapter_prompts_list',
			$this->mcp->get_error_handler()
		);

		return array( 'prompts' => $prompts );
	}

	/**
	 * Handle prompts/get.
	 *
	 * @param \WP\McpSchema\Record\GetPromptRequest $request Validated request.
	 * @param \WP\MCP\Core\McpRequestContext $request_context Exact context.
	 * @return array<string, mixed>
	 * @since n.e.x.t
	 */
	public function get_prompt( GetPromptRequest $request, McpRequestContext $request_context ): array {
		$request_params = $request->getParams();
		$request_id     = $request->getId();
		$prompt_name    = trim( $request_params->getName() );

		$mcp_prompt = $this->mcp->get_mcp_prompt( $prompt_name );
		if ( ! $mcp_prompt || ! $mcp_prompt->is_available_for( $request_context->schema() ) ) {
			return McpErrorFactory::prompt_not_found( $request_id, $prompt_name );
		}

		$prompt    = $mcp_prompt->get_protocol_record( $request_context->schema() );
		$arguments = $this->callback_arguments( $request_params->getArguments() );

		try {
			$permission = $mcp_prompt->check_permission( $arguments );
			if ( true !== $permission ) {
				$message = is_wp_error( $permission ) ? $permission->get_error_message() : 'Access denied for prompt: ' . $prompt_name;
				return McpErrorFactory::permission_denied( $request_id, $message );
			}

			/**
			 * Filters prompt arguments before execution.
			 *
			 * @since 0.5.0
			 *
			 * @param array $arguments Prompt arguments; return WP_Error to stop execution.
			 * @param string $prompt_name Requested prompt name.
			 * @param \WP\MCP\Domain\Prompts\McpPrompt $mcp_prompt Prompt execution component.
			 * @param \WP\MCP\Core\McpServer $server Server owning the prompt.
			 */
			$arguments = apply_filters( 'mcp_adapter_pre_prompt_get', $arguments, $prompt_name, $mcp_prompt, $this->mcp );
			if ( is_wp_error( $arguments ) ) {
				return McpErrorFactory::internal_error( $request_id, $arguments->get_error_message() );
			}

			$result = $mcp_prompt->execute( $arguments );

			/**
			 * Filters the prompt execution result before normalization.
			 *
			 * @since 0.5.0
			 *
			 * @param mixed|\WP_Error $result Raw execution result or error.
			 * @param array $arguments Arguments used for execution.
			 * @param string $prompt_name Requested prompt name.
			 * @param \WP\MCP\Domain\Prompts\McpPrompt $mcp_prompt Prompt execution component.
			 * @param \WP\MCP\Core\McpServer $server Server owning the prompt.
			 */
			$result = apply_filters( 'mcp_adapter_prompt_get_result', $result, $arguments, $prompt_name, $mcp_prompt, $this->mcp );
			if ( is_wp_error( $result ) ) {
				$this->mcp->get_error_handler()->log(
					'Prompt execution returned WP_Error',
					array(
						'prompt_name'   => $prompt_name,
						'error_code'    => $result->get_error_code(),
						'error_message' => $result->get_error_message(),
					)
				);

				return McpErrorFactory::internal_error( $request_id, $result->get_error_message() );
			}

			$result = is_array( $result ) ? $result : array( 'result' => $result );
			return $this->normalize_result( $result, $prompt, $prompt_name );
		} catch ( \Throwable $throwable ) {
			$this->mcp->get_error_handler()->log(
				'Prompt execution failed',
				array(
					'prompt_name' => $prompt_name,
					'arguments'   => $arguments,
					'error'       => $throwable->getMessage(),
				)
			);

			return McpErrorFactory::internal_error( $request_id, 'Prompt execution failed' );
		}
	}

	/**
	 * Convert supported prompt result forms into logical message data.
	 *
	 * Accepts message lists, text shortcuts, or a single role/content pair. Other
	 * arrays become JSON text; empty message output receives a placeholder.
	 *
	 * @param array<string, mixed> $result Callback result after filtering.
	 * @param \WP\McpSchema\Record\Prompt $prompt Projected prompt definition supplying default description.
	 * @param string $prompt_name Prompt identifier for diagnostics.
	 *
	 * @return array<string, mixed> Prompt result with messages and optional description.
	 */
	private function normalize_result( array $result, Prompt $prompt, string $prompt_name ): array {
		$description = isset( $result['description'] ) && is_string( $result['description'] ) ? $result['description'] : $prompt->getDescription();
		$messages    = array();

		if ( isset( $result['messages'] ) && is_array( $result['messages'] ) ) {
			foreach ( $result['messages'] as $index => $message ) {
				if ( ! is_array( $message ) ) {
					$this->mcp->get_error_handler()->log(
						'Invalid message structure in prompt result, skipping',
						array(
							'prompt_name'   => $prompt_name,
							'message_index' => $index,
						),
						'warning'
					);
					continue;
				}

				$messages[] = $this->normalize_message( $message, $prompt_name );
			}
		} elseif ( isset( $result['text'] ) && is_string( $result['text'] ) ) {
			$content = array(
				'type' => 'text',
				'text' => $result['text'],
			);
			if ( isset( $result['annotations'] ) && is_array( $result['annotations'] ) ) {
				$content['annotations'] = $result['annotations'];
			}
			$messages[] = array(
				'role'    => self::$default_role,
				'content' => $content,
			);
		} elseif ( isset( $result['role'], $result['content'] ) ) {
			$messages[] = $this->normalize_message( $result, $prompt_name );
		} elseif ( isset( $result['texts'] ) && is_array( $result['texts'] ) ) {
			$role = $this->validate_role( $result['role'] ?? self::$default_role, $prompt_name );
			foreach ( $result['texts'] as $text ) {
				if ( ! is_string( $text ) ) {
					continue;
				}

				$messages[] = array(
					'role'    => $role,
					'content' => array(
						'type' => 'text',
						'text' => $text,
					),
				);
			}
		} else {
			$this->mcp->get_observability_handler()->record_event(
				'prompt_result_fallback_normalization',
				array(
					'prompt_name' => $prompt_name,
					'result_keys' => array_keys( $result ),
				)
			);
			$text       = wp_json_encode( $result, JSON_PRETTY_PRINT );
			$messages[] = array(
				'role'    => self::$default_role,
				'content' => array(
					'type' => 'text',
					'text' => false === $text ? '{}' : $text,
				),
			);
		}

		if ( empty( $messages ) ) {
			$messages[] = array(
				'role'    => self::$default_role,
				'content' => array(
					'type' => 'text',
					'text' => '(No messages returned)',
				),
			);
		}

		$data = array( 'messages' => $messages );
		if ( null !== $description ) {
			$data['description'] = $description;
		}

		return $data;
	}

	/**
	 * Normalize one message role and content block.
	 *
	 * @param array<string, mixed> $message Message data from the callback.
	 * @param string $prompt_name Prompt identifier for diagnostics.
	 *
	 * @return array<string, mixed> Message with a supported role and normalized content.
	 */
	private function normalize_message( array $message, string $prompt_name ): array {
		$role    = $this->validate_role( $message['role'] ?? self::$default_role, $prompt_name );
		$content = $message['content'] ?? array();
		if ( ! is_array( $content ) ) {
			$content = array(
				'type' => 'text',
				'text' => (string) $content,
			);
		}

		return array(
			'role'    => $role,
			'content' => $this->normalize_content_block( $this->validate_content_type( $content, $prompt_name ) ),
		);
	}

	/**
	 * Normalize metadata on a content block and an embedded resource.
	 *
	 * @since 0.6.0
	 *
	 * @param array<string, mixed> $content Content block to normalize.
	 *
	 * @return array<string, mixed> Block with absent or invalid metadata removed.
	 */
	private function normalize_content_block( array $content ): array {
		$block_meta = McpValidator::normalize_meta( $content['_meta'] ?? null );
		if ( null === $block_meta ) {
			unset( $content['_meta'] );
		} else {
			$content['_meta'] = $block_meta;
		}

		if ( 'resource' === ( $content['type'] ?? '' ) && isset( $content['resource'] ) && is_array( $content['resource'] ) ) {
			$resource      = $content['resource'];
			$resource_meta = McpValidator::normalize_meta( $resource['_meta'] ?? null );
			if ( null === $resource_meta ) {
				unset( $resource['_meta'] );
			} else {
				$resource['_meta'] = $resource_meta;
			}
			$content['resource'] = $resource;
		}

		return $content;
	}

	/**
	 * Keep a supported content type or convert the block to text.
	 *
	 * @since 0.5.0
	 *
	 * @param array<string, mixed> $content Candidate content block.
	 * @param string $prompt_name Prompt identifier included in warnings.
	 *
	 * @return array<string, mixed> Original supported block or a text fallback.
	 */
	private function validate_content_type( array $content, string $prompt_name ): array {
		$type = $content['type'] ?? null;
		if ( is_string( $type ) && in_array( $type, self::$valid_content_types, true ) ) {
			return $content;
		}

		$this->mcp->get_error_handler()->log(
			'Invalid content type in prompt result, converting to text',
			array(
				'prompt_name'  => $prompt_name,
				'invalid_type' => $type,
			),
			'warning'
		);

		$text = isset( $content['text'] ) ? (string) $content['text'] : wp_json_encode( $content, JSON_PRETTY_PRINT );
		return array(
			'type' => 'text',
			'text' => false === $text ? '{}' : $text,
		);
	}

	/**
	 * Keep a supported role or fall back to the default user role.
	 *
	 * @since 0.5.0
	 *
	 * @param string $role Candidate message role.
	 * @param string $prompt_name Prompt identifier included in warnings.
	 *
	 * @return string Supported role.
	 */
	private function validate_role( string $role, string $prompt_name ): string {
		if ( in_array( $role, self::$valid_roles, true ) ) {
			return $role;
		}

		$this->mcp->get_error_handler()->log(
			'Invalid role in prompt message, defaulting to user',
			array(
				'prompt_name'  => $prompt_name,
				'invalid_role' => $role,
			),
			'warning'
		);

		return self::$default_role;
	}
}
