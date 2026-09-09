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
use WP\MCP\Handlers\HandlerHelperTrait;
use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;
use WP\McpSchema\Record\GetPromptRequest;
use WP\McpSchema\Record\ListPromptsRequest;
use WP\McpSchema\Record\Prompt;

/** Handles prompts/list and prompts/get. */
class PromptsHandler {
	use HandlerHelperTrait;

	/** @var string */
	private static string $default_role = 'user';

	/** @var \WP\MCP\Core\McpServer */
	private McpServer $mcp;

	/** Constructor. */
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
	 * Normalize supported prompt-result conveniences to canonical result data.
	 *
	 * Only the shape is normalized. Roles, content types, description, annotations
	 * and metadata are carried as given whenever they are set; an explicit null
	 * counts as absent, as everywhere else in the adapter. The schema decides
	 * whether the result fits, and a result that does not fit fails the request
	 * instead of being repaired. The registered prompt description fills in only
	 * when the result has none. An empty message list is emitted as given; the
	 * schema and the official client both accept it.
	 *
	 * @return array<string, mixed>
	 */
	private function normalize_result( array $result, Prompt $prompt, string $prompt_name ): array {
		$description = isset( $result['description'] ) ? $result['description'] : $prompt->getDescription();
		$messages    = array();

		if ( isset( $result['messages'] ) && is_array( $result['messages'] ) ) {
			foreach ( $result['messages'] as $message ) {
				$messages[] = is_array( $message ) ? $this->normalize_message( $message ) : $message;
			}
		} elseif ( isset( $result['text'] ) && is_string( $result['text'] ) ) {
			$content = array(
				'type' => 'text',
				'text' => $result['text'],
			);
			if ( isset( $result['annotations'] ) ) {
				$content['annotations'] = $result['annotations'];
			}
			$messages[] = array(
				'role'    => self::$default_role,
				'content' => $content,
			);
		} elseif ( isset( $result['role'], $result['content'] ) ) {
			$messages[] = $this->normalize_message( $result );
		} elseif ( isset( $result['texts'] ) && is_array( $result['texts'] ) ) {
			$role = $result['role'] ?? self::$default_role;
			foreach ( $result['texts'] as $text ) {
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

		$data = array( 'messages' => $messages );
		if ( null !== $description ) {
			$data['description'] = $description;
		}
		if ( isset( $result['_meta'] ) ) {
			$data['_meta'] = $result['_meta'];
		}

		return $data;
	}

	/**
	 * Fill in the message defaults: an absent role is `user`, and a plain string
	 * content is a text block. Everything else is carried as given.
	 *
	 * @return array<string, mixed>
	 */
	private function normalize_message( array $message ): array {
		$content = $message['content'] ?? array();
		if ( is_string( $content ) ) {
			$content = array(
				'type' => 'text',
				'text' => $content,
			);
		}

		return array(
			'role'    => $message['role'] ?? self::$default_role,
			'content' => $content,
		);
	}
}
