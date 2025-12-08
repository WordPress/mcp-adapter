<?php
/**
 * Helper trait for MCP handlers.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Handlers;

use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;

/**
 * Provides common helper methods for MCP handlers.
 */
trait HandlerHelperTrait {
	/**
	 * Extracts parameters from a request message.
	 *
	 * Handles both direct params and nested params structure for backward compatibility.
	 * This normalizes the dual parameter patterns found throughout handlers.
	 *
	 * @param array $data Request data that may have params at root or nested.
	 *
	 * @return array Extracted parameters.
	 */
	protected function extract_params( array $data ): array {
		return $data['params'] ?? $data;
	}

	/**
	 * Creates a standardized error response.
	 *
	 * This helper ensures all error responses follow the same format and
	 * properly extract the error field from McpErrorFactory responses.
	 *
	 * @param int    $code       Error code.
	 * @param string $message    Error message.
	 * @param int    $request_id Optional. Request ID for JSON-RPC. Default 0.
	 *
	 * @return array Error response array with 'error' key.
	 */
	protected function create_error_response( int $code, string $message, int $request_id = 0 ): array {
		return array(
			'id'    => $request_id,
			'error' => array(
				'code'    => $code,
				'message' => $message,
			),
		);
	}

	/**
	 * Extracts error array from McpErrorFactory response.
	 *
	 * McpErrorFactory methods return ['error' => [...]] but handlers
	 * often need just the error array itself.
	 *
	 * @param array $factory_response Response from McpErrorFactory method.
	 *
	 * @return array Error array (without wrapping 'error' key).
	 */
	protected function extract_error( array $factory_response ): array {
		return $factory_response['error'] ?? $factory_response;
	}

	/**
	 * Creates missing parameter error response.
	 *
	 * @param string $param_name Missing parameter name.
	 * @param int    $request_id Optional. Request ID for JSON-RPC. Default 0.
	 *
	 * @return array Error response array.
	 */
	protected function missing_parameter_error( string $param_name, int $request_id = 0 ): array {
		return array( 'error' => McpErrorFactory::missing_parameter( $request_id, $param_name )['error'] );
	}

	/**
	 * Creates permission denied error response.
	 *
	 * @param string $denied_resource Resource that was denied.
	 * @param int    $request_id      Optional. Request ID for JSON-RPC. Default 0.
	 *
	 * @return array Error response array.
	 */
	protected function permission_denied_error( string $denied_resource, int $request_id = 0 ): array {
		return array( 'error' => McpErrorFactory::permission_denied( $request_id, 'Access denied for: ' . $denied_resource )['error'] );
	}

	/**
	 * Creates internal error response.
	 *
	 * @param string $message    Error message.
	 * @param int    $request_id Optional. Request ID for JSON-RPC. Default 0.
	 *
	 * @return array Error response array.
	 */
	protected function internal_error( string $message, int $request_id = 0 ): array {
		return array( 'error' => McpErrorFactory::internal_error( $request_id, $message )['error'] );
	}

	/**
	 * Creates a standardized success response.
	 *
	 * @param mixed $data Response data.
	 *
	 * @return array Success response array.
	 */
	protected function create_success_response( $data ): array {
		return array(
			'result' => $data,
		);
	}
}
