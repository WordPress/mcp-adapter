<?php
/**
 * Resources method handlers for MCP requests.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Handlers\Resources;

use Throwable;
use WP\MCP\Core\McpServer;
use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;

/**
 * Handles resources-related MCP methods.
 */
class ResourcesHandler {
	/**
	 * The WordPress MCP instance.
	 *
	 * @var McpServer
	 */
	private McpServer $mcp;

	/**
	 * Constructor.
	 *
	 * @param McpServer $mcp The WordPress MCP instance.
	 */
	public function __construct( McpServer $mcp ) {
		$this->mcp = $mcp;
	}

	/**
	 * Check if user has permission to access resources.
	 *
	 * Authorization is primarily handled at the transport level. For additional
	 * hardening, this handler can also enforce authentication when the
	 * `mcp_enforce_handler_auth` filter returns true.
	 *
	 * @return null|array Returns error if permission denied, null if allowed.
	 */
	private function check_permission(): null|array {
		$enforce_handler_auth = (bool) apply_filters( 'mcp_enforce_handler_auth', false );

		if ( $enforce_handler_auth && ! is_user_logged_in() ) {
			return array( 'error' => McpErrorFactory::unauthorized( 0, 'You must be logged in to access resources.' )['error'] );
		}

		return null;
	}

	/**
	 * Handle the resources/list request.
	 *
	 * @param int $request_id The request ID for JSON-RPC.
	 *
	 * @return array
	 */
	public function list_resources( int $request_id = 0 ): array {
		$permission_error = $this->check_permission();
		if ( $permission_error ) {
			return $permission_error;
		}

		// Get the registered resources from the MCP instance and extract only the args.
		$resources = array();
		foreach ( $this->mcp->get_resources() as $resource ) {
			$resources[] = $resource->to_array();
		}

		return array(
			'resources' => $resources,
		);
	}

	/**
	 * Handle the resources/templates/list request.
	 *
	 * @param int $request_id The request ID for JSON-RPC.
	 *
	 * @return array
	 */
	public function list_resource_templates( int $request_id = 0 ): array {
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
	 * @param array $params     Request parameters.
	 * @param int   $request_id The request ID for JSON-RPC.
	 *
	 * @return array
	 */
	public function read_resource( array $params, int $request_id = 0 ): array {
		// Handle both direct params and nested params structure.
		$request_params = $params['params'] ?? $params;

		if ( ! isset( $request_params['uri'] ) ) {
			return array( 'error' => McpErrorFactory::missing_parameter( $request_id, 'uri' )['error'] );
		}

		// Implement resource reading logic here.
		$uri      = $request_params['uri'];
		$resource = $this->mcp->get_resource( $uri );

		if ( ! $resource ) {
			return array( 'error' => McpErrorFactory::resource_not_found( $request_id, $uri )['error'] );
		}

		try {
			$ability = $resource->get_ability();
			$ability->has_permission( $request_params );

			$contents = $ability->execute( $request_params );

			return array(
				'contents' => $contents,
			);
		} catch ( Throwable $exception ) {
			if ( $this->mcp->error_handler ) {
				$this->mcp->error_handler->log(
					'Error reading resource',
					array(
						'uri'       => $uri,
						'exception' => $exception->getMessage(),
					)
				);
			}

			return array( 'error' => McpErrorFactory::internal_error( $request_id, 'Failed to read resource' )['error'] );
		}
	}

	/**
	 * Handle the resources/subscribe request.
	 *
	 * @param array $params     Request parameters.
	 * @param int   $request_id The request ID for JSON-RPC.
	 *
	 * @return array
	 */
	public function subscribe_resource( array $params, int $request_id = 0 ): array {
		$permission_error = $this->check_permission();
		if ( $permission_error ) {
			return $permission_error;
		}

		// Handle both direct params and nested params structure.
		$request_params = $params['params'] ?? $params;

		if ( ! isset( $request_params['uri'] ) ) {
			return array( 'error' => McpErrorFactory::missing_parameter( $request_id, 'uri' )['error'] );
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
	 * @param array $params     Request parameters.
	 * @param int   $request_id The request ID for JSON-RPC.
	 *
	 * @return array
	 */
	public function unsubscribe_resource( array $params, int $request_id = 0 ): array {
		$permission_error = $this->check_permission();
		if ( $permission_error ) {
			return $permission_error;
		}

		// Handle both direct params and nested params structure.
		$request_params = $params['params'] ?? $params;

		if ( ! isset( $request_params['subscriptionId'] ) ) {
			return array( 'error' => McpErrorFactory::missing_parameter( $request_id, 'subscriptionId' )['error'] );
		}

		return array(
			'success' => true,
		);
	}
}
