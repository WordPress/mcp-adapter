<?php
/**
 * Resolves whether an ability is exposed through the default MCP server.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities;

use WP_Ability;

/**
 * Class McpAbilityExposure
 *
 * Single source of truth for MCP exposure. Every read of the exposure flag must
 * go through this class. Reading `meta.mcp.public` directly reintroduces the bug
 * this class exists to prevent: exposure cannot be resolved while an ability is
 * being registered, because `wp_register_ability_args` callbacks that run later
 * can still change `meta.public`, and no filter priority is guaranteed to run
 * last. Resolving from the stored ability instead happens after registration is
 * complete, so the metadata is final.
 *
 * @since 0.6.0
 */
final class McpAbilityExposure {

	/**
	 * Determines whether an ability is exposed through the default MCP server.
	 *
	 * An explicit `meta.mcp.public` wins. When it is absent or null, exposure is
	 * inherited from the high-level `meta.public` flag. Malformed `meta.mcp`
	 * fails closed.
	 *
	 * @since 0.6.0
	 *
	 * @param \WP_Ability $ability The ability to check.
	 *
	 * @return bool True when the ability is exposed through MCP, false otherwise.
	 */
	public static function is_public( WP_Ability $ability ): bool {
		return self::is_meta_public( $ability->get_meta() );
	}

	/**
	 * Determines whether ability metadata resolves to MCP exposure.
	 *
	 * @since 0.6.0
	 *
	 * @param array<string, mixed> $meta Ability metadata.
	 *
	 * @return bool True when the metadata resolves to MCP exposure, false otherwise.
	 */
	public static function is_meta_public( array $meta ): bool {
		$mcp_meta = $meta['mcp'] ?? array();

		// Fail closed when `meta.mcp` is malformed.
		if ( ! is_array( $mcp_meta ) ) {
			return false;
		}

		if ( isset( $mcp_meta['public'] ) ) {
			return (bool) $mcp_meta['public'];
		}

		return true === ( $meta['public'] ?? false );
	}
}
