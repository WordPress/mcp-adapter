<?php
/**
 * Helper trait for MCP handlers.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Handlers;

use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;
use WP\McpSchema\Common\JsonRpc\DTO\JSONRPCErrorResponse;

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
	 * @param int $code Error code.
	 * @param string $message Error message.
	 * @param string|int|null $request_id Optional. Request ID for JSON-RPC. Default 0.
	 *
	 * @return array Error response array with 'error' key.
	 */
	protected function create_error_response( int $code, string $message, $request_id = 0 ): array {
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
	 * McpErrorFactory methods return JSONRPCErrorResponse DTOs.
	 * This helper extracts the error array for handlers that need it.
	 *
	 * @param \WP\McpSchema\Common\JsonRpc\DTO\JSONRPCErrorResponse|array $factory_response Response from McpErrorFactory method (DTO or legacy array).
	 *
	 * @return array Error array (without wrapping 'error' key).
	 */
	protected function extract_error( $factory_response ): array {
		// Handle DTO responses
		if ( $factory_response instanceof JSONRPCErrorResponse ) {
			return $factory_response->getError()->toArray();
		}

		// Handle legacy array responses
		return $factory_response['error'] ?? $factory_response;
	}

	/**
	 * Creates missing parameter error response.
	 *
	 * @param string $param_name Missing parameter name.
	 * @param string|int|null $request_id Optional. Request ID for JSON-RPC. Default 0.
	 *
	 * @return array Error response array.
	 */
	protected function missing_parameter_error( string $param_name, $request_id = 0 ): array {
		return array( 'error' => McpErrorFactory::missing_parameter( $request_id, $param_name )->getError()->toArray() );
	}

	/**
	 * Creates permission denied error response.
	 *
	 * @param string $denied_resource Resource that was denied.
	 * @param string|int|null $request_id Optional. Request ID for JSON-RPC. Default 0.
	 *
	 * @return array Error response array.
	 */
	protected function permission_denied_error( string $denied_resource, $request_id = 0 ): array {
		return array( 'error' => McpErrorFactory::permission_denied( $request_id, 'Access denied for: ' . $denied_resource )->getError()->toArray() );
	}

	/**
	 * Creates internal error response.
	 *
	 * @param string $message Error message.
	 * @param string|int|null $request_id Optional. Request ID for JSON-RPC. Default 0.
	 *
	 * @return array Error response array.
	 */
	protected function internal_error( string $message, $request_id = 0 ): array {
		return array( 'error' => McpErrorFactory::internal_error( $request_id, $message )->getError()->toArray() );
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
