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
	 * Map WordPress ability annotations to MCP Annotations format.
	 *
	 * Converts annotation fields according to MCP specification:
	 * - audience: array of Role values (e.g., ["user", "assistant"])
	 * - lastModified: ISO 8601 formatted string
	 * - priority: number (1 = most important, 0 = least important)
	 *
	 * Filters out null values and invalid fields.
	 * Only returns MCP-compliant annotation fields.
	 *
	 * @param array $ability_annotations WordPress ability annotations.
	 *
	 * @return array MCP-compliant Annotations.
	 */
	public static function map( array $ability_annotations ): array {
		$valid_mcp_fields = array(
			'audience'     => 'array',
			'lastModified' => 'string',
			'priority'     => 'number',
		);

		$mcp_annotations = array();

		foreach ( $valid_mcp_fields as $field => $field_type ) {
			if ( ! isset( $ability_annotations[ $field ] ) ) {
				continue;
			}

			$value = $ability_annotations[ $field ];

			// Validate and normalize audience field.
			if ( 'audience' === $field ) {
				if ( ! is_array( $value ) ) {
					continue;
				}
				// Filter valid roles and ensure they're strings.
				$valid_roles       = array( 'user', 'assistant' );
				$filtered_audience = array();
				foreach ( $value as $role ) {
					if ( ! is_string( $role ) || ! in_array( $role, $valid_roles, true ) ) {
						continue;
					}
					$filtered_audience[] = $role;
				}
				if ( ! empty( $filtered_audience ) ) {
					$mcp_annotations[ $field ] = $filtered_audience;
				}
				continue;
			}

			// Validate and normalize lastModified field (ISO 8601 string).
			if ( 'lastModified' === $field ) {
				if ( ! is_string( $value ) || empty( trim( $value ) ) ) {
					continue;
				}
				$trimmed_value = trim( $value );
				// Validate ISO 8601 format - filter out invalid dates.
				if ( ! McpValidator::validate_iso8601_timestamp( $trimmed_value ) ) {
					continue;
				}
				$mcp_annotations[ $field ] = $trimmed_value;
				continue;
			}

			// Validate and normalize priority field (number between 0 and 1).
			// This is the only remaining valid field after audience and lastModified checks.
			if ( ! is_numeric( $value ) ) {
				continue;
			}
			$priority = (float) $value;
			// Clamp priority between 0 and 1 per MCP spec.
			$priority                  = max( 0.0, min( 1.0, $priority ) );
			$mcp_annotations[ $field ] = $priority;
		}

		return $mcp_annotations;
	}
}
