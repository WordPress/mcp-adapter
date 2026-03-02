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
	 * Unknown annotation keys (not part of the MCP specification) are passed through
	 * as-is for tool and resource feature types. MCP clients ignore unrecognized fields,
	 * so this is protocol-safe and enables domain-specific metadata on annotations.
	 *
	 * @since n.e.x.t
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

		// Pass through unknown annotation keys as-is (no normalization).
		// Prompts are excluded: MCP clients may not expect custom fields on prompts.
		if ( 'prompt' !== $feature_type ) {
			$known_keys = self::get_known_annotation_keys();
			foreach ( $ability_annotations as $key => $value ) {
				if ( isset( $known_keys[ $key ] ) || null === $value ) {
					continue;
				}
				$result[ $key ] = $value;
			}
		}

		return $result;
	}

	/**
	 * Get all known annotation keys (MCP fields and WordPress ability property aliases).
	 *
	 * Used to identify which keys in the source annotations are "unknown" and should
	 * be passed through without normalization.
	 *
	 * @since n.e.x.t
	 *
	 * @return array<string, true> Map of known keys for fast lookup.
	 */
	private static function get_known_annotation_keys(): array {
		static $known_keys = null;

		if ( null !== $known_keys ) {
			return $known_keys;
		}

		$known_keys = array();
		foreach ( self::$mcp_annotations as $mcp_field => $config ) {
			$known_keys[ $mcp_field ] = true;
			if ( null === $config['ability_property'] ) {
				continue;
			}
			$known_keys[ $config['ability_property'] ] = true;
		}

		return $known_keys;
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
