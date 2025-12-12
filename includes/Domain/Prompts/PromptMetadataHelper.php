<?php
/**
 * Helper for accessing MCP Adapter metadata stored in Prompt DTOs.
 *
 * @package McpAdapter
 */
declare( strict_types=1 );

namespace WP\MCP\Domain\Prompts;

use WP\McpSchema\Server\Prompts\Prompt;

/**
 * PromptMetadataHelper.
 *
 * Internal adapter metadata is stored in Prompt `_meta['mcp_adapter']`.
 *
 * @internal
 */
final class PromptMetadataHelper {
	/**
	 * Get the ability name associated with a prompt DTO.
	 *
	 * For builder-based prompts this may be null; those prompts are executed via the
	 * component registry builder mapping.
	 *
	 * @param \WP\McpSchema\Server\Prompts\Prompt $prompt Prompt DTO.
	 *
	 * @return string|null Ability name or null when missing.
	 */
	public static function get_ability_name( Prompt $prompt ): ?string {
		$meta = $prompt->get_meta();
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
