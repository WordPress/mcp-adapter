<?php
/**
 * Prompts method handlers for MCP requests.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Handlers\Prompts;

use WP\MCP\Core\McpServer;
use WP\MCP\Domain\Prompts\McpPrompt;
use WP\MCP\Handlers\HandlerHelperTrait;
use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;
use WP\McpSchema\Common\JsonRpc\JSONRPCErrorResponse;
use WP\McpSchema\Server\Prompts\GetPromptResult;
use WP\McpSchema\Server\Prompts\ListPromptsResult;
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
	 * @param int $request_id Optional. The request ID for JSON-RPC. Default 0.
	 *
	 * @return ListPromptsResult Response with prompts list DTO.
	 */
	public function list_prompts( int $request_id = 0 ): ListPromptsResult {
		$prompts = $this->mcp->get_prompts();

		// Convert domain prompts to DTOs.
		$prompt_dtos = array_values(
			array_map(
				static fn( McpPrompt $prompt ) => $prompt->to_schema_dto(),
				$prompts
			)
		);

		return new ListPromptsResult( $prompt_dtos );
	}

	/**
	 * Handles the prompts/get request.
	 *
	 * @param array $params     Request parameters.
	 * @param int   $request_id Optional. The request ID for JSON-RPC. Default 0.
	 *
	 * @return GetPromptResult|JSONRPCErrorResponse Response with prompt execution results or error.
	 */
	public function get_prompt( array $params, int $request_id = 0 ) {
		// Extract parameters using helper method.
		$request_params = $this->extract_params( $params );

		if ( ! isset( $request_params['name'] ) ) {
			return McpErrorFactory::missing_parameter( $request_id, 'name' );
		}

		// Get the prompt by name.
		$prompt_name = $request_params['name'];
		$prompt      = $this->mcp->get_prompt( $prompt_name );

		if ( ! $prompt ) {
			return McpErrorFactory::prompt_not_found( $request_id, $prompt_name );
		}

		// Get the arguments for the prompt.
		$arguments = $request_params['arguments'] ?? array();

		try {
			// Check if this is a builder-based prompt that can execute directly
			if ( $prompt->is_builder_based() ) {
				// Direct execution through the builder (bypasses abilities completely)
				// Note: Builder permission checks return bool only, not WP_Error
				$has_permission = $prompt->check_permission_direct( $arguments );
				if ( ! $has_permission ) {
					return McpErrorFactory::permission_denied( $request_id, 'Access denied for prompt: ' . $prompt_name );
				}

				$result = $prompt->execute_direct( $arguments );

				return $this->convert_result_to_dto( $result, $prompt );
			}

			/**
			 * Traditional ability-based execution
			 *
			 * Get the ability for the prompt.
			 *
			 * @var \WP_Ability|\WP_Error $ability
			 */
			$ability = $prompt->get_ability();

			// Check if getting the ability returned an error
			if ( is_wp_error( $ability ) ) {
				$this->mcp->error_handler->log(
					'Failed to get ability for prompt',
					array(
						'prompt_name'   => $prompt_name,
						'error_message' => $ability->get_error_message(),
					)
				);

				return McpErrorFactory::internal_error( $request_id, $ability->get_error_message() );
			}

			// If ability has no input schema and arguments is empty, pass null
			// This is required by WP_Ability::validate_input() which expects null when no schema
			$ability_input_schema = $ability->get_input_schema();
			if ( empty( $ability_input_schema ) && empty( $arguments ) ) {
				$arguments = null;
			}
			$has_permission = $ability->check_permissions( $arguments );
			if ( true !== $has_permission ) {
				// Extract detailed error message and code if WP_Error was returned
				$error_message = 'Access denied for prompt: ' . $prompt_name;

				if ( is_wp_error( $has_permission ) ) {
					$error_message = $has_permission->get_error_message();
				}

				return McpErrorFactory::permission_denied( $request_id, $error_message );
			}

			$result = $ability->execute( $arguments );

			// Handle WP_Error objects that weren't converted by the ability.
			if ( is_wp_error( $result ) ) {
				$this->mcp->error_handler->log(
					'Ability returned WP_Error object',
					array(
						'ability'       => $ability->get_name(),
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
	 * @param array    $result The result from ability execution.
	 * @param McpPrompt $prompt The prompt being executed.
	 *
	 * @return GetPromptResult
	 */
	private function convert_result_to_dto( array $result, McpPrompt $prompt ): GetPromptResult {
		// Check if result already has properly structured messages.
		if ( isset( $result['messages'] ) && is_array( $result['messages'] ) ) {
			$message_dtos = array_map(
				static function ( array $message ): PromptMessage {
					return PromptMessage::fromArray( $message );
				},
				$result['messages']
			);

			return new GetPromptResult(
				$message_dtos,
				null, // _meta
				$result['description'] ?? $prompt->get_description()
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

		return new GetPromptResult(
			$message_dtos,
			null, // _meta
			$prompt->get_description()
		);
	}
}
