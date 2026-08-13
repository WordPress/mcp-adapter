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

	private const JSONRPC_VERSION = '2.0';

	/**
	 * Standard JSON-RPC error codes as defined in the specification.
	 */
	public const PARSE_ERROR                        = -32700;
	public const INVALID_REQUEST                    = -32600;
	public const METHOD_NOT_FOUND                   = -32601;
	public const INVALID_PARAMS                     = -32602;
	public const INTERNAL_ERROR                     = -32603;
	public const HEADER_MISMATCH                    = -32020;
	public const MISSING_REQUIRED_CLIENT_CAPABILITY = -32021;
	public const UNSUPPORTED_PROTOCOL_VERSION       = -32022;

	/**
	 * Implementation-defined server error codes (in -32000 to -32099 range as per JSON-RPC spec).
	 * Using conservative, well-established error codes only.
	 */
	public const SERVER_ERROR       = -32000; // Generic server error (includes MCP disabled)
	public const TIMEOUT_ERROR      = -32001; // Request timeout
	public const RESOURCE_NOT_FOUND = -32002; // Resource not found
	public const TOOL_NOT_FOUND     = -32003; // Tool not found
	public const PROMPT_NOT_FOUND   = -32004; // Prompt not found
	public const SESSION_NOT_FOUND  = -32005; // Session not found or expired
	public const PERMISSION_DENIED  = -32008; // Access denied/forbidden
	public const UNAUTHORIZED       = -32010; // Authentication required

	/**
	 * Create a parse error response.
	 *
	 * @param string|int|null $id The request ID.
	 * @param string $details Optional additional details.
	 *
	 * @return array{jsonrpc: '2.0', error: array{code: int, message: string, data?: mixed}, id: string|int|null}
	 */
	public static function parse_error( $id, string $details = '' ): array {
		$message = __( 'Parse error', 'mcp-adapter' );
		if ( $details ) {
			$message .= ': ' . $details;
		}

		return self::create_error_response( $id, self::PARSE_ERROR, $message );
	}

	/**
	 * Create a standardized JSON-RPC error response.
	 *
	 * @param string|int|null $id The request ID (JSON-RPC allows string, int, or null).
	 * @param int $code The error code.
	 * @param string $message The error message.
	 * @param mixed|null $data Optional additional error data.
	 *
	 * @return array{jsonrpc: '2.0', error: array{code: int, message: string, data?: mixed}, id: string|int|null}
	 */
	public static function create_error_response( $id, int $code, string $message, $data = null ): array {
		return array(
			'jsonrpc' => self::JSONRPC_VERSION,
			'error'   => self::create_error( $code, $message, $data ),
			'id'      => $id,
		);
	}

	/**
	 * Create a JSON-RPC error object.
	 *
	 * @param int $code The error code.
	 * @param string $message The error message.
	 * @param mixed|null $data Optional additional error data.
	 *
	 * @return array{code: int, message: string, data?: mixed}
	 */
	public static function create_error( int $code, string $message, $data = null ): array {
		$error = array(
			'code'    => $code,
			'message' => $message,
		);

		if ( null !== $data ) {
			$error['data'] = $data;
		}

		return $error;
	}

	/**
	 * Create a method not found error response.
	 *
	 * @param string|int|null $id The request ID.
	 * @param string $method The method that was not found.
	 *
	 * @return array{jsonrpc: '2.0', error: array{code: int, message: string, data?: mixed}, id: string|int|null}
	 */
	public static function method_not_found( $id, string $method ): array {
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
	 * @param string|int|null $id The request ID.
	 * @param string $details Optional additional details.
	 *
	 * @return array{jsonrpc: '2.0', error: array{code: int, message: string, data?: mixed}, id: string|int|null}
	 */
	public static function invalid_params( $id, string $details = '' ): array {
		$message = __( 'Invalid params', 'mcp-adapter' );
		if ( $details ) {
			$message .= ': ' . $details;
		}

		return self::create_error_response( $id, self::INVALID_PARAMS, $message );
	}

	/**
	 * Create an internal error response.
	 *
	 * @param string|int|null $id The request ID.
	 * @param string $details Optional additional details.
	 *
	 * @return array{jsonrpc: '2.0', error: array{code: int, message: string, data?: mixed}, id: string|int|null}
	 */
	public static function internal_error( $id, string $details = '' ): array {
		$message = __( 'Internal error', 'mcp-adapter' );
		if ( $details ) {
			$message .= ': ' . $details;
		}

		return self::create_error_response( $id, self::INTERNAL_ERROR, $message );
	}

	/**
	 * Create an MCP disabled error response.
	 *
	 * @param string|int|null $id The request ID.
	 *
	 * @return array{jsonrpc: '2.0', error: array{code: int, message: string, data?: mixed}, id: string|int|null}
	 */
	public static function mcp_disabled( $id ): array {
		return self::create_error_response(
			$id,
			self::SERVER_ERROR,
			__( 'MCP functionality is currently disabled', 'mcp-adapter' )
		);
	}

	/**
	 * Create a validation error response (uses standard invalid params error).
	 *
	 * @param string|int|null $id The request ID.
	 * @param string $details Validation error details.
	 *
	 * @return array{jsonrpc: '2.0', error: array{code: int, message: string, data?: mixed}, id: string|int|null}
	 */
	public static function validation_error( $id, string $details ): array {
		return self::create_error_response(
			$id,
			self::INVALID_PARAMS,
			sprintf(
			/* translators: %s: validation details */
				__( 'Validation error: %s', 'mcp-adapter' ),
				$details
			)
		);
	}

	/**
	 * Create a missing parameter error response.
	 *
	 * @param string|int|null $id The request ID.
	 * @param string $parameter The missing parameter name.
	 *
	 * @return array{jsonrpc: '2.0', error: array{code: int, message: string, data?: mixed}, id: string|int|null}
	 */
	public static function missing_parameter( $id, string $parameter ): array {
		return self::create_error_response(
			$id,
			self::INVALID_PARAMS,
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
	 * @param string|int|null $id The request ID.
	 * @param string $resource_uri The resource identifier.
	 *
	 * @return array{jsonrpc: '2.0', error: array{code: int, message: string, data?: mixed}, id: string|int|null}
	 */
	public static function resource_not_found( $id, string $resource_uri ): array {
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
	 * @param string|int|null $id The request ID.
	 * @param string $tool The tool name.
	 *
	 * @return array{jsonrpc: '2.0', error: array{code: int, message: string, data?: mixed}, id: string|int|null}
	 */
	public static function tool_not_found( $id, string $tool ): array {
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
	 * Create an ability not found error response.
	 *
	 * @param string|int|null $id The request ID.
	 * @param string $ability The ability name.
	 *
	 * @return array{jsonrpc: '2.0', error: array{code: int, message: string, data?: mixed}, id: string|int|null}
	 */
	public static function ability_not_found( $id, string $ability ): array {
		return self::create_error_response(
			$id,
			self::TOOL_NOT_FOUND,
			sprintf(
			/* translators: %s: ability name */
				__( 'Ability not found: %s', 'mcp-adapter' ),
				$ability
			)
		);
	}

	/**
	 * Create a prompt not found error response.
	 *
	 * @param string|int|null $id The request ID.
	 * @param string $prompt The prompt name.
	 *
	 * @return array{jsonrpc: '2.0', error: array{code: int, message: string, data?: mixed}, id: string|int|null}
	 */
	public static function prompt_not_found( $id, string $prompt ): array {
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
	 * Create a session not found error response.
	 *
	 * Used when an MCP session ID is invalid or expired. Maps to HTTP 404
	 * per the MCP specification requirement for invalid/expired sessions.
	 *
	 * @param string|int|null $id The request ID.
	 * @param string $details Optional additional details.
	 *
	 * @return array{jsonrpc: '2.0', error: array{code: int, message: string, data?: mixed}, id: string|int|null}
	 */
	public static function session_not_found( $id, string $details = '' ): array {
		$message = __( 'Session not found', 'mcp-adapter' );
		if ( $details ) {
			$message .= ': ' . $details;
		}

		return self::create_error_response( $id, self::SESSION_NOT_FOUND, $message );
	}

	/**
	 * Create a permission denied error response.
	 *
	 * @param string|int|null $id The request ID.
	 * @param string $details Optional additional details.
	 *
	 * @return array{jsonrpc: '2.0', error: array{code: int, message: string, data?: mixed}, id: string|int|null}
	 */
	public static function permission_denied( $id, string $details = '' ): array {
		$message = __( 'Permission denied', 'mcp-adapter' );
		if ( $details ) {
			$message .= ': ' . $details;
		}

		return self::create_error_response( $id, self::PERMISSION_DENIED, $message );
	}

	/**
	 * Create an unauthorized error response.
	 *
	 * @param string|int|null $id The request ID.
	 * @param string $details Optional additional details.
	 *
	 * @return array{jsonrpc: '2.0', error: array{code: int, message: string, data?: mixed}, id: string|int|null}
	 */
	public static function unauthorized( $id, string $details = '' ): array {
		$message = __( 'Unauthorized', 'mcp-adapter' );
		if ( $details ) {
			$message .= ': ' . $details;
		}

		return self::create_error_response( $id, self::UNAUTHORIZED, $message );
	}

	/**
	 * Create a modern header mismatch error response.
	 *
	 * @param string|int|null $id The request ID.
	 * @param string          $header The HTTP header name.
	 * @param string|null     $expected The expected header value.
	 * @param string|null     $actual The actual header value.
	 *
	 * @return array{jsonrpc: '2.0', error: array{code: int, message: string, data?: mixed}, id: string|int|null}
	 */
	public static function header_mismatch( $id, string $header, ?string $expected, ?string $actual ): array {
		$expected_display = null === $expected ? '(missing)' : $expected;
		$actual_display   = null === $actual ? '(missing)' : $actual;

		return self::create_error_response(
			$id,
			self::HEADER_MISMATCH,
			sprintf(
				/* translators: 1: HTTP header name, 2: header value, 3: request body value. */
				__( "Header mismatch: %1\$s header value '%2\$s' does not match body value '%3\$s'", 'mcp-adapter' ),
				$header,
				$expected_display,
				$actual_display
			),
			array(
				'header'   => $header,
				'expected' => $expected_display,
				'actual'   => $actual_display,
			)
		);
	}

	/**
	 * Create a modern missing required client capability error response.
	 *
	 * @param string|int|null    $id The request ID.
	 * @param array<string, mixed> $required Required client capabilities.
	 *
	 * @return array{jsonrpc: '2.0', error: array{code: int, message: string, data?: mixed}, id: string|int|null}
	 */
	public static function missing_required_client_capability( $id, array $required ): array {
		return self::create_error_response(
			$id,
			self::MISSING_REQUIRED_CLIENT_CAPABILITY,
			__( 'Server requires an undeclared client capability for this request', 'mcp-adapter' ),
			array( 'requiredCapabilities' => $required )
		);
	}

	/**
	 * Create a modern unsupported protocol version error response.
	 *
	 * @param string|int|null $id The request ID.
	 * @param string          $requested Requested protocol version.
	 * @param list<string>    $supported Supported protocol versions.
	 *
	 * @return array{jsonrpc: '2.0', error: array{code: int, message: string, data?: mixed}, id: string|int|null}
	 */
	public static function unsupported_protocol_version( $id, string $requested, array $supported ): array {
		return self::create_error_response(
			$id,
			self::UNSUPPORTED_PROTOCOL_VERSION,
			__( 'Unsupported protocol version', 'mcp-adapter' ),
			array(
				'supported' => $supported,
				'requested' => $requested,
			)
		);
	}

	/**
	 * Determine if an MCP error should return HTTP 200 or an HTTP error status.
	 *
	 * This method helps distinguish between transport-level errors (which should
	 * return HTTP error codes) and application-level errors (which should return
	 * HTTP 200 with a JSON-RPC error response).
	 *
	 * @param array $error_response The MCP error response.
	 *
	 * @return int The appropriate HTTP status code.
	 */
	public static function get_http_status_for_error( $error_response ): int {
		if ( ! isset( $error_response['error']['code'] ) ) {
			return 500; // Invalid error response structure
		}

		return self::mcp_error_to_http_status( $error_response['error']['code'] );
	}

	/**
	 * Translate MCP error code to appropriate HTTP status code.
	 *
	 * Maps JSON-RPC error codes to HTTP status codes according to best practices:
	 * - Transport-level errors (malformed JSON-RPC) → HTTP 4xx
	 * - Application-level errors (business logic) → HTTP 200 with JSON-RPC error
	 *
	 * @param int|string|float $mcp_error_code The MCP/JSON-RPC error code (integer, float, or string).
	 *
	 * @return int The appropriate HTTP status code.
	 */
	public static function mcp_error_to_http_status( $mcp_error_code ): int {
		// Cast to integer for comparison (handles float from DTOs)
		$code = is_numeric( $mcp_error_code ) ? (int) $mcp_error_code : 0;

		switch ( $code ) {
			// Transport-level errors - these indicate malformed requests
			case self::PARSE_ERROR:      // Invalid JSON - syntactic error
				return 400;

			case self::INVALID_REQUEST:  // Invalid JSON-RPC structure - syntactic error
			case self::HEADER_MISMATCH:
			case self::MISSING_REQUIRED_CLIENT_CAPABILITY:
			case self::UNSUPPORTED_PROTOCOL_VERSION:
				return 400;

			// Authentication and authorization errors
			case self::UNAUTHORIZED:     // Authentication required
				return 401;

			case self::PERMISSION_DENIED: // Access forbidden
				return 403;

			// Resource not found errors
			case self::RESOURCE_NOT_FOUND:
			case self::TOOL_NOT_FOUND:
			case self::PROMPT_NOT_FOUND:
			case self::SESSION_NOT_FOUND:
			case self::METHOD_NOT_FOUND:
				return 404;

			// Server errors
			case self::INTERNAL_ERROR:
			case self::SERVER_ERROR:
				return 500;

			case self::TIMEOUT_ERROR:
				return 504;

			// Application-level errors - return 200 with JSON-RPC error
			case self::INVALID_PARAMS:
			default:
				return 200;
		}
	}

	/**
	 * Validate JSON-RPC message structure.
	 *
	 * @param mixed $message The message to validate.
	 *
	 * @return true|array{jsonrpc: '2.0', error: array{code: int, message: string, data?: mixed}, id: string|int|null} Returns true if valid, or an error response if invalid.
	 */
	public static function validate_jsonrpc_message( $message ) {
		if ( ! is_array( $message ) ) {
			return self::invalid_request( null, __( 'Message must be a JSON object', 'mcp-adapter' ) );
		}

		// Must have jsonrpc field with value "2.0".
		if ( ! isset( $message['jsonrpc'] ) || self::JSONRPC_VERSION !== $message['jsonrpc'] ) {
			return self::invalid_request(
				null,
				sprintf(
				/* translators: %s: JSON-RPC version */
					__( 'jsonrpc version must be "%s"', 'mcp-adapter' ),
					self::JSONRPC_VERSION
				)
			);
		}

		// Must be either a request/notification (has method) or a response (has result/error).
		$is_request_or_notification = isset( $message['method'] );
		$is_response                = isset( $message['result'] ) || isset( $message['error'] );

		if ( ! $is_request_or_notification && ! $is_response ) {
			return self::invalid_request( null, __( 'Message must have either method or result/error field', 'mcp-adapter' ) );
		}

		// Responses must have an id field.
		if ( $is_response && ! isset( $message['id'] ) ) {
			return self::invalid_request( null, __( 'Response messages must have an id field', 'mcp-adapter' ) );
		}

		return true;
	}

	/**
	 * Create an invalid request error response.
	 *
	 * @param string|int|null $id The request ID.
	 * @param string $details Optional additional details.
	 *
	 * @return array{jsonrpc: '2.0', error: array{code: int, message: string, data?: mixed}, id: string|int|null}
	 */
	public static function invalid_request( $id, string $details = '' ): array {
		$message = __( 'Invalid Request', 'mcp-adapter' );
		if ( $details ) {
			$message .= ': ' . $details;
		}

		return self::create_error_response( $id, self::INVALID_REQUEST, $message );
	}
}
