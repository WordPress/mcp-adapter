<?php
/**
 * JSON-RPC Response Builder for MCP Transport
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Transport\Infrastructure;

/**
 * Builds standardized JSON-RPC 2.0 responses for MCP transport.
 *
 * Centralizes response creation logic to eliminate duplication and ensure
 * consistent response formatting across all MCP transport implementations.
 */
class JsonRpcResponseBuilder {

	/**
	 * Create a JSON-RPC 2.0 success response.
	 *
	 * @param mixed $request_id The request ID from the original JSON-RPC request (string, number, or null).
	 * @param mixed $result The result data to return.
	 *
	 * @return array The formatted JSON-RPC response.
	 */
	public static function create_success_response( $request_id, $result ): array {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $request_id,
			// Make sure the result is an object (not an array)
			'result'  => (object) $result,
		);
	}

	/**
	 * Create a JSON-RPC 2.0 error response.
	 *
	 * @param mixed $request_id The request ID from the original JSON-RPC request (string, number, or null).
	 * @param array $error The error array with 'code', 'message', and optional 'data'.
	 *
	 * @return array The formatted JSON-RPC error response.
	 */
	public static function create_error_response( $request_id, array $error ): array {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $request_id,
			'error'   => $error,
		);
	}

	/**
	 * Process multiple MCP messages and format the response correctly.
	 *
	 * Handles both batch requests (array of messages) and single requests,
	 * returning the appropriate response format per JSON-RPC 2.0 specification.
	 *
	 * @param array $messages Array of JSON-RPC messages to process.
	 * @param bool $is_batch_request Whether the original request was a batch.
	 * @param callable $processor Callback function to process each individual message.
	 *                                Should accept (array $message) and return array $response.
	 *
	 * @return array|null The formatted response (array for batch, single response for non-batch).
	 */
	public static function process_messages( array $messages, bool $is_batch_request, callable $processor ): ?array {
		$results = array();

		foreach ( $messages as $message ) {
			$response = call_user_func( $processor, $message );
			if ( null === $response ) {
				continue;
			}

			$results[] = $response;
		}

		// Return response format based on original request format (JSON-RPC 2.0 spec)
		// If the request was a batch, response MUST be an array, even if only one result
		return $is_batch_request ? $results : ( $results[0] ?? null );
	}

	/**
	 * Determine if a request body represents a batch request.
	 *
	 * Per JSON-RPC 2.0 specification, a batch request is an array with at least one element.
	 *
	 * @param mixed $body The decoded request body.
	 *
	 * @return bool True if this is a batch request.
	 */
	public static function is_batch_request( $body ): bool {
		return is_array( $body ) && isset( $body[0] );
	}

	/**
	 * Normalize request body to an array of messages.
	 *
	 * Converts single messages to an array for uniform processing.
	 *
	 * @param mixed $body The decoded request body.
	 *
	 * @return array Array of messages for processing.
	 */
	public static function normalize_messages( $body ): array {
		return self::is_batch_request( $body ) ? $body : array( $body );
	}
}
