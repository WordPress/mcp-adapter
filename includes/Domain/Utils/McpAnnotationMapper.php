<?php
/**
 * MCP Annotation Mapper utility class for mapping WordPress ability annotations to MCP format.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Domain\Utils;

/**
 * Utility class for mapping WordPress ability annotations to MCP Annotations format.
 *
 * Provides shared annotation mapping and transformation logic used across multiple
 * MCP component registration classes. Handles conversion of WordPress-format annotations
 * to MCP-compliant annotation structures.
 */
class McpAnnotationMapper {

	/**
	 * Comprehensive mapping of MCP annotations.
	 *
	 * Maps MCP annotation fields to their type, which features they apply to,
	 * and their WordPress Ability API equivalent property names.
	 *
	 * Structure:
	 * - type: The data type (boolean, string, array, number)
	 * - features: Array of MCP features where this annotation is used (tool, resource)
	 * - ability_property: The WordPress Ability API property name (may differ from MCP field name), or null if mapping 1:1
	 *
	 * Note: Per MCP 2025-11-25 spec:
	 * - Tools use ToolAnnotations (title, *Hint fields only)
	 * - Resources use shared Annotations (audience, priority, lastModified)
	 * - Prompts do NOT support annotations at template level (only on message content blocks)
	 *
	 * @var array<string, array{type: string, features: array<string>, ability_property: string|null}>
	 */
	private static array $mcp_annotations = array(
		// Shared annotations - Resources only (NOT Tools or Prompt templates per MCP spec).
		// ToolAnnotations is a separate type that does not include these fields.
		// Prompt templates do not support annotations; only content blocks inside messages do.
		'audience'        => array(
			'type'             => 'array',
			'features'         => array( 'resource' ),
			'ability_property' => null,
		),
		'lastModified'    => array(
			'type'             => 'string',
			'features'         => array( 'resource' ),
			'ability_property' => null,
		),
		'priority'        => array(
			'type'             => 'number',
			'features'         => array( 'resource' ),
			'ability_property' => null,
		),
		// Tool-specific annotations (ToolAnnotations type per MCP 2025-11-25 spec).
		'readOnlyHint'    => array(
			'type'             => 'boolean',
			'features'         => array( 'tool' ),
			'ability_property' => 'readonly',
		),
		'destructiveHint' => array(
			'type'             => 'boolean',
			'features'         => array( 'tool' ),
			'ability_property' => 'destructive',
		),
		'idempotentHint'  => array(
			'type'             => 'boolean',
			'features'         => array( 'tool' ),
			'ability_property' => 'idempotent',
		),
		'openWorldHint'   => array(
			'type'             => 'boolean',
			'features'         => array( 'tool' ),
			'ability_property' => null,
		),
		'title'           => array(
			'type'             => 'string',
			'features'         => array( 'tool' ),
			'ability_property' => null,
		),
	);

	/**
	 * Map WordPress ability annotation property names to MCP field names.
	 *
	 * Maps WordPress-format field names to MCP equivalents (e.g., readonly → readOnlyHint).
	 * Only includes annotations applicable to the specified feature type.
	 * Null values are excluded from the result.
	 *
	 * @param array $ability_annotations WordPress ability annotations.
	 * @param string $feature_type The MCP feature type ('tool', 'resource', or 'prompt').
	 *
	 * @return array Mapped annotations for the specified feature type.
	 */
	public static function map( array $ability_annotations, string $feature_type ): array {
		$result = array();

		foreach ( self::$mcp_annotations as $mcp_field => $config ) {
			if ( ! in_array( $feature_type, $config['features'], true ) ) {
				continue;
			}

			$value = self::resolve_annotation_value(
				$ability_annotations,
				$mcp_field,
				$config['ability_property']
			);

			if ( null === $value ) {
				continue;
			}

			$normalized = self::normalize_annotation_value( $config['type'], $value );
			if ( null === $normalized ) {
				continue;
			}

			$result[ $mcp_field ] = $normalized;
		}

		return $result;
	}

	/**
	 * Resolve the annotation value, preferring WordPress-format overrides when available.
	 *
	 * @param array $annotations Raw annotations from the ability.
	 * @param string $mcp_field The MCP field name.
	 * @param string|null $ability_property Optional WordPress-format field name, or null if mapping 1:1.
	 *
	 * @return mixed The annotation value, or null if not found.
	 */
	private static function resolve_annotation_value( array $annotations, string $mcp_field, ?string $ability_property ) {
		// WordPress-format overrides take precedence when present.
		if ( null !== $ability_property && array_key_exists( $ability_property, $annotations ) && ! is_null( $annotations[ $ability_property ] ) ) {
			return $annotations[ $ability_property ];
		}

		if ( array_key_exists( $mcp_field, $annotations ) && ! is_null( $annotations[ $mcp_field ] ) ) {
			return $annotations[ $mcp_field ];
		}

		return null;
	}

	/**
	 * Normalize annotation values to the types expected by MCP.
	 *
	 * @param string $field_type Expected MCP type (boolean, string, array, number).
	 * @param mixed $value Raw annotation value.
	 *
	 * @return mixed|null Normalized value or null if invalid.
	 */
	private static function normalize_annotation_value( string $field_type, $value ) {
		switch ( $field_type ) {
			case 'boolean':
				return self::normalize_boolean( $value );

			case 'string':
				if ( ! is_scalar( $value ) ) {
					return null;
				}
				$trimmed = trim( (string) $value );

				return '' === $trimmed ? null : $trimmed;

			case 'array':
				return is_array( $value ) && ! empty( $value ) ? $value : null;

			case 'number':
				return is_numeric( $value ) ? (float) $value : null;

			default:
				return $value;
		}
	}

	/**
	 * Normalize a value to a strict boolean.
	 *
	 * Accepts only well-defined boolean representations to avoid ambiguous conversions.
	 * PHP's default (bool) cast incorrectly converts 'false' string to true.
	 *
	 * Accepted values:
	 * - true, false (PHP booleans)
	 * - 1, 0 (integers)
	 * - '1', '0', 'true', 'false' (case-insensitive strings)
	 *
	 * @param mixed $value The value to normalize.
	 *
	 * @return bool|null The normalized boolean, or null if value cannot be safely converted.
	 */
	private static function normalize_boolean( $value ): ?bool {
		// Already a boolean - return as-is.
		if ( is_bool( $value ) ) {
			return $value;
		}

		// Integer 1 or 0.
		if ( is_int( $value ) ) {
			if ( 1 === $value ) {
				return true;
			}
			if ( 0 === $value ) {
				return false;
			}

			// Other integers are invalid (e.g., 2, -1).
			return null;
		}

		// String representations (case-insensitive).
		if ( is_string( $value ) ) {
			$lower = strtolower( trim( $value ) );
			if ( 'true' === $lower || '1' === $lower ) {
				return true;
			}
			if ( 'false' === $lower || '0' === $lower ) {
				return false;
			}

			// Other strings are invalid (e.g., 'yes', 'no', empty string).
			return null;
		}

		// All other types (arrays, objects, floats, null) are invalid.
		return null;
	}
}
