<?php
/**
 * Prompts method handlers for MCP requests.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Handlers\Prompts;

use WP\MCP\Core\McpServer;
use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;

/**
 * Handles prompts-related MCP methods.
 */
class PromptsHandler {
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
	 * Check if user has permission to access prompts.
	 *
	 * Authorization is primarily handled at the transport level. For additional
	 * hardening, this handler can also enforce authentication when the
	 * `mcp_enforce_handler_auth` filter returns true.
	 *
	 * @return array|null Returns error if permission denied, null if allowed.
	 */
	private function check_permission(): ?array {
		$enforce_handler_auth = (bool) apply_filters( 'mcp_enforce_handler_auth', false );

		if ( $enforce_handler_auth && ! is_user_logged_in() ) {
			return array( 'error' => McpErrorFactory::unauthorized( 0, 'You must be logged in to access prompts.' )['error'] );
		}

		return null;
	}

	/**
	 * Handle the prompts/list request.
	 *
	 * @param int $request_id The request ID for JSON-RPC.
	 *
	 * @return array
	 */
	public function list_prompts( int $request_id = 0 ): array {
		$permission_error = $this->check_permission();
		if ( $permission_error ) {
			return $permission_error;
		}

		// Get the registered prompts from the MCP instance and extract only the args.
		$prompts = array();
		foreach ( $this->mcp->get_prompts() as $prompt ) {
			$prompts[] = $prompt->to_array();
		}

		return array(
			'prompts' => $prompts,
		);
	}

	/**
	 * Handle the prompts/get request.
	 *
	 * @param array $params     Request parameters.
	 * @param int   $request_id The request ID for JSON-RPC.
	 *
	 * @return array
	 */
	public function get_prompt( array $params, int $request_id = 0 ): array {
		// Handle both direct params and nested params structure.
		$request_params = $params['params'] ?? $params;

		if ( ! isset( $request_params['name'] ) ) {
			return array( 'error' => McpErrorFactory::missing_parameter( $request_id, 'name' )['error'] );
		}

		// Get the prompt by name.
		$prompt_name = $request_params['name'];
		$prompt      = $this->mcp->get_prompt( $prompt_name );

		if ( ! $prompt ) {
			return array( 'error' => McpErrorFactory::prompt_not_found( $request_id, $prompt_name )['error'] );
		}

		// Get the arguments for the prompt.
		$arguments = $request_params['arguments'] ?? array();

		try {
			// Check if this is a builder-based prompt that can execute directly
			if ( $prompt->is_builder_based() ) {
				// Direct execution through the builder (bypasses abilities completely)
				$has_permission = $prompt->check_permission_direct( $arguments );
				if ( ! $has_permission ) {
					return array( 'error' => McpErrorFactory::permission_denied( $request_id, 'Access denied for prompt: ' . $prompt_name )['error'] );
				}

				return $prompt->execute_direct( $arguments );
			}

			/**
			 * Traditional ability-based execution
			 *
			 * Assume non-builder based prompts can only be registered with valid abilities.
			 * If not, the has_permission() will let us know.
			 *
			 * @var \WP_Ability $ability
			 */
			$ability        = $prompt->get_ability();
			$has_permission = $ability->has_permission( $arguments );
			if ( true !== $has_permission ) {
				return array( 'error' => McpErrorFactory::permission_denied( $request_id, 'Access denied for prompt: ' . $prompt_name )['error'] );
			}

			return $ability->execute( $arguments );
		} catch ( \Throwable $e ) {
			if ( $this->mcp->error_handler ) {
				$this->mcp->error_handler->log(
					'Prompt execution failed',
					array(
						'prompt_name' => $prompt_name,
						'arguments'   => $arguments,
						'error'       => $e->getMessage(),
					)
				);
			}

			return array( 'error' => McpErrorFactory::internal_error( $request_id, 'Prompt execution failed' )['error'] );
		}
	}
}
