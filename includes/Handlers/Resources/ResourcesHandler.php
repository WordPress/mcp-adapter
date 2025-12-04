<?php
/**
 * Resources method handlers for MCP requests.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Handlers\Resources;

use WP\MCP\Core\McpServer;
use WP\MCP\Handlers\HandlerHelperTrait;
use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;

/**
 * Handles resources-related MCP methods.
 */
class ResourcesHandler {
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
	 * Handles the resources/list request.
	 *
	 * @param int $request_id Optional. The request ID for JSON-RPC. Default 0.
	 *
	 * @return array Response with resources list and metadata.
	 */
	public function list_resources( int $request_id = 0 ): array {
		// Get the registered resources from the MCP instance and extract only the args.
		$resources = array();
		foreach ( $this->mcp->get_resources() as $resource ) {
			$resources[] = $resource->to_array();
		}

		return array(
			'resources' => $resources,
			'_metadata' => array(
				'component_type'  => 'resources',
				'resources_count' => count( $resources ),
			),
		);
	}

	/**
	 * Handles the resources/read request.
	 *
	 * @param array $params     Request parameters.
	 * @param int   $request_id Optional. The request ID for JSON-RPC. Default 0.
	 *
	 * @return array Response with resource contents or error.
	 */
	public function read_resource( array $params, int $request_id = 0 ): array {
		// Extract parameters using helper method.
		$request_params = $this->extract_params( $params );

		if ( ! isset( $request_params['uri'] ) ) {
			return array(
				'error'     => McpErrorFactory::missing_parameter( $request_id, 'uri' )['error'],
				'_metadata' => array(
					'component_type' => 'resource',
					'failure_reason' => 'missing_parameter',
				),
			);
		}

		// Implement resource reading logic here.
		$uri      = $request_params['uri'];
		$resource = $this->mcp->get_resource( $uri );

		if ( ! $resource ) {
			return array(
				'error'     => McpErrorFactory::resource_not_found( $request_id, $uri )['error'],
				'_metadata' => array(
					'component_type' => 'resource',
					'resource_uri'   => $uri,
					'failure_reason' => 'not_found',
				),
			);
		}

		/**
		 * Get the ability
		 *
		 * @var \WP_Ability|\WP_Error $ability
		 */
		$ability = $resource->get_ability();

		// Check if getting the ability returned an error
		if ( is_wp_error( $ability ) ) {
			$this->mcp->error_handler->log(
				'Failed to get ability for resource',
				array(
					'resource_uri'  => $uri,
					'error_message' => $ability->get_error_message(),
				)
			);

			return array(
				'error'     => McpErrorFactory::internal_error( $request_id, $ability->get_error_message() )['error'],
				'_metadata' => array(
					'component_type' => 'resource',
					'resource_uri'   => $uri,
					'resource_name'  => $resource->get_name(),
					'failure_reason' => 'ability_retrieval_failed',
					'error_code'     => $ability->get_error_code(),
				),
			);
		}

		try {
			$has_permission = $ability->check_permissions();
			if ( true !== $has_permission ) {
				// Extract detailed error message and code if WP_Error was returned
				$error_message  = 'Access denied for resource: ' . $resource->get_name();
				$failure_reason = 'permission_denied';

				if ( is_wp_error( $has_permission ) ) {
					$error_message  = $has_permission->get_error_message();
					$failure_reason = $has_permission->get_error_code(); // Use WP_Error code as failure_reason
				}

				return array(
					'error'     => McpErrorFactory::permission_denied( $request_id, $error_message )['error'],
					'_metadata' => array(
						'component_type' => 'resource',
						'resource_uri'   => $uri,
						'resource_name'  => $resource->get_name(),
						'ability_name'   => $ability->get_name(),
						'failure_reason' => $failure_reason,
					),
				);
			}

			$contents = $ability->execute();

			// Handle WP_Error objects that weren't converted by the ability.
			if ( is_wp_error( $contents ) ) {
				$this->mcp->error_handler->log(
					'Ability returned WP_Error object',
					array(
						'ability'       => $ability->get_name(),
						'error_code'    => $contents->get_error_code(),
						'error_message' => $contents->get_error_message(),
					)
				);

				return array(
					'error'     => McpErrorFactory::internal_error( $request_id, $contents->get_error_message() )['error'],
					'_metadata' => array(
						'component_type' => 'resource',
						'resource_uri'   => $uri,
						'resource_name'  => $resource->get_name(),
						'ability_name'   => $ability->get_name(),
						'failure_reason' => 'wp_error',
						'error_code'     => $contents->get_error_code(),
					),
				);
			}

			// Successful execution - return contents.
			return array(
				'contents'  => $contents,
				'_metadata' => array(
					'component_type' => 'resource',
					'resource_uri'   => $uri,
					'resource_name'  => $resource->get_name(),
					'ability_name'   => $ability->get_name(),
				),
			);
		} catch ( \Throwable $exception ) {
			$this->mcp->error_handler->log(
				'Error reading resource',
				array(
					'uri'       => $uri,
					'exception' => $exception->getMessage(),
				)
			);

			return array(
				'error'     => McpErrorFactory::internal_error( $request_id, 'Failed to read resource' )['error'],
				'_metadata' => array(
					'component_type' => 'resource',
					'resource_uri'   => $uri,
					'failure_reason' => 'execution_failed',
					'error_type'     => get_class( $exception ),
				),
			);
		}
	}
}
