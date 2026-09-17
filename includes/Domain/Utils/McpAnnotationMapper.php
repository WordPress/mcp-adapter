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
 * Renames WordPress-format annotation keys to their MCP field names and keeps only
 * the fields that apply to the requested feature type. Values are passed through
 * unchanged; the schema package decides whether they fit the protocol.
 */
class McpAnnotationMapper {

	/**
	 * Comprehensive mapping of MCP annotations.
	 *
	 * Maps MCP annotation fields to the features they apply to and their
	 * WordPress Ability API equivalent property names.
	 *
	 * Structure:
	 * - features: Array of MCP features where this annotation is used (tool, resource)
	 * - ability_property: The WordPress Ability API property name (may differ from MCP field name), or null if mapping 1:1
	 *
	 * Note: Per MCP 2025-11-25 spec:
	 * - Tools use ToolAnnotations (title, *Hint fields only)
	 * - Resources use shared Annotations (audience, priority, lastModified)
	 * - Prompts do NOT support annotations at template level (only on message content blocks)
	 *
	 * @var array<string, array{features: array<string>, ability_property: string|null}>
	 */
	private static array $mcp_annotations = array(
		// Shared annotations - Resources only (NOT Tools or Prompt templates per MCP spec).
		// ToolAnnotations is a separate type that does not include these fields.
		// Prompt templates do not support annotations; only content blocks inside messages do.
		'audience'        => array(
			'features'         => array( 'resource' ),
			'ability_property' => null,
		),
		'lastModified'    => array(
			'features'         => array( 'resource' ),
			'ability_property' => null,
		),
		'priority'        => array(
			'features'         => array( 'resource' ),
			'ability_property' => null,
		),
		// Tool-specific annotations (ToolAnnotations type per MCP 2025-11-25 spec).
		'readOnlyHint'    => array(
			'features'         => array( 'tool' ),
			'ability_property' => 'readonly',
		),
		'destructiveHint' => array(
			'features'         => array( 'tool' ),
			'ability_property' => 'destructive',
		),
		'idempotentHint'  => array(
			'features'         => array( 'tool' ),
			'ability_property' => 'idempotent',
		),
		'openWorldHint'   => array(
			'features'         => array( 'tool' ),
			'ability_property' => null,
		),
		'title'           => array(
			'features'         => array( 'tool' ),
			'ability_property' => null,
		),
	);

	/**
	 * Map WordPress ability annotation property names to MCP field names.
	 *
	 * Maps WordPress-format field names to MCP equivalents (e.g., readonly → readOnlyHint).
	 * Only includes annotations applicable to the specified feature type.
	 * Null values are excluded because WordPress core defaults every annotation to null.
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

			$result[ $mcp_field ] = $value;
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
}
