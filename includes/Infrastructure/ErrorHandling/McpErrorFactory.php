<?php
/**
 * JSON-RPC and MCP protocol error factory.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Infrastructure\ErrorHandling;

use WP\McpSchema\Schemas;

/**
 * Builds logical JSON-RPC error arrays and maps error codes to HTTP status.
 *
 * The wire orchestrator hydrates errors after schema selection. Early decoding
 * or transport failures may serialize these arrays without schema hydration.
 */
class McpErrorFactory {

	public const PARSE_ERROR      = -32700;
	public const INVALID_REQUEST  = -32600;
	public const METHOD_NOT_FOUND = -32601;
	public const INVALID_PARAMS   = -32602;
	public const INTERNAL_ERROR   = -32603;

	public const SERVER_ERROR        = -32000;
	public const TIMEOUT_ERROR       = -32001;
	public const RESOURCE_NOT_FOUND  = -32002;
	public const TOOL_NOT_FOUND      = -32003;
	public const PROMPT_NOT_FOUND    = -32004;
	public const SESSION_NOT_FOUND   = -32005;
	public const PERMISSION_DENIED   = -32008;
	public const UNAUTHORIZED        = -32010;
	public const HEADER_MISMATCH     = -32020;
	public const MISSING_CAPABILITY  = -32021;
	public const UNSUPPORTED_VERSION = -32022;

	/**
	 * Build an error for malformed JSON.
	 *
	 * @param string|int|float|null $id Request identifier, or null when it cannot be determined.
	 * @param string $details Optional diagnostic details appended to the base message.
	 *
	 * @return array<string, mixed> JSON-RPC error envelope.
	 */
	public static function parse_error( $id, string $details = '' ): array {
		return self::create_error_response( $id, self::PARSE_ERROR, self::details( __( 'Parse error', 'mcp-adapter' ), $details ) );
	}

	/**
	 * Wrap an error object in a JSON-RPC response envelope.
	 *
	 * @param string|int|float|null $id Request identifier, or null when it cannot be determined.
	 * @param int $code Protocol error code.
	 * @param string $message Human-readable error message.
	 * @param mixed $data Optional error data; omitted when null.
	 *
	 * @return array<string, mixed> Error envelope, not yet validated by a selected schema.
	 */
	public static function create_error_response( $id, int $code, string $message, $data = null ): array {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => self::create_error( $code, $message, $data ),
		);
	}

	/**
	 * Build the error object used inside a JSON-RPC response.
	 *
	 * @param int $code Protocol error code.
	 * @param string $message Human-readable error message.
	 * @param mixed $data Optional error data; omitted when null.
	 *
	 * @return array<string, mixed> Error fields without a JSON-RPC envelope.
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
	 * Build an error for an unavailable method.
	 *
	 * @param string|int|float|null $id Request identifier, or null when it cannot be determined.
	 * @param string $method Requested method.
	 *
	 * @return array<string, mixed> JSON-RPC error envelope.
	 */
	public static function method_not_found( $id, string $method ): array {
		/* translators: %s: method name. */
		return self::create_error_response( $id, self::METHOD_NOT_FOUND, sprintf( __( 'Method not found: %s', 'mcp-adapter' ), $method ) );
	}

	/**
	 * Build an error for invalid method parameters.
	 *
	 * @param string|int|float|null $id Request identifier, or null when it cannot be determined.
	 * @param string $details Optional diagnostic details appended to the base message.
	 *
	 * @return array<string, mixed> JSON-RPC error envelope.
	 */
	public static function invalid_params( $id, string $details = '' ): array {
		return self::create_error_response( $id, self::INVALID_PARAMS, self::details( __( 'Invalid params', 'mcp-adapter' ), $details ) );
	}

	/**
	 * Build an error for an internal processing failure.
	 *
	 * @param string|int|float|null $id Request identifier, or null when it cannot be determined.
	 * @param string $details Optional diagnostic details appended to the base message.
	 *
	 * @return array<string, mixed> JSON-RPC error envelope.
	 */
	public static function internal_error( $id, string $details = '' ): array {
		return self::create_error_response( $id, self::INTERNAL_ERROR, self::details( __( 'Internal error', 'mcp-adapter' ), $details ) );
	}

	/**
	 * Build an error indicating that MCP functionality is disabled.
	 *
	 * @param string|int|float|null $id Request identifier, or null when it cannot be determined.
	 *
	 * @return array<string, mixed> JSON-RPC error envelope.
	 */
	public static function mcp_disabled( $id ): array {
		return self::create_error_response( $id, self::SERVER_ERROR, __( 'MCP functionality is currently disabled', 'mcp-adapter' ) );
	}

	/**
	 * Build an Invalid Params error describing validation failure.
	 *
	 * @param string|int|float|null $id Request identifier, or null when it cannot be determined.
	 * @param string $details Validation failure details.
	 *
	 * @return array<string, mixed> JSON-RPC error envelope.
	 */
	public static function validation_error( $id, string $details ): array {
		/* translators: %s: validation details. */
		return self::create_error_response( $id, self::INVALID_PARAMS, sprintf( __( 'Validation error: %s', 'mcp-adapter' ), $details ) );
	}

	/**
	 * Build an Invalid Params error for a missing parameter.
	 *
	 * @param string|int|float|null $id Request identifier, or null when it cannot be determined.
	 * @param string $parameter Missing parameter name.
	 *
	 * @return array<string, mixed> JSON-RPC error envelope.
	 */
	public static function missing_parameter( $id, string $parameter ): array {
		/* translators: %s: missing parameter name. */
		return self::create_error_response( $id, self::INVALID_PARAMS, sprintf( __( 'Missing required parameter: %s', 'mcp-adapter' ), $parameter ) );
	}

	/**
	 * Build the selected revision's error for an unavailable resource.
	 *
	 * @param string|int|float|null $id Request identifier, or null when it cannot be determined.
	 * @param string $resource_uri Requested resource URI.
	 * @param string $revision Selected schema revision.
	 *
	 * @return array<string, mixed> Invalid Params for 2026, or Resource Not Found for the legacy schema.
	 */
	public static function resource_not_found( $id, string $resource_uri, string $revision ): array {
		$code = Schemas::V2026_07_28 === $revision ? self::INVALID_PARAMS : self::RESOURCE_NOT_FOUND;
		/* translators: %s: resource URI. */
		return self::create_error_response( $id, $code, sprintf( __( 'Resource not found: %s', 'mcp-adapter' ), $resource_uri ), array( 'uri' => $resource_uri ) );
	}

	/**
	 * Build an Invalid Params error for an unavailable tool.
	 *
	 * @param string|int|float|null $id Request identifier, or null when it cannot be determined.
	 * @param string $tool Requested tool name.
	 *
	 * @return array<string, mixed> JSON-RPC error envelope.
	 */
	public static function tool_not_found( $id, string $tool ): array {
		/* translators: %s: tool name. */
		return self::create_error_response( $id, self::INVALID_PARAMS, sprintf( __( 'Tool not found: %s', 'mcp-adapter' ), $tool ) );
	}

	/**
	 * Build an error for a missing WordPress Ability.
	 *
	 * @param string|int|float|null $id Request identifier, or null when it cannot be determined.
	 * @param string $ability Requested WordPress Ability name.
	 *
	 * @return array<string, mixed> JSON-RPC error envelope.
	 */
	public static function ability_not_found( $id, string $ability ): array {
		/* translators: %s: ability name. */
		return self::create_error_response( $id, self::TOOL_NOT_FOUND, sprintf( __( 'Ability not found: %s', 'mcp-adapter' ), $ability ) );
	}

	/**
	 * Build an Invalid Params error for an unavailable prompt.
	 *
	 * @param string|int|float|null $id Request identifier, or null when it cannot be determined.
	 * @param string $prompt Requested prompt name.
	 *
	 * @return array<string, mixed> JSON-RPC error envelope.
	 */
	public static function prompt_not_found( $id, string $prompt ): array {
		/* translators: %s: prompt name. */
		return self::create_error_response( $id, self::INVALID_PARAMS, sprintf( __( 'Prompt not found: %s', 'mcp-adapter' ), $prompt ) );
	}

	/**
	 * Build an error for an unavailable legacy session.
	 *
	 * @param string|int|float|null $id Request identifier, or null when it cannot be determined.
	 * @param string $details Optional diagnostic details appended to the base message.
	 *
	 * @return array<string, mixed> JSON-RPC error envelope.
	 */
	public static function session_not_found( $id, string $details = '' ): array {
		return self::create_error_response( $id, self::SESSION_NOT_FOUND, self::details( __( 'Session not found', 'mcp-adapter' ), $details ) );
	}

	/**
	 * Build an error for denied access.
	 *
	 * @param string|int|float|null $id Request identifier, or null when it cannot be determined.
	 * @param string $details Optional diagnostic details appended to the base message.
	 *
	 * @return array<string, mixed> JSON-RPC error envelope.
	 */
	public static function permission_denied( $id, string $details = '' ): array {
		return self::create_error_response( $id, self::PERMISSION_DENIED, self::details( __( 'Permission denied', 'mcp-adapter' ), $details ) );
	}

	/**
	 * Build an error for missing or invalid authentication.
	 *
	 * @param string|int|float|null $id Request identifier, or null when it cannot be determined.
	 * @param string $details Optional diagnostic details appended to the base message.
	 *
	 * @return array<string, mixed> JSON-RPC error envelope.
	 */
	public static function unauthorized( $id, string $details = '' ): array {
		return self::create_error_response( $id, self::UNAUTHORIZED, self::details( __( 'Unauthorized', 'mcp-adapter' ), $details ) );
	}

	/**
	 * Build an error for an invalid JSON-RPC request.
	 *
	 * @param string|int|float|null $id Request identifier, or null when it cannot be determined.
	 * @param string $details Optional diagnostic details appended to the base message.
	 *
	 * @return array<string, mixed> JSON-RPC error envelope.
	 */
	public static function invalid_request( $id, string $details = '' ): array {
		return self::create_error_response( $id, self::INVALID_REQUEST, self::details( __( 'Invalid Request', 'mcp-adapter' ), $details ) );
	}

	/**
	 * Build a modern protocol header-mismatch error.
	 *
	 * @param string|int|float|null $id Request identifier, or null when it cannot be determined.
	 * @param string $details Missing or mismatched header details.
	 *
	 * @return array<string, mixed> JSON-RPC error envelope.
	 */
	public static function header_mismatch( $id, string $details ): array {
		return self::create_error_response( $id, self::HEADER_MISMATCH, self::details( 'Header mismatch', $details ) );
	}

	/**
	 * Build a modern unsupported-version error with supported alternatives.
	 *
	 * @param string|int|float|null $id Request identifier, or null when it cannot be determined.
	 * @param string $requested Requested protocol identifier.
	 * @param list<string> $supported Supported schema revision identifiers.
	 *
	 * @return array<string, mixed> Error envelope containing requested and supported versions.
	 */
	public static function unsupported_protocol_version( $id, string $requested, array $supported ): array {
		return self::create_error_response(
			$id,
			self::UNSUPPORTED_VERSION,
			'Unsupported protocol version',
			array(
				'requested' => $requested,
				'supported' => array_values( $supported ),
			)
		);
	}

	/**
	 * Map an error envelope using the default HTTP status policy.
	 *
	 * Revision-specific overrides are applied by McpWireOrchestrator.
	 *
	 * @param mixed $error_response Logical error envelope.
	 *
	 * @return int HTTP status; 200 when no recognized error code is present.
	 */
	public static function get_http_status_for_error( $error_response ): int {
		$code = is_array( $error_response ) ? ( $error_response['error']['code'] ?? 0 ) : 0;
		return self::mcp_error_to_http_status( $code );
	}

	/**
	 * Map a protocol error code using the default HTTP status policy.
	 *
	 * The orchestrator overrides Invalid Params to HTTP 400 for the 2026 revision.
	 *
	 * @param mixed $mcp_error_code Numeric protocol error code.
	 *
	 * @return int HTTP status; unmapped codes and Invalid Params default to 200.
	 */
	public static function mcp_error_to_http_status( $mcp_error_code ): int {
		$code = is_numeric( $mcp_error_code ) ? (int) $mcp_error_code : 0;
		switch ( $code ) {
			case self::PARSE_ERROR:
			case self::INVALID_REQUEST:
			case self::HEADER_MISMATCH:
			case self::UNSUPPORTED_VERSION:
			case self::MISSING_CAPABILITY:
				return 400;
			case self::UNAUTHORIZED:
				return 401;
			case self::PERMISSION_DENIED:
				return 403;
			case self::RESOURCE_NOT_FOUND:
			case self::TOOL_NOT_FOUND:
			case self::PROMPT_NOT_FOUND:
			case self::SESSION_NOT_FOUND:
			case self::METHOD_NOT_FOUND:
				return 404;
			case self::INTERNAL_ERROR:
			case self::SERVER_ERROR:
				return 500;
			case self::TIMEOUT_ERROR:
				return 504;
			case self::INVALID_PARAMS:
			default:
				return 200;
		}
	}

	/**
	 * Append optional diagnostic details to a base message.
	 *
	 * @param string $message Base message.
	 * @param string $details Diagnostic details, or an empty string.
	 *
	 * @return string Base message with non-empty details appended.
	 */
	private static function details( string $message, string $details ): string {
		return '' === $details ? $message : $message . ': ' . $details;
	}
}
