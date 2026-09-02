<?php
/**
 * Identity-preserving JSON-RPC request decoder.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Transport\Infrastructure;

/**
 * Decodes raw JSON once and protects PHP's native integer boundary.
 *
 * @since n.e.x.t
 */
final class JsonRpcRequestDecoder {

	/** Maximum JSON value depth. */
	private const MAX_DEPTH = 512;

	/**
	 * Decode one JSON-RPC object.
	 *
	 * @throws \InvalidArgumentException When JSON is malformed, unsafe, or not one object.
	 * @since n.e.x.t
	 */
	public function decode( string $json ): \stdClass {
		$this->assert_native_integers( $json );

		try {
			$value = json_decode( $json, false, self::MAX_DEPTH, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $exception ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is protocol validation data, not HTML output.
			throw new \InvalidArgumentException( 'Invalid JSON: ' . $exception->getMessage(), 0, $exception );
		}

		if ( ! $value instanceof \stdClass ) {
			throw new \UnexpectedValueException( 'The JSON-RPC payload must be one JSON object; batches are not supported.' );
		}
		$this->assert_finite_numbers( $value );

		return $value;
	}

	/**
	 * Convert a decoded object graph to associative callback input.
	 *
	 * @param mixed $value Decoded value.
	 * @return mixed
	 * @since n.e.x.t
	 */
	public function to_associative( $value ) {
		if ( $value instanceof \stdClass ) {
			$result = array();
			foreach ( get_object_vars( $value ) as $key => $item ) {
				$result[ $key ] = $this->to_associative( $item );
			}

			return $result;
		}

		if ( is_array( $value ) ) {
			return array_map( array( $this, 'to_associative' ), $value );
		}

		return $value;
	}

	/** Reject integer tokens that json_decode() would silently turn into floats. */
	private function assert_native_integers( string $json ): void {
		$length    = strlen( $json );
		$in_string = false;
		$escaped   = false;

		for ( $index = 0; $index < $length; ++$index ) {
			$character = $json[ $index ];
			if ( $in_string ) {
				if ( $escaped ) {
					$escaped = false;
				} elseif ( '\\' === $character ) {
					$escaped = true;
				} elseif ( '"' === $character ) {
					$in_string = false;
				}
				continue;
			}

			if ( '"' === $character ) {
				$in_string = true;
				continue;
			}

			if ( '-' !== $character && ( $character < '0' || $character > '9' ) ) {
				continue;
			}

			$start  = $index;
			$cursor = $index;
			if ( '-' === $json[ $cursor ] ) {
				++$cursor;
			}
			if ( $cursor >= $length || $json[ $cursor ] < '0' || $json[ $cursor ] > '9' ) {
				continue;
			}

			if ( '0' === $json[ $cursor ] ) {
				++$cursor;
			} else {
				while ( $cursor < $length && $json[ $cursor ] >= '0' && $json[ $cursor ] <= '9' ) {
					++$cursor;
				}
			}

			$integer = true;
			if ( $cursor < $length && '.' === $json[ $cursor ] ) {
				$integer = false;
				++$cursor;
				while ( $cursor < $length && $json[ $cursor ] >= '0' && $json[ $cursor ] <= '9' ) {
					++$cursor;
				}
			}
			if ( $cursor < $length && ( 'e' === $json[ $cursor ] || 'E' === $json[ $cursor ] ) ) {
				$integer = false;
				++$cursor;
				if ( $cursor < $length && ( '+' === $json[ $cursor ] || '-' === $json[ $cursor ] ) ) {
					++$cursor;
				}
				while ( $cursor < $length && $json[ $cursor ] >= '0' && $json[ $cursor ] <= '9' ) {
					++$cursor;
				}
			}

			$index = $cursor - 1;
			if ( ! $integer ) {
				continue;
			}

			$token = substr( $json, $start, $cursor - $start );
			if ( ! $this->is_native_integer( $token ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is protocol validation data, not HTML output.
				throw new \RangeException( sprintf( 'JSON integer %s exceeds the native PHP integer range.', $token ) );
			}
		}
	}

	/**
	 * Reject non-finite floats that cannot round-trip through JSON.
	 *
	 * @param mixed $value Decoded JSON value.
	 */
	private function assert_finite_numbers( $value ): void {
		if ( is_float( $value ) && ! is_finite( $value ) ) {
			throw new \RangeException( 'JSON number exceeds the finite PHP floating-point range.' );
		}
		if ( $value instanceof \stdClass ) {
			$value = get_object_vars( $value );
		}
		if ( ! is_array( $value ) ) {
			return;
		}

		foreach ( $value as $item ) {
			$this->assert_finite_numbers( $item );
		}
	}

	/** Check one integer token against the current native range. */
	private function is_native_integer( string $token ): bool {
		$negative = '-' === $token[0];
		$digits   = ltrim( ltrim( $token, '-' ), '0' );
		if ( '' === $digits ) {
			return true;
		}

		$limit = $negative ? ltrim( (string) PHP_INT_MIN, '-' ) : (string) PHP_INT_MAX;
		if ( strlen( $digits ) !== strlen( $limit ) ) {
			return strlen( $digits ) < strlen( $limit );
		}

		return strcmp( $digits, $limit ) <= 0;
	}
}
