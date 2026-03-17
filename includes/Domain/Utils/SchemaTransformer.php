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
	 * @param mixed $value The value to convert.
	 *
	 * @return mixed The converted value.
	 */
	private static function convert_objects_to_arrays( $value ) {
		if ( is_object( $value ) ) {
			$value = (array) $value;
		}

		if ( is_array( $value ) ) {
			return array_map( array( self::class, 'convert_objects_to_arrays' ), $value );
		}

		return $value;
	}
}
