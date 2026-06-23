<?php
/**
 * SchemaTransformer class for converting flattened schemas to MCP-compatible object schemas.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Domain\Utils;

/**
 * SchemaTransformer class.
 *
 * This class transforms flattened JSON schemas (e.g., type: string, number, boolean, array)
 * into MCP-compatible object schemas. MCP requires all tool input schemas to be of type "object".
 *
 * @package McpAdapter
 */
class SchemaTransformer {
	/**
	 * Maximum recursion depth for the normalize walk.
	 *
	 * Guards against pathological/cyclic structures blowing the PHP stack.
	 *
	 * @var int
	 */
	private const MAX_DEPTH = 64;

	/**
	 * WordPress-only argument keys that are not part of the JSON Schema spec.
	 *
	 * These must be stripped from the emitted MCP schema. They can carry
	 * object-valued callables (e.g. sanitize_callback => array( $obj, 'method' ))
	 * whose object graph may be cyclic.
	 *
	 * @var array<int,string>
	 */
	// phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- False positive: sniff mistakes array() commas for multi-const commas (only handles short syntax).
	private const WP_ONLY_KEYS = array(
		'sanitize_callback',
		'validate_callback',
		'arg_options',
	);

	/**
	 * Transform a schema to MCP-compatible object format.
	 *
	 * If the schema is already an object type, it is returned unchanged.
	 * If the schema is a flattened type (string, number, boolean, array), it is
	 * wrapped in an object structure with a single property (defaults to "input").
	 *
	 * @param array<string,mixed>|null $schema The JSON schema to transform.
	 * @param string $wrapper_key Property name to use when wrapping non-object schemas.
	 *
	 * @return array<string,mixed> Array containing 'schema', 'was_transformed' (bool), and 'wrapper_property' when transformed.
	 */
	public static function transform_to_object_schema( ?array $schema, string $wrapper_key = 'input' ): array {
		// Convert any objects to arrays and strip empty properties.
		// Abilities may contain objects from JSON decode cycles. Empty properties must be removed
		// because MCP expects properties to be a JSON object, and PHP serializes empty arrays as [].
		$schema = self::normalize( $schema );

		// Handle null or empty schema - return minimal valid MCP object schema.
		if ( empty( $schema ) ) {
			return array(
				'schema'           => array(
					'type' => 'object',
				),
				'was_transformed'  => false,
				'wrapper_property' => null,
			);
		}

		// If no type is specified, add 'object' type since MCP requires it.
		if ( ! isset( $schema['type'] ) ) {
			$schema['type'] = 'object';

			return array(
				'schema'           => $schema,
				'was_transformed'  => false,
				'wrapper_property' => null,
			);
		}

		// If already an object type, return as-is
		if ( 'object' === $schema['type'] ) {
			return array(
				'schema'           => $schema,
				'was_transformed'  => false,
				'wrapper_property' => null,
			);
		}

		// Transform flattened schema to object format
		return array(
			'schema'           => self::wrap_in_object( $schema, $wrapper_key ),
			'was_transformed'  => true,
			'wrapper_property' => $wrapper_key,
		);
	}

	/**
	 * Wrap a flattened schema in an object structure.
	 *
	 * Creates an object schema with a single property (named by $wrapper_key) that
	 * contains the original flattened schema.
	 *
	 * @param array<string,mixed> $schema The flattened schema to wrap.
	 * @param string $wrapper_key Property name to wrap the value under.
	 *
	 * @return array<string,mixed> The wrapped object schema.
	 */
	private static function wrap_in_object( array $schema, string $wrapper_key ): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				$wrapper_key => $schema,
			),
			'required'   => array( $wrapper_key ),
		);
	}

	/**
	 * Convert objects to arrays and strip empty properties.
	 *
	 * @param array<string,mixed>|null $schema The schema to normalize.
	 *
	 * @return array<string,mixed>|null The normalized schema.
	 */
	private static function normalize( ?array $schema ): ?array {
		if ( null === $schema ) {
			return null;
		}

		$schema = self::convert_objects_to_arrays( $schema );

		if ( array_key_exists( 'properties', $schema ) && is_array( $schema['properties'] ) && empty( $schema['properties'] ) ) {
			unset( $schema['properties'] );
		}

		return $schema;
	}

	/**
	 * Recursively convert objects to arrays.
	 *
	 * Casts objects to arrays as before, but guards against runaway recursion:
	 * cyclic object graphs (e.g. an object-valued sanitize_callback whose object
	 * references itself) are short-circuited via spl_object_id() tracking, and a
	 * MAX_DEPTH ceiling stops any other pathological nesting. While walking, the
	 * WordPress-only keys (sanitize_callback, validate_callback, arg_options) are
	 * stripped because they are not valid JSON Schema and may carry such cyclic
	 * callables. They are stripped wherever encountered: that is the behaviour the
	 * issue asks for and the simplest correct approach. The risk of clobbering a
	 * legitimately named data property is accepted as negligible for schema input.
	 *
	 * @param mixed              $value   The value to convert.
	 * @param int                $depth   Current recursion depth.
	 * @param array<int,bool>    $visited Object ids already seen on this branch.
	 *
	 * @return mixed The converted value.
	 */
	private static function convert_objects_to_arrays( $value, int $depth = 0, array $visited = array() ) {
		if ( $depth >= self::MAX_DEPTH ) {
			return null;
		}

		if ( is_object( $value ) ) {
			// Track identity BEFORE the cast, since (array) loses object identity.
			$object_id = spl_object_id( $value );
			if ( isset( $visited[ $object_id ] ) ) {
				return null;
			}
			$visited[ $object_id ] = true;
			$value                 = (array) $value;
		}

		if ( is_array( $value ) ) {
			$result = array();
			foreach ( $value as $key => $item ) {
				if ( in_array( $key, self::WP_ONLY_KEYS, true ) ) {
					continue;
				}
				$result[ $key ] = self::convert_objects_to_arrays( $item, $depth + 1, $visited );
			}

			return $result;
		}

		return $value;
	}
}
