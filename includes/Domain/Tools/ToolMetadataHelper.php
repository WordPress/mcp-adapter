<?php
/**
 * Helper for accessing MCP Adapter metadata stored in Tool DTOs.
 *
 * @package McpAdapter
 */
declare( strict_types=1 );

namespace WP\MCP\Domain\Tools;

use WP\McpSchema\Server\Tools\Tool;

/**
 * ToolMetadataHelper.
 *
 * Internal adapter metadata is stored in Tool `_meta['mcp_adapter']` while the Tool
 * object itself remains MCP-spec compliant.
 *
 * @internal
 */
final class ToolMetadataHelper {
	/**
	 * Get the ability name associated with a tool DTO.
	 *
	 * @param \WP\McpSchema\Server\Tools\Tool $tool Tool DTO.
	 *
	 * @return string|null Ability name or null when missing.
	 */
	public static function get_ability_name( Tool $tool ): ?string {
		$meta = $tool->get_meta();
		if ( ! is_array( $meta ) ) {
			return null;
		}
		$adapter_meta = $meta['mcp_adapter'] ?? null;
		if ( ! is_array( $adapter_meta ) ) {
			return null;
		}
		$ability = $adapter_meta['ability'] ?? null;
		return is_string( $ability ) && '' !== trim( $ability ) ? $ability : null;
	}

	/**
	 * Whether the tool input schema was transformed (flattened → object wrapper).
	 *
	 * @param \WP\McpSchema\Server\Tools\Tool $tool Tool DTO.
	 *
	 * @return bool
	 */
	public static function is_input_transformed( Tool $tool ): bool {
		$meta = self::get_adapter_meta( $tool );
		return true === ( $meta['input_schema_transformed'] ?? false );
	}

	/**
	 * Get the wrapper property used for transformed input schema.
	 *
	 * @param \WP\McpSchema\Server\Tools\Tool $tool Tool DTO.
	 *
	 * @return string
	 */
	public static function get_input_wrapper( Tool $tool ): string {
		$meta    = self::get_adapter_meta( $tool );
		$wrapper = $meta['input_schema_wrapper'] ?? null;
		return is_string( $wrapper ) && '' !== trim( $wrapper ) ? $wrapper : 'input';
	}

	/**
	 * Whether the tool output schema was transformed (flattened → object wrapper).
	 *
	 * @param \WP\McpSchema\Server\Tools\Tool $tool Tool DTO.
	 *
	 * @return bool
	 */
	public static function is_output_transformed( Tool $tool ): bool {
		$meta = self::get_adapter_meta( $tool );
		return true === ( $meta['output_schema_transformed'] ?? false );
	}

	/**
	 * Get the wrapper property used for transformed output schema.
	 *
	 * @param \WP\McpSchema\Server\Tools\Tool $tool Tool DTO.
	 *
	 * @return string
	 */
	public static function get_output_wrapper( Tool $tool ): string {
		$meta    = self::get_adapter_meta( $tool );
		$wrapper = $meta['output_schema_wrapper'] ?? null;
		return is_string( $wrapper ) && '' !== trim( $wrapper ) ? $wrapper : 'result';
	}

	/**
	 * Get the adapter meta array from a Tool DTO.
	 *
	 * @param \WP\McpSchema\Server\Tools\Tool $tool Tool DTO.
	 *
	 * @return array<string, mixed>
	 */
	private static function get_adapter_meta( Tool $tool ): array {
		$meta = $tool->get_meta();
		if ( ! is_array( $meta ) ) {
			return array();
		}
		$adapter_meta = $meta['mcp_adapter'] ?? null;
		return is_array( $adapter_meta ) ? $adapter_meta : array();
	}
}
