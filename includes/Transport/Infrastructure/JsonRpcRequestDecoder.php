<?php
/**
 * Lossless JSON-RPC request decoding.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Transport\Infrastructure;

/**
 * Decodes transport input without collapsing JSON objects into PHP arrays.
 *
 * Only the JSON-RPC message object itself is converted to an associative array
 * for routing. Nested JSON objects remain stdClass instances, so the schema can
 * distinguish them from JSON lists before callback-facing normalization.
 *
 * @since n.e.x.t
 */
final class JsonRpcRequestDecoder {

	/**
	 * Decode one JSON document.
	 *
	 * @param string $json Raw JSON document.
	 * @param bool   $valid Set to whether the document is valid JSON.
	 * @return mixed Decoded single message, batch, scalar, or null.
	 */
	public static function decode( string $json, &$valid ) {
		$decoded = json_decode( $json, false );
		$valid   = JSON_ERROR_NONE === json_last_error();

		if ( ! $valid ) {
			return null;
		}

		if ( $decoded instanceof \stdClass ) {
			return get_object_vars( $decoded );
		}

		if ( is_array( $decoded ) ) {
			return array_map(
				static function ( $message ) {
					return $message instanceof \stdClass ? get_object_vars( $message ) : $message;
				},
				$decoded
			);
		}

		return $decoded;
	}
}
