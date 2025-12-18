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
	use WP\McpSchema\Server\Prompts\GetPromptResult;
	use WP\McpSchema\Server\Prompts\ListPromptsResult;
	use WP\McpSchema\Server\Prompts\Prompt;
use WP\McpSchema\Server\Prompts\PromptMessage;

/**
 * Handles prompts-related MCP methods.
 */
class PromptsHandler {
	use HandlerHelperTrait;

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
	 * @param string|int|null $request_id Optional. The request ID for JSON-RPC. Default 0.
	 *
	 * @return \WP\McpSchema\Server\Prompts\ListPromptsResult Response with prompts list DTO.
	 */
	public function list_prompts( $request_id = 0 ): ListPromptsResult {
		$prompts = array_values( $this->mcp->get_prompts() );

		return ListPromptsResult::fromArray(
			array(
				'prompts' => $prompts,
			)
		);
	}

	/**
	 * Handles the prompts/get request.
	 *
	 * @param array $params     Request parameters.
	 * @param string|int|null $request_id Optional. The request ID for JSON-RPC. Default 0.
	 *
	 * @return \WP\McpSchema\Server\Prompts\GetPromptResult|\WP\McpSchema\Common\JsonRpc\JSONRPCErrorResponse Response with prompt execution results or error.
	 */
	public function get_prompt( array $params, $request_id = 0 ) {
		// Extract parameters using helper method.
		$request_params = $this->extract_params( $params );

		if ( ! isset( $request_params['name'] ) ) {
			return McpErrorFactory::missing_parameter( $request_id, 'name' );
		}

		$prompt_name = (string) $request_params['name'];
		$prompt_name = trim( $prompt_name );

		$prompt_wrapper = $this->mcp->get_prompt_wrapper( $prompt_name );

		if ( ! $prompt_wrapper ) {
			return McpErrorFactory::prompt_not_found( $request_id, $prompt_name );
		}

		/** @var \WP\McpSchema\Server\Prompts\Prompt $prompt */
		$prompt = $prompt_wrapper->get_component();

		// Get the arguments for the prompt.
		$arguments = $request_params['arguments'] ?? array();

		try {
			$permission = $prompt_wrapper->check_permission( $arguments );
			if ( true !== $permission ) {
				$error_message = 'Access denied for prompt: ' . $prompt_name;
				if ( is_wp_error( $permission ) ) {
					$error_message = $permission->get_error_message();
				}

				return McpErrorFactory::permission_denied( $request_id, $error_message );
			}

			$result = $prompt_wrapper->execute( $arguments );
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

			return $this->convert_result_to_dto( $result, $prompt );
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

	/**
	 * Converts the ability result to a GetPromptResult DTO.
	 *
	 * Handles both:
	 * 1. MCP-compliant results with 'messages' array
	 * 2. Builder prompts returning arbitrary data (wraps as text content)
	 *
	 * @param array $result The result from ability execution.
	 * @param \WP\McpSchema\Server\Prompts\Prompt $prompt Prompt DTO.
	 *
	 * @return \WP\McpSchema\Server\Prompts\GetPromptResult
	 */
	private function convert_result_to_dto( array $result, Prompt $prompt ): GetPromptResult {
		// Check if result already has properly structured messages.
		if ( isset( $result['messages'] ) && is_array( $result['messages'] ) ) {
			$message_dtos = array_map(
				static function ( array $message ): PromptMessage {
					return PromptMessage::fromArray( $message );
				},
				$result['messages']
			);

			return GetPromptResult::fromArray(
				array(
					'messages'    => $message_dtos,
					'description' => $result['description'] ?? $prompt->getDescription(),
				)
			);
		}

		// For builder prompts or non-compliant results, wrap the entire result as a text message.
		// This ensures backward compatibility with existing builder implementations.
		$json_content = wp_json_encode( $result, JSON_PRETTY_PRINT );
		if ( false === $json_content ) {
			$json_content = '{}';
		}

		$message_dtos = array(
			PromptMessage::fromArray(
				array(
					'role'    => 'assistant',
					'content' => array(
						'type' => 'text',
						'text' => $json_content,
					),
				)
			),
		);

		return GetPromptResult::fromArray(
			array(
				'messages'    => $message_dtos,
				'description' => $prompt->getDescription(),
			)
		);
	}
}
