<?php
/**
 * Factory class for creating MCP error responses.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Infrastructure\ErrorHandling;

/**
 * Factory for creating standardized MCP error responses.
 *
 * This class provides static methods for creating various types of JSON-RPC
 * error responses according to the MCP specification.
 */
class McpErrorFactory {

	/**
	 * Standard JSON-RPC error codes as defined in the specification.
	 */
	public const PARSE_ERROR      = -32700;
	public const INVALID_REQUEST  = -32600;
	public const METHOD_NOT_FOUND = -32601;
	public const INVALID_PARAMS   = -32602;
	public const INTERNAL_ERROR   = -32603;

	/**
	 * MCP-specific error codes (above -32000 as per JSON-RPC spec).
	 */
	public const MCP_DISABLED       = -32000;
	public const MISSING_PARAMETER  = -32001;
	public const RESOURCE_NOT_FOUND = -32002;
	public const TOOL_NOT_FOUND     = -32003;
	public const PROMPT_NOT_FOUND   = -32004;
	public const PERMISSION_DENIED  = -32008;
	public const UNAUTHORIZED       = -32010;

	/**
	 * Create a standardized JSON-RPC error response.
	 *
	 * @param int    $id The request ID.
	 * @param int    $code The error code.
	 * @param string $message The error message.
	 * @param mixed|null $data Optional additional error data.
	 *
	 * @return array
	 */
	public static function create_error_response( int $id, int $code, string $message, mixed $data = null ): array {
		$response = array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => array(
				'code'    => $code,
				'message' => $message,
			),
		);

		if ( null !== $data ) {
			$response['error']['data'] = $data;
		}

		return $response;
	}

	/**
	 * Create a parse error response.
	 *
	 * @param int    $id The request ID.
	 * @param string $details Optional additional details.
	 *
	 * @return array
	 */
	public static function parse_error( int $id, string $details = '' ): array {
		$message = __( 'Parse error', 'mcp-adapter' );
		if ( $details ) {
			$message .= ': ' . $details;
		}

		return self::create_error_response( $id, self::PARSE_ERROR, $message );
	}

	/**
	 * Create an invalid request error response.
	 *
	 * @param int    $id The request ID.
	 * @param string $details Optional additional details.
	 *
	 * @return array
	 */
	public static function invalid_request( int $id, string $details = '' ): array {
		$message = __( 'Invalid Request', 'mcp-adapter' );
		if ( $details ) {
			$message .= ': ' . $details;
		}

		return self::create_error_response( $id, self::INVALID_REQUEST, $message );
	}

	/**
	 * Create a method not found error response.
	 *
	 * @param int    $id The request ID.
	 * @param string $method The method that was not found.
	 *
	 * @return array
	 */
	public static function method_not_found( int $id, string $method ): array {
		return self::create_error_response(
			$id,
			self::METHOD_NOT_FOUND,
			sprintf(
				/* translators: %s: method name */
				__( 'Method not found: %s', 'mcp-adapter' ),
				$method
			)
		);
	}

	/**
	 * Create an invalid params error response.
	 *
	 * @param int    $id The request ID.
	 * @param string $details Optional additional details.
	 *
	 * @return array
	 */
	public static function invalid_params( int $id, string $details = '' ): array {
		$message = __( 'Invalid params', 'mcp-adapter' );
		if ( $details ) {
			$message .= ': ' . $details;
		}

		return self::create_error_response( $id, self::INVALID_PARAMS, $message );
	}

	/**
	 * Create an internal error response.
	 *
	 * @param int    $id The request ID.
	 * @param string $details Optional additional details.
	 *
	 * @return array
	 */
	public static function internal_error( int $id, string $details = '' ): array {
		$message = __( 'Internal error', 'mcp-adapter' );
		if ( $details ) {
			$message .= ': ' . $details;
		}

		return self::create_error_response( $id, self::INTERNAL_ERROR, $message );
	}

	/**
	 * Create an MCP disabled error response.
	 *
	 * @param int $id The request ID.
	 *
	 * @return array
	 */
	public static function mcp_disabled( int $id ): array {
		return self::create_error_response(
			$id,
			self::MCP_DISABLED,
			__( 'MCP functionality is currently disabled', 'mcp-adapter' )
		);
	}

	/**
	 * Create a missing parameter error response.
	 *
	 * @param int    $id The request ID.
	 * @param string $parameter The missing parameter name.
	 *
	 * @return array
	 */
	public static function missing_parameter( int $id, string $parameter ): array {
		return self::create_error_response(
			$id,
			self::MISSING_PARAMETER,
			sprintf(
				/* translators: %s: parameter name */
				__( 'Missing required parameter: %s', 'mcp-adapter' ),
				$parameter
			)
		);
	}

	/**
	 * Create a resource not found error response.
	 *
	 * @param int    $id The request ID.
	 * @param string $resource_uri The resource identifier.
	 *
	 * @return array
	 */
	public static function resource_not_found( int $id, string $resource_uri ): array {
		return self::create_error_response(
			$id,
			self::RESOURCE_NOT_FOUND,
			sprintf(
				/* translators: %s: resource identifier */
				__( 'Resource not found: %s', 'mcp-adapter' ),
				$resource_uri
			)
		);
	}

	/**
	 * Create a tool not found error response.
	 *
	 * @param int    $id The request ID.
	 * @param string $tool The tool name.
	 *
	 * @return array
	 */
	public static function tool_not_found( int $id, string $tool ): array {
		return self::create_error_response(
			$id,
			self::TOOL_NOT_FOUND,
			sprintf(
				/* translators: %s: tool name */
				__( 'Tool not found: %s', 'mcp-adapter' ),
				$tool
			)
		);
	}

	/**
	 * Create a prompt not found error response.
	 *
	 * @param int    $id The request ID.
	 * @param string $prompt The prompt name.
	 *
	 * @return array
	 */
	public static function prompt_not_found( int $id, string $prompt ): array {
		return self::create_error_response(
			$id,
			self::PROMPT_NOT_FOUND,
			sprintf(
				/* translators: %s: prompt name */
				__( 'Prompt not found: %s', 'mcp-adapter' ),
				$prompt
			)
		);
	}

	/**
	 * Create a permission denied error response.
	 *
	 * @param int    $id The request ID.
	 * @param string $details Optional additional details.
	 *
	 * @return array
	 */
	public static function permission_denied( int $id, string $details = '' ): array {
		$message = __( 'Permission denied', 'mcp-adapter' );
		if ( $details ) {
			$message .= ': ' . $details;
		}

		return self::create_error_response( $id, self::PERMISSION_DENIED, $message );
	}

	/**
	 * Create an unauthorized error response.
	 *
	 * @param int    $id The request ID.
	 * @param string $details Optional additional details.
	 *
	 * @return array
	 */
	public static function unauthorized( int $id, string $details = '' ): array {
		$message = __( 'Unauthorized', 'mcp-adapter' );
		if ( $details ) {
			$message .= ': ' . $details;
		}

		return self::create_error_response( $id, self::UNAUTHORIZED, $message );
	}

	/**
	 * Validate JSON-RPC message structure.
	 *
	 * @param mixed $message The message to validate.
	 *
	 * @return bool|array Returns true if valid, or error array if invalid.
	 */
	public static function validate_jsonrpc_message( mixed $message ): bool|array {
		if ( ! is_array( $message ) ) {
			return self::invalid_request( 0, __( 'Message must be a JSON object', 'mcp-adapter' ) );
		}

		// Must have jsonrpc field with value "2.0".
		if ( ! isset( $message['jsonrpc'] ) || '2.0' !== $message['jsonrpc'] ) {
			return self::invalid_request( 0, __( 'jsonrpc version must be "2.0"', 'mcp-adapter' ) );
		}

		// Must be either a request/notification (has method) or a response (has result/error).
		$is_request_or_notification = isset( $message['method'] );
		$is_response                = isset( $message['result'] ) || isset( $message['error'] );

		if ( ! $is_request_or_notification && ! $is_response ) {
			return self::invalid_request( 0, __( 'Message must have either method or result/error field', 'mcp-adapter' ) );
		}

		// Responses must have an id field.
		if ( $is_response && ! isset( $message['id'] ) ) {
			return self::invalid_request( 0, __( 'Response messages must have an id field', 'mcp-adapter' ) );
		}

		return true;
	}
}
