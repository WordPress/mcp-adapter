<?php //phpcs:ignore
/**
 * Prompts method handlers for MCP requests.
 *
 * @package WP\MCP
 */

declare(strict_types=1);

namespace WP\MCP\RequestMethodHandlers;

use WP\MCP\Registry\Server;
use WP\MCP\Utils\ErrorHandler;
use WP\MCP\Utils\HandlePromptGet;

/**
 * Handles prompts-related MCP methods.
 */
class PromptsHandler {
	/**
	 * The WordPress MCP instance.
	 *
	 * @var Server
	 */
	private Server $mcp;

	/**
	 * Constructor.
	 *
	 * @param Server $mcp The WordPress MCP instance.
	 */
	public function __construct( Server $mcp ) {
		$this->mcp = $mcp;
	}

	/**
	 * Check if user has permission to access prompts.
	 *
	 * @return array|null Returns error array if permission denied, null if allowed.
	 */
	private function check_permission(): ?array {
		if ( ! is_user_logged_in() ) {
			return array(
				'error' => array(
					'code'    => 'rest_forbidden',
					'message' => 'You must be logged in to access prompts.',
					'data'    => array( 'status' => 401 ),
				),
			);
		}
		return null;
	}

	/**
	 * Handle the prompts/list request.
	 *
	 * @return array
	 */
	public function list_prompts(): array {
		$permission_error = $this->check_permission();
		if ( $permission_error ) {
			return $permission_error;
		}

		// Get the registered prompts from the MCP instance and extract only the args.
		$prompts = array();
		foreach ( $this->mcp->get_prompts() as $prompt_data ) {
			$prompts[] = $prompt_data['args'];
		}

		return array(
			'prompts' => $prompts,
		);
	}

	/**
	 * Handle the prompts/get request.
	 *
	 * @param array $params Request parameters.
	 * @return array
	 */
	public function get_prompt( array $params ): array {
		$permission_error = $this->check_permission();
		if ( $permission_error ) {
			return $permission_error;
		}

		// Handle both direct params and nested params structure.
		$request_params = $params['params'] ?? $params;

		if ( ! isset( $request_params['name'] ) ) {
			return array(
				'error' => ErrorHandler::missing_parameter( 0, 'name' )['error'],
			);
		}

		// Get the prompt by name.
		$prompt_name = $request_params['name'];
		$prompt_data = $this->mcp->get_prompt_by_name( $prompt_name );

		if ( ! $prompt_data ) {
			return array(
				'error' => ErrorHandler::prompt_not_found( 0, $prompt_name )['error'],
			);
		}

		// Get the arguments for the prompt.
		$arguments = $request_params['arguments'] ?? array();
		$prompt_args = $prompt_data['args'];
		$messages = $prompt_data['messages'];

		return array(
			'result' => HandlePromptGet::run( $prompt_args, $messages, $arguments ),
		);
	}
}
