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
	 * - features: Array of MCP features where this annotation is used (tool, resource, prompt)
	 * - ability_property: The WordPress Ability API property name (may differ from MCP field name), or null if mapping 1:1
	 *
	 * @var array<string, array{type: string, features: array<string>, ability_property: string|null}>
	 */
	private static array $mcp_annotations = array(
		// Shared annotations (all features) - in annotations object.
		'audience'        => array(
			'type'             => 'array',
			'features'         => array( 'tool', 'resource', 'prompt' ),
			'ability_property' => null,
		),
		'lastModified'    => array(
			'type'             => 'string',
			'features'         => array( 'tool', 'resource', 'prompt' ),
			'ability_property' => null,
		),
		'priority'        => array(
			'type'             => 'number',
			'features'         => array( 'tool', 'resource', 'prompt' ),
			'ability_property' => null,
		),
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
	 * @param array  $ability_annotations WordPress ability annotations.
	 * @param string $feature_type        The MCP feature type ('tool', 'resource', or 'prompt').
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
	 * @param array       $annotations     Raw annotations from the ability.
	 * @param string      $mcp_field       The MCP field name.
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
	 * @param mixed  $value      Raw annotation value.
	 *
	 * @return mixed|null Normalized value or null if invalid.
	 */
	private static function normalize_annotation_value( string $field_type, $value ) {
		switch ( $field_type ) {
			case 'boolean':
				return (bool) $value;

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
}
