<?php //phpcs:ignore
/**
 * Resources method handlers for MCP requests.
 *
 * @package WP\MCP
 */

declare(strict_types=1);

namespace WP\MCP\RequestMethodHandlers;

use WP\MCP\Registry\Server;
use WP\MCP\Utils\ErrorHandler;

/**
 * Handles resources-related MCP methods.
 */
class ResourcesHandler {
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
	 * Check if user has permission to access resources.
	 *
	 * @return array|null Returns error array if permission denied, null if allowed.
	 */
	private function check_permission(): ?array {
		if ( ! is_user_logged_in() ) {
			return array(
				'error' => array(
					'code'    => 'rest_forbidden',
					'message' => 'You must be logged in to access resources.',
					'data'    => array( 'status' => 401 ),
				),
			);
		}
		return null;
	}

	/**
	 * Handle the resources/list request.
	 *
	 * @return array
	 */
	public function list_resources(): array {
		$permission_error = $this->check_permission();
		if ( $permission_error ) {
			return $permission_error;
		}

		// Get the registered resources from the MCP instance and extract only the args.
		$resources = array();
		foreach ( $this->mcp->get_resources() as $resource_data ) {
			$resources[] = $resource_data['args'];
		}

		return array(
			'resources' => $resources,
		);
	}

	/**
	 * Handle the resources/templates/list request.
	 *
	 * @param array $params Request parameters.
	 * @return array
	 */
	public function list_resource_templates( array $params ): array {
		$permission_error = $this->check_permission();
		if ( $permission_error ) {
			return $permission_error;
		}

		// Implement resource template listing logic here.
		$templates = array();

		return array(
			'templates' => $templates,
		);
	}

	/**
	 * Handle the resources/read request.
	 *
	 * @param array $params Request parameters.
	 * @return array
	 */
	public function read_resource( array $params ): array {
		$permission_error = $this->check_permission();
		if ( $permission_error ) {
			return $permission_error;
		}

		// Handle both direct params and nested params structure.
		$request_params = $params['params'] ?? $params;

		if ( ! isset( $request_params['uri'] ) ) {
			return array(
				'error' => ErrorHandler::missing_parameter( 0, 'uri' )['error'],
			);
		}

		// Implement resource reading logic here.
		$uri       = $request_params['uri'];
		$resources = $this->mcp->get_resources();

		if ( ! isset( $resources[ $uri ] ) ) {
			return array(
				'error' => ErrorHandler::resource_not_found( 0, $uri )['error'],
			);
		}

		try {
			$resource_data = $resources[ $uri ];
			$callback      = $resource_data['callback'];
			$content       = call_user_func( $callback, $request_params );

			return array(
				'contents' => array(
					array(
						'uri'      => $uri,
						'mimeType' => $resource_data['args']['mimeType'] ?? 'application/json',
						'text'     => wp_json_encode( $content ),
					),
				),
			);
		} catch ( \Throwable $exception ) {
			ErrorHandler::log(
				'Error reading resource',
				array(
					'uri'       => $uri,
					'exception' => $exception->getMessage(),
				)
			);
			return array(
				'error' => ErrorHandler::internal_error( 0, 'Failed to read resource' )['error'],
			);
		}
	}

	/**
	 * Handle the resources/subscribe request.
	 *
	 * @param array $params Request parameters.
	 * @return array
	 */
	public function subscribe_resource( array $params ): array {
		$permission_error = $this->check_permission();
		if ( $permission_error ) {
			return $permission_error;
		}

		// Handle both direct params and nested params structure.
		$request_params = $params['params'] ?? $params;

		if ( ! isset( $request_params['uri'] ) ) {
			return array(
				'error' => ErrorHandler::missing_parameter( 0, 'uri' )['error'],
			);
		}

		// Implement resource subscription logic here.
		$uri = $request_params['uri'];

		return array(
			'subscriptionId' => 'sub_' . md5( $uri ),
		);
	}

	/**
	 * Handle the resources/unsubscribe request.
	 *
	 * @param array $params Request parameters.
	 * @return array
	 */
	public function unsubscribe_resource( array $params ): array {
		$permission_error = $this->check_permission();
		if ( $permission_error ) {
			return $permission_error;
		}

		// Handle both direct params and nested params structure.
		$request_params = $params['params'] ?? $params;

		if ( ! isset( $request_params['subscriptionId'] ) ) {
			return array(
				'error' => ErrorHandler::missing_parameter( 0, 'subscriptionId' )['error'],
			);
		}

		// @todo: Implement resource unsubscription logic here.

		return array(
			'success' => true,
		);
	}
}
