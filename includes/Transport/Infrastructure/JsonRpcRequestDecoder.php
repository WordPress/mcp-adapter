<?php
/**
 * Identity-preserving JSON-RPC request decoding.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Transport\Infrastructure;

/**
 * Decodes JSON-RPC input without collapsing JSON objects into PHP arrays.
 *
 * @since n.e.x.t
 */
final class JsonRpcRequestDecoder {

	/**
	 * Decode raw JSON while preserving object/list identity.
	 *
	 * @param string $json Raw JSON bytes.
	 *
	 * @return mixed The decoded JSON value. Objects are stdClass instances and arrays are lists.
	 *
	 * @throws \JsonException When the input is not valid JSON.
	 */
	public static function decode( string $json ) {
		return json_decode( $json, false, 512, JSON_THROW_ON_ERROR );
	}

	/**
	 * Derive the associative representation consumed by Adapter callbacks.
	 *
	 * @param mixed $value An identity-preserving decoded JSON value.
	 *
	 * @return mixed The same value with objects recursively converted to arrays.
	 */
	public static function to_associative( $value ) {
		if ( $value instanceof \stdClass ) {
			$value = get_object_vars( $value );
		}

		if ( ! is_array( $value ) ) {
			return $value;
		}

		$converted = array();
		foreach ( $value as $key => $item ) {
			$converted[ $key ] = self::to_associative( $item );
		}

		return $converted;
	}

	/**
	 * Determine whether a decoded root is a non-empty JSON-RPC batch.
	 *
	 * An empty JSON array is invalid as a batch and is processed as one invalid
	 * request so the caller emits a single JSON-RPC error object.
	 *
	 * @param mixed $body Identity-preserving decoded request body.
	 *
	 * @return bool True for a non-empty JSON array, false otherwise.
	 */
	public static function is_batch_request( $body ): bool {
		return is_array( $body ) && self::is_non_empty_list( $body );
	}

	/**
	 * Normalize a decoded request root to associative message arrays.
	 *
	 * @param mixed $body Identity-preserving decoded request body.
	 *
	 * @return array<int, array<mixed>> Messages ready for existing handlers.
	 */
	public static function normalize_messages( $body ): array {
		$messages   = self::is_batch_request( $body ) ? $body : array( $body );
		$normalized = array();

		foreach ( $messages as $message ) {
			$associative  = self::to_associative( $message );
			$normalized[] = is_array( $associative ) ? $associative : array();
		}

		return $normalized;
	}

	/**
	 * Determine whether an array is a non-empty list.
	 *
	 * @param array<mixed> $value Value to inspect.
	 *
	 * @return bool True for sequential integer keys starting at zero.
	 */
	private static function is_non_empty_list( array $value ): bool {
		return array() !== $value && array_keys( $value ) === range( 0, count( $value ) - 1 );
	}
}
