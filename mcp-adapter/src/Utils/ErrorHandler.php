<?php //phpcs:ignore
/**
 * MCP Error Handler for standardized JSON-RPC error handling.
 *
 * @package WordPressMcp
 */

declare(strict_types=1);

namespace WP\MCP\Utils;

/**
 * Handles MCP error responses according to JSON-RPC 2.0 specification.
 */
class ErrorHandler {

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
	public const MCP_DISABLED          = -32000;
	public const MISSING_PARAMETER     = -32001;
	public const RESOURCE_NOT_FOUND    = -32002;
	public const TOOL_NOT_FOUND        = -32003;
	public const PROMPT_NOT_FOUND      = -32004;
	public const REST_API_ERROR        = -32005;
	public const INVALID_ACCEPT_HEADER = -32006;
	public const INVALID_CONTENT_TYPE  = -32007;

	/**
	 * Create a standardized JSON-RPC error response.
	 *
	 * @param int    $id The request ID.
	 * @param int    $code The error code.
	 * @param string $message The error message.
	 * @param mixed  $data Optional additional error data.
	 * @return array
	 */
	public static function create_error_response( int $id, int $code, string $message, $data = null ): array {
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
	 * @return array
	 */
	public static function parse_error( int $id, string $details = '' ): array {
		$message = 'Parse error';
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
	 * @return array
	 */
	public static function invalid_request( int $id, string $details = '' ): array {
		$message = 'Invalid Request';
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
	 * @return array
	 */
	public static function method_not_found( int $id, string $method ): array {
		return self::create_error_response( $id, self::METHOD_NOT_FOUND, "Method not found: {$method}" );
	}

	/**
	 * Create an invalid params error response.
	 *
	 * @param int    $id The request ID.
	 * @param string $details Optional additional details.
	 * @return array
	 */
	public static function invalid_params( int $id, string $details = '' ): array {
		$message = 'Invalid params';
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
	 * @return array
	 */
	public static function internal_error( int $id, string $details = '' ): array {
		$message = 'Internal error';
		if ( $details ) {
			$message .= ': ' . $details;
		}
		return self::create_error_response( $id, self::INTERNAL_ERROR, $message );
	}

	/**
	 * Create an MCP disabled error response.
	 *
	 * @param int $id The request ID.
	 * @return array
	 */
	public static function mcp_disabled( int $id ): array {
		return self::create_error_response( $id, self::MCP_DISABLED, 'MCP functionality is currently disabled' );
	}

	/**
	 * Create a missing parameter error response.
	 *
	 * @param int    $id The request ID.
	 * @param string $parameter The missing parameter name.
	 * @return array
	 */
	public static function missing_parameter( int $id, string $parameter ): array {
		return self::create_error_response( $id, self::MISSING_PARAMETER, "Missing required parameter: {$parameter}" );
	}

	/**
	 * Create a resource not found error response.
	 *
	 * @param int    $id The request ID.
	 * @param string $resource The resource identifier.
	 * @return array
	 */
	public static function resource_not_found( int $id, string $resource ): array {
		return self::create_error_response( $id, self::RESOURCE_NOT_FOUND, "Resource not found: {$resource}" );
	}

	/**
	 * Create a tool not found error response.
	 *
	 * @param int    $id The request ID.
	 * @param string $tool The tool name.
	 * @return array
	 */
	public static function tool_not_found( int $id, string $tool ): array {
		return self::create_error_response( $id, self::TOOL_NOT_FOUND, "Tool not found: {$tool}" );
	}

	/**
	 * Create a prompt not found error response.
	 *
	 * @param int    $id The request ID.
	 * @param string $prompt The prompt name.
	 * @return array
	 */
	public static function prompt_not_found( int $id, string $prompt ): array {
		return self::create_error_response( $id, self::PROMPT_NOT_FOUND, "Prompt not found: {$prompt}" );
	}

	/**
	 * Create an invalid accept header error response.
	 *
	 * @param int $id The request ID.
	 * @return array
	 */
	public static function invalid_accept_header( int $id ): array {
		return self::create_error_response(
			$id,
			self::INVALID_ACCEPT_HEADER,
			'Accept header must include both application/json and text/event-stream'
		);
	}

	/**
	 * Create an invalid content type error response.
	 *
	 * @param int $id The request ID.
	 * @return array
	 */
	public static function invalid_content_type( int $id ): array {
		return self::create_error_response(
			$id,
			self::INVALID_CONTENT_TYPE,
			'Content-Type must be application/json'
		);
	}

	/**
	 * Log with context.
	 *
	 * @param string $message The log message.
	 * @param array  $context Additional context data.
	 * @return void
	 */
	public static function log( string $message, array $context = array(), string $type = 'error' ): void {
		do_action( 'wp_mcp_log', [
			'feature'  => 'mcp-adapter',
			'message'  => $message,
			'context'  => $context,
			'severity' => $type,
			'user_id'  => get_current_user_id(),
		] );
	}

	/**
	 * Handle and log exceptions with proper error response.
	 *
	 * @param \Throwable $exception The exception to handle.
	 * @param int        $request_id The request ID.
	 * @return array Error response array.
	 */
	public static function handle_exception( \Throwable $exception, int $request_id ): array {
		// Log the exception for debugging.
		self::log(
			'Exception occurred',
			array(
				'message' => $exception->getMessage(),
				'file'    => $exception->getFile(),
				'line'    => $exception->getLine(),
				'trace'   => $exception->getTraceAsString(),
			)
		);

		// Return sanitized error response.
		return self::internal_error( $request_id, 'An internal error occurred' );
	}

	/**
	 * Validate JSON-RPC message structure.
	 *
	 * @param mixed $message The message to validate.
	 * @return bool|array Returns true if valid, or error array if invalid.
	 */
	public static function validate_jsonrpc_message( $message ) {
		if ( ! is_array( $message ) ) {
			return self::invalid_request( 0, 'Message must be a JSON object' );
		}

		// Must have jsonrpc field with value "2.0".
		if ( ! isset( $message['jsonrpc'] ) || '2.0' !== $message['jsonrpc'] ) {
			return self::invalid_request( 0, 'jsonrpc version must be "2.0"' );
		}

		// Must be either a request/notification (has method) or a response (has result/error).
		$is_request_or_notification = isset( $message['method'] );
		$is_response                = isset( $message['result'] ) || isset( $message['error'] );

		if ( ! $is_request_or_notification && ! $is_response ) {
			return self::invalid_request( 0, 'Message must have either method or result/error field' );
		}

		// Responses must have an id field.
		if ( $is_response && ! isset( $message['id'] ) ) {
			return self::invalid_request( 0, 'Response messages must have an id field' );
		}

		return true;
	}
}
