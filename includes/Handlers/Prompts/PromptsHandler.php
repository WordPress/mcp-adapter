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
	 * Handle the prompts/list request.
	 *
	 * @param int $request_id The request ID for JSON-RPC.
	 *
	 * @return array
	 */
	public function list_prompts( int $request_id = 0 ): array {
		// Get the registered prompts from the MCP instance and extract only the args.
		$prompts = array();
		foreach ( $this->mcp->get_prompts() as $prompt ) {
			$prompts[] = $prompt->to_array();
		}

		return array(
			'prompts'   => $prompts,
			'_metadata' => array(
				'component_type' => 'prompts',
				'prompts_count'  => count( $prompts ),
			),
		);
	}

	/**
	 * Handle the prompts/get request.
	 *
	 * @param array $params Request parameters.
	 * @param int $request_id The request ID for JSON-RPC.
	 *
	 * @return array
	 */
	public function get_prompt( array $params, int $request_id = 0 ): array {
		// Extract parameters using helper method.
		$request_params = $this->extract_params( $params );

		if ( ! isset( $request_params['name'] ) ) {
			return array(
				'error'     => McpErrorFactory::missing_parameter( $request_id, 'name' )['error'],
				'_metadata' => array(
					'component_type' => 'prompt',
					'failure_reason' => 'missing_parameter',
				),
			);
		}

		// Get the prompt by name.
		$prompt_name = $request_params['name'];
		$prompt      = $this->mcp->get_prompt( $prompt_name );

		if ( ! $prompt ) {
			return array(
				'error'     => McpErrorFactory::prompt_not_found( $request_id, $prompt_name )['error'],
				'_metadata' => array(
					'component_type' => 'prompt',
					'prompt_name'    => $prompt_name,
					'failure_reason' => 'not_found',
				),
			);
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
					return array(
						'error'     => McpErrorFactory::permission_denied( $request_id, 'Access denied for prompt: ' . $prompt_name )['error'],
						'_metadata' => array(
							'component_type' => 'prompt',
							'prompt_name'    => $prompt_name,
							'failure_reason' => 'permission_denied',
							'is_builder'     => true,
						),
					);
				}

				$result              = $prompt->execute_direct( $arguments );
				$result['_metadata'] = array(
					'component_type' => 'prompt',
					'prompt_name'    => $prompt_name,
					'is_builder'     => true,
				);

				return $result;
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
			$has_permission = $ability->check_permissions( $arguments );
			if ( true !== $has_permission ) {
				// Extract detailed error message and code if WP_Error was returned
				$error_message  = 'Access denied for prompt: ' . $prompt_name;
				$failure_reason = 'permission_denied';

				if ( is_wp_error( $has_permission ) ) {
					$error_message  = $has_permission->get_error_message();
					$failure_reason = $has_permission->get_error_code(); // Use WP_Error code as failure_reason
				}

				return array(
					'error'     => McpErrorFactory::permission_denied( $request_id, $error_message )['error'],
					'_metadata' => array(
						'component_type' => 'prompt',
						'prompt_name'    => $prompt_name,
						'ability_name'   => $ability->get_name(),
						'failure_reason' => $failure_reason,
						'is_builder'     => false,
					),
				);
			}

			$result              = $ability->execute( $arguments );
			$result['_metadata'] = array(
				'component_type' => 'prompt',
				'prompt_name'    => $prompt_name,
				'ability_name'   => $ability->get_name(),
				'is_builder'     => false,
			);

			return $result;
		} catch ( \Throwable $e ) {
			$this->mcp->error_handler->log(
				'Prompt execution failed',
				array(
					'prompt_name' => $prompt_name,
					'arguments'   => $arguments,
					'error'       => $e->getMessage(),
				)
			);

			return array(
				'error'     => McpErrorFactory::internal_error( $request_id, 'Prompt execution failed' )['error'],
				'_metadata' => array(
					'component_type' => 'prompt',
					'prompt_name'    => $prompt_name,
					'failure_reason' => 'execution_failed',
					'error_type'     => get_class( $e ),
				),
			);
		}
	}
}
