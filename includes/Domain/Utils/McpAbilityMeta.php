<?php
/**
 * Reader for the adapter-owned `mcp` namespace in ability meta.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Domain\Utils;

use WP_Error;

/**
 * Reads `meta.mcp` from an ability.
 *
 * `meta.mcp` is the block ability authors write to shape MCP exposure and
 * component data; the adapter reads it and never emits it. Core validates only
 * `meta` itself as an array, so the converters check the block here before they
 * index it. A block that is set and not an array is rejected, matching the
 * fail-closed rule in McpAbilityExposure.
 *
 * @internal
 *
 * @since n.e.x.t
 */
final class McpAbilityMeta {

	/**
	 * Get the `meta.mcp` block of an ability.
	 *
	 * @param \WP_Ability $ability The ability to read.
	 * @return array<string, mixed>|\WP_Error The block, an empty array when it is not set, or a WP_Error when it is set and not an array.
	 */
	public static function mcp( \WP_Ability $ability ) {
		$meta = $ability->get_meta();
		if ( ! isset( $meta['mcp'] ) ) {
			return array();
		}

		if ( ! is_array( $meta['mcp'] ) ) {
			return new WP_Error(
				'mcp_ability_invalid_meta',
				sprintf(
				/* translators: 1: ability name, 2: PHP type of the meta.mcp value */
					__( 'Ability meta "mcp" must be an array for ability "%1$s", %2$s given.', 'mcp-adapter' ),
					$ability->get_name(),
					gettype( $meta['mcp'] )
				)
			);
		}

		return $meta['mcp'];
	}
}
