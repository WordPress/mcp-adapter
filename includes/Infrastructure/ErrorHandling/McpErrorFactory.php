<?php
/**
 * Factory class for creating MCP error responses.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Infrastructure\ErrorHandling;

use WP\McpSchema\Generated\V20251125Constants;
use WP\McpSchema\Generated\V20260728Constants;

/**
 * Factory for creating standardized MCP error responses.
 *
 * This class provides static methods for creating various types of JSON-RPC
 * error responses according to the MCP specification. All methods return
 * revision-neutral arrays in wire shape.
 *
 * These envelopes are built here rather than through the schema encoder. A
 * JSON-RPC error is a transport-level structure: the specification requires a
 * null id when the request id could not be determined, which the protocol's own
 * JSONRPCErrorResponse type cannot express, and an error path that can itself
 * fail to encode is worse than no validation at all. Revision-specific typed
 * 2026 errors are the exception: their concrete encoder validates the whole
 * envelope and required data payload before emission.
 */
class McpErrorFactory {

	/**
	 * Standard JSON-RPC error codes as defined in the specification.
	 */
	public const PARSE_ERROR                        = V20251125Constants::PARSE_ERROR;
	public const INVALID_REQUEST                    = V20251125Constants::INVALID_REQUEST;
	public const METHOD_NOT_FOUND                   = V20251125Constants::METHOD_NOT_FOUND;
	public const INVALID_PARAMS                     = V20251125Constants::INVALID_PARAMS;
	public const INTERNAL_ERROR                     = V20251125Constants::INTERNAL_ERROR;
	public const HEADER_MISMATCH                    = V20260728Constants::HEADER_MISMATCH;
	public const MISSING_REQUIRED_CLIENT_CAPABILITY = V20260728Constants::MISSING_REQUIRED_CLIENT_CAPABILITY;
	public const UNSUPPORTED_PROTOCOL_VERSION       = V20260728Constants::UNSUPPORTED_PROTOCOL_VERSION;

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
	 * @return array<string, mixed> JSON-RPC error envelope in wire shape (`id` omitted when null).
	 */
	public static function parse_error( $id, string $details = '' ): array {
		$message = __( 'Parse error', 'mcp-adapter' );
		if ( $details ) {
			$message .= ': ' . $details;
		}

		return self::create_error_response( $id, self::PARSE_ERROR, $message );
	}

	/**
	 * Create a standardized JSON-RPC error response DTO.
	 *
	 * @param string|int|null $id The request ID (JSON-RPC allows string, int, or null).
	 * @param int $code The error code.
	 * @param string $message The error message.
	 * @param mixed|null $data Optional additional error data.
	 *
	 * @return array<string, mixed> JSON-RPC error envelope in wire shape (`id` omitted when null).
	 */
	public static function create_error_response( $id, int $code, string $message, $data = null ): array {
		$response = array(
			'jsonrpc' => V20251125Constants::JSONRPC_VERSION,
			'error'   => self::create_error( $code, $message, $data ),
		);

		// A null id means the request id could not be determined; the key is
		// omitted rather than sent as null.
		if ( null !== $id ) {
			$response['id'] = $id;
		}

		return $response;
	}

	/**
	 * Create a JSON-RPC error object.
	 *
	 * @param int $code The error code.
	 * @param string $message The error message.
	 * @param mixed|null $data Optional additional error data.
	 *
	 * @return array<string, mixed> Error object in wire shape (`data` omitted when null).
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
	 * @return array<string, mixed> JSON-RPC error envelope in wire shape (`id` omitted when null).
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
	 * @return array<string, mixed> JSON-RPC error envelope in wire shape (`id` omitted when null).
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
	 * @return array<string, mixed> JSON-RPC error envelope in wire shape (`id` omitted when null).
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
	 * @return array<string, mixed> JSON-RPC error envelope in wire shape (`id` omitted when null).
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
	 * @return array<string, mixed> JSON-RPC error envelope in wire shape (`id` omitted when null).
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
	 * @return array<string, mixed> JSON-RPC error envelope in wire shape (`id` omitted when null).
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
	 * @param string      $resource_uri The resource identifier.
	 * @param string|null $revision Exact request revision, or null for legacy compatibility.
	 *
	 * @return array<string, mixed> JSON-RPC error envelope in wire shape (`id` omitted when null).
	 */
	public static function resource_not_found( $id, string $resource_uri, ?string $revision = null ): array {
		if ( self::is_modern_revision( $revision ) ) {
			return self::create_error_response(
				$id,
				self::INVALID_PARAMS,
				sprintf(
					/* translators: %s: resource identifier. */
					__( 'Resource not found: %s', 'mcp-adapter' ),
					$resource_uri
				)
			);
		}

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
	 * @param string      $tool The tool name.
	 * @param string|null $revision Exact request revision, or null for legacy compatibility.
	 *
	 * @return array<string, mixed> JSON-RPC error envelope in wire shape (`id` omitted when null).
	 */
	public static function tool_not_found( $id, string $tool, ?string $revision = null ): array {
		if ( self::is_modern_revision( $revision ) ) {
			return self::create_error_response(
				$id,
				self::INVALID_PARAMS,
				sprintf(
					/* translators: %s: tool name. */
					__( 'Tool not found: %s', 'mcp-adapter' ),
					$tool
				)
			);
		}

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
	 * @return array<string, mixed> JSON-RPC error envelope in wire shape (`id` omitted when null).
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
	 * @param string      $prompt The prompt name.
	 * @param string|null $revision Exact request revision, or null for legacy compatibility.
	 *
	 * @return array<string, mixed> JSON-RPC error envelope in wire shape (`id` omitted when null).
	 */
	public static function prompt_not_found( $id, string $prompt, ?string $revision = null ): array {
		if ( self::is_modern_revision( $revision ) ) {
			return self::create_error_response(
				$id,
				self::INVALID_PARAMS,
				sprintf(
					/* translators: %s: prompt name. */
					__( 'Prompt not found: %s', 'mcp-adapter' ),
					$prompt
				)
			);
		}

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
	 * @return array<string, mixed> JSON-RPC error envelope in wire shape (`id` omitted when null).
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
	 * @return array<string, mixed> JSON-RPC error envelope in wire shape (`id` omitted when null).
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
	 * @return array<string, mixed> JSON-RPC error envelope in wire shape (`id` omitted when null).
	 */
	public static function unauthorized( $id, string $details = '' ): array {
		$message = __( 'Unauthorized', 'mcp-adapter' );
		if ( $details ) {
			$message .= ': ' . $details;
		}

		return self::create_error_response( $id, self::UNAUTHORIZED, $message );
	}

	/**
	 * Determine if an MCP error should return HTTP 200 or an HTTP error status.
	 *
	 * This method helps distinguish between transport-level errors (which should
	 * return HTTP error codes) and application-level errors (which should return
	 * HTTP 200 with a JSON-RPC error response).
	 *
	 * @param array<string, mixed> $error_response The MCP error response array.
	 * @param string|null          $revision Exact request revision when known.
	 *
	 * @return int The appropriate HTTP status code.
	 */
	public static function get_http_status_for_error( array $error_response, ?string $revision = null ): int {
		if ( ! isset( $error_response['error']['code'] ) ) {
			return 500; // Invalid error response structure
		}

		return self::mcp_error_to_http_status( $error_response['error']['code'], $revision );
	}

	/**
	 * Translate MCP error code to appropriate HTTP status code.
	 *
	 * Maps JSON-RPC error codes to HTTP status codes according to best practices:
	 * - Transport-level errors (malformed JSON-RPC) → HTTP 4xx
	 * - Application-level errors (business logic) → HTTP 200 with JSON-RPC error
	 *
	 * @param int|string|float $mcp_error_code The MCP/JSON-RPC error code (integer, float, or string).
	 * @param string|null      $revision Exact request revision when known.
	 *
	 * @return int The appropriate HTTP status code.
	 */
	public static function mcp_error_to_http_status( $mcp_error_code, ?string $revision = null ): int {
		// Cast to integer for comparison (handles float from DTOs)
		$code = is_numeric( $mcp_error_code ) ? (int) $mcp_error_code : 0;

		if ( in_array( $code, array( self::HEADER_MISMATCH, self::MISSING_REQUIRED_CLIENT_CAPABILITY, self::UNSUPPORTED_PROTOCOL_VERSION ), true ) ) {
			return 400;
		}

		if ( self::is_modern_revision( $revision ) ) {
			switch ( $code ) {
				case self::PARSE_ERROR:
				case self::INVALID_REQUEST:
					return 400;

				case self::UNAUTHORIZED:
					return 401;

				case self::PERMISSION_DENIED:
					return 403;

				case self::INTERNAL_ERROR:
				case self::SERVER_ERROR:
					return 500;

				case self::TIMEOUT_ERROR:
					return 504;

				default:
					return 200;
			}
		}

		switch ( $code ) {
			// Transport-level errors - these indicate malformed requests
			case self::PARSE_ERROR:      // Invalid JSON - syntactic error
				return 400;

			case self::INVALID_REQUEST:  // Invalid JSON-RPC structure - syntactic error
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
	 * @return true|array<string, mixed> Returns true if valid, or a JSON-RPC error envelope array if invalid.
	 */
	public static function validate_jsonrpc_message( $message ) {
		if ( ! is_array( $message ) ) {
			return self::invalid_request( null, __( 'Message must be a JSON object', 'mcp-adapter' ) );
		}

		// Must have jsonrpc field with value "2.0".
		if ( ! isset( $message['jsonrpc'] ) || V20251125Constants::JSONRPC_VERSION !== $message['jsonrpc'] ) {
			return self::invalid_request(
				null,
				sprintf(
				/* translators: %s: JSON-RPC version */
					__( 'jsonrpc version must be "%s"', 'mcp-adapter' ),
					V20251125Constants::JSONRPC_VERSION
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
	 * @return array<string, mixed> JSON-RPC error envelope in wire shape (`id` omitted when null).
	 */
	public static function invalid_request( $id, string $details = '' ): array {
		$message = __( 'Invalid Request', 'mcp-adapter' );
		if ( $details ) {
			$message .= ': ' . $details;
		}

		return self::create_error_response( $id, self::INVALID_REQUEST, $message );
	}

	/**
	 * Whether the error policy belongs to the modern stateless revision.
	 */
	private static function is_modern_revision( ?string $revision ): bool {
		return V20260728Constants::LATEST_PROTOCOL_VERSION === $revision;
	}
}
