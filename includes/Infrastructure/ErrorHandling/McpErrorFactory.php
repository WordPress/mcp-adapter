<?php
/**
 * JSON-RPC and MCP protocol error factory.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Infrastructure\ErrorHandling;

use WP\McpSchema\Schemas;

/** Builds revision-neutral error arrays for final schema validation. */
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

	/** @param string|int|float|null $id Request ID. @return array<string, mixed> */
	public static function parse_error( $id, string $details = '' ): array {
		return self::create_error_response( $id, self::PARSE_ERROR, self::details( __( 'Parse error', 'mcp-adapter' ), $details ) );
	}

	/**
	 * @param string|int|float|null $id Request ID.
	 * @param mixed $data Error data.
	 * @return array<string, mixed>
	 */
	public static function create_error_response( $id, int $code, string $message, $data = null ): array {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => self::create_error( $code, $message, $data ),
		);
	}

	/** @param mixed $data Error data. @return array<string, mixed> */
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

	/** @param string|int|float|null $id Request ID. @return array<string, mixed> */
	public static function method_not_found( $id, string $method ): array {
		/* translators: %s: method name. */
		return self::create_error_response( $id, self::METHOD_NOT_FOUND, sprintf( __( 'Method not found: %s', 'mcp-adapter' ), $method ) );
	}

	/** @param string|int|float|null $id Request ID. @return array<string, mixed> */
	public static function invalid_params( $id, string $details = '' ): array {
		return self::create_error_response( $id, self::INVALID_PARAMS, self::details( __( 'Invalid params', 'mcp-adapter' ), $details ) );
	}

	/** @param string|int|float|null $id Request ID. @return array<string, mixed> */
	public static function internal_error( $id, string $details = '' ): array {
		return self::create_error_response( $id, self::INTERNAL_ERROR, self::details( __( 'Internal error', 'mcp-adapter' ), $details ) );
	}

	/** @param string|int|float|null $id Request ID. @return array<string, mixed> */
	public static function mcp_disabled( $id ): array {
		return self::create_error_response( $id, self::SERVER_ERROR, __( 'MCP functionality is currently disabled', 'mcp-adapter' ) );
	}

	/** @param string|int|float|null $id Request ID. @return array<string, mixed> */
	public static function validation_error( $id, string $details ): array {
		/* translators: %s: validation details. */
		return self::create_error_response( $id, self::INVALID_PARAMS, sprintf( __( 'Validation error: %s', 'mcp-adapter' ), $details ) );
	}

	/** @param string|int|float|null $id Request ID. @return array<string, mixed> */
	public static function missing_parameter( $id, string $parameter ): array {
		/* translators: %s: missing parameter name. */
		return self::create_error_response( $id, self::INVALID_PARAMS, sprintf( __( 'Missing required parameter: %s', 'mcp-adapter' ), $parameter ) );
	}

	/** @param string|int|float|null $id Request ID. @return array<string, mixed> */
	public static function resource_not_found( $id, string $resource_uri, string $revision ): array {
		$code = Schemas::V2026_07_28 === $revision ? self::INVALID_PARAMS : self::RESOURCE_NOT_FOUND;
		/* translators: %s: resource URI. */
		return self::create_error_response( $id, $code, sprintf( __( 'Resource not found: %s', 'mcp-adapter' ), $resource_uri ), array( 'uri' => $resource_uri ) );
	}

	/** @param string|int|float|null $id Request ID. @return array<string, mixed> */
	public static function tool_not_found( $id, string $tool ): array {
		/* translators: %s: tool name. */
		return self::create_error_response( $id, self::TOOL_NOT_FOUND, sprintf( __( 'Tool not found: %s', 'mcp-adapter' ), $tool ) );
	}

	/** @param string|int|float|null $id Request ID. @return array<string, mixed> */
	public static function ability_not_found( $id, string $ability ): array {
		/* translators: %s: ability name. */
		return self::create_error_response( $id, self::TOOL_NOT_FOUND, sprintf( __( 'Ability not found: %s', 'mcp-adapter' ), $ability ) );
	}

	/** @param string|int|float|null $id Request ID. @return array<string, mixed> */
	public static function prompt_not_found( $id, string $prompt ): array {
		/* translators: %s: prompt name. */
		return self::create_error_response( $id, self::PROMPT_NOT_FOUND, sprintf( __( 'Prompt not found: %s', 'mcp-adapter' ), $prompt ) );
	}

	/** @param string|int|float|null $id Request ID. @return array<string, mixed> */
	public static function session_not_found( $id, string $details = '' ): array {
		return self::create_error_response( $id, self::SESSION_NOT_FOUND, self::details( __( 'Session not found', 'mcp-adapter' ), $details ) );
	}

	/** @param string|int|float|null $id Request ID. @return array<string, mixed> */
	public static function permission_denied( $id, string $details = '' ): array {
		return self::create_error_response( $id, self::PERMISSION_DENIED, self::details( __( 'Permission denied', 'mcp-adapter' ), $details ) );
	}

	/** @param string|int|float|null $id Request ID. @return array<string, mixed> */
	public static function unauthorized( $id, string $details = '' ): array {
		return self::create_error_response( $id, self::UNAUTHORIZED, self::details( __( 'Unauthorized', 'mcp-adapter' ), $details ) );
	}

	/** @param string|int|float|null $id Request ID. @return array<string, mixed> */
	public static function invalid_request( $id, string $details = '' ): array {
		return self::create_error_response( $id, self::INVALID_REQUEST, self::details( __( 'Invalid Request', 'mcp-adapter' ), $details ) );
	}

	/** @param string|int|float|null $id Request ID. @return array<string, mixed> */
	public static function header_mismatch( $id, string $details ): array {
		return self::create_error_response( $id, self::HEADER_MISMATCH, self::details( 'Header mismatch', $details ) );
	}

	/** @param string|int|float|null $id Request ID. @param list<string> $supported Supported revisions. @return array<string, mixed> */
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

	/** @param mixed $error_response Error response. Map an error envelope to HTTP status. */
	public static function get_http_status_for_error( $error_response ): int {
		$code = is_array( $error_response ) ? ( $error_response['error']['code'] ?? 0 ) : 0;
		return self::mcp_error_to_http_status( $code );
	}

	/** @param mixed $mcp_error_code Error code. Map MCP/JSON-RPC code to HTTP status. */
	public static function mcp_error_to_http_status( $mcp_error_code ): int {
		$code = is_numeric( $mcp_error_code ) ? (int) $mcp_error_code : 0;
		switch ( $code ) {
			case self::PARSE_ERROR:
			case self::INVALID_REQUEST:
			case self::HEADER_MISMATCH:
			case self::UNSUPPORTED_VERSION:
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

	/** Append details to a localized base message. */
	private static function details( string $message, string $details ): string {
		return '' === $details ? $message : $message . ': ' . $details;
	}
}
