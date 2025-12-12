<?php
/**
 * Helper for accessing MCP Adapter metadata stored in Resource DTOs.
 *
 * @package McpAdapter
 */
declare( strict_types=1 );

namespace WP\MCP\Domain\Resources;

use WP\McpSchema\Server\Resources\Resource;

/**
 * ResourceMetadataHelper.
 *
 * Internal adapter metadata is stored in Resource `_meta['mcp_adapter']`.
 *
 * @internal
 */
final class ResourceMetadataHelper {
	/**
	 * Get the ability name associated with a resource DTO.
	 *
	 * @param \WP\McpSchema\Server\Resources\Resource $resource_dto Resource DTO.
	 *
	 * @return string|null Ability name or null when missing.
	 */
	public static function get_ability_name( Resource $resource_dto ): ?string {
		$meta = $resource_dto->get_meta();
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
}
