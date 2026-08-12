<?php
/**
 * JSON-RPC request decoder that preserves selected JSON object shapes.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Transport\Infrastructure;

/**
 * Decodes request JSON while retaining object/list identity for tools/call.
 *
 * @internal
 * @since n.e.x.t
 */
final class JsonRpcRequestDecoder {

	/**
	 * Decode and normalize a JSON-RPC request or batch.
	 *
	 * The 2026-07-28 clientCapabilities and arguments fields remain stdClass
	 * when encoded as JSON objects. Their validation boundary can therefore
	 * distinguish {} from [] before normalizing arguments for ability handlers.
	 *
	 * @since n.e.x.t
	 *
	 * @param string $json JSON request body.
	 * @return mixed
	 */
	public static function decode( string $json ) {
		$decoded = json_decode( $json );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return null;
		}

		if ( $decoded instanceof \stdClass ) {
			return self::normalize_message( $decoded );
		}

		if ( is_array( $decoded ) ) {
			return array_map(
				static function ( $message ) {
					return $message instanceof \stdClass
						? self::normalize_message( $message )
						: self::normalize_value( $message );
				},
				$decoded
			);
		}

		return $decoded;
	}

	/**
	 * Normalize one JSON-RPC message.
	 *
	 * @param \stdClass $message Decoded message object.
	 * @return array<string, mixed>
	 */
	private static function normalize_message( \stdClass $message ): array {
		$data   = get_object_vars( $message );
		$method = $data['method'] ?? null;
		if ( array_key_exists( 'params', $data ) ) {
			$data['params'] = self::normalize_params( $data['params'], 'tools/call' === $method );
		}

		foreach ( $data as $key => $value ) {
			if ( 'params' === $key ) {
				continue;
			}

			$data[ $key ] = self::normalize_value( $value );
		}

		return $data;
	}

	/**
	 * Normalize request parameters while preserving the two object-shaped fields.
	 *
	 * @param mixed $params Decoded params value.
	 * @param bool  $preserve_tool_shapes Whether this is tools/call.
	 * @return mixed
	 */
	private static function normalize_params( $params, bool $preserve_tool_shapes ) {
		if ( ! $params instanceof \stdClass ) {
			return self::normalize_value( $params );
		}

		$data = get_object_vars( $params );
		foreach ( $data as $key => $value ) {
			if ( $preserve_tool_shapes && 'arguments' === $key ) {
				continue;
			}

			if ( '_meta' === $key ) {
				$data[ $key ] = self::normalize_meta( $value, $preserve_tool_shapes );
				continue;
			}

			$data[ $key ] = self::normalize_value( $value );
		}

		return $data;
	}

	/**
	 * Normalize request metadata while preserving clientCapabilities identity.
	 *
	 * @param mixed $meta Decoded metadata value.
	 * @param bool  $preserve_client_capabilities Whether this is tools/call.
	 * @return mixed
	 */
	private static function normalize_meta( $meta, bool $preserve_client_capabilities ) {
		if ( ! $meta instanceof \stdClass ) {
			return self::normalize_value( $meta );
		}

		$data = get_object_vars( $meta );
		foreach ( $data as $key => $value ) {
			if ( $preserve_client_capabilities && 'io.modelcontextprotocol/clientCapabilities' === $key ) {
				continue;
			}

			$data[ $key ] = self::normalize_value( $value );
		}

		return $data;
	}

	/**
	 * Recursively normalize decoded JSON objects to arrays.
	 *
	 * @param mixed $value Decoded value.
	 * @return mixed
	 */
	private static function normalize_value( $value ) {
		if ( $value instanceof \stdClass ) {
			$value = get_object_vars( $value );
		}

		if ( ! is_array( $value ) ) {
			return $value;
		}

		return array_map( array( self::class, 'normalize_value' ), $value );
	}
}
