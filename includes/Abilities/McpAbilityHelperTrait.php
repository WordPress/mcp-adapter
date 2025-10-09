<?php
/**
 * Helper trait for WordPress abilities providing MCP-related utilities.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities;

/**
 * Trait McpAbilityHelperTrait
 *
 * Provides helper methods for MCP abilities including MCP exposure checking and metadata handling.
 */
trait McpAbilityHelperTrait {

	/**
	 * Check if ability is publicly exposed via MCP.
	 *
	 * Validates against the ability's mcp.public metadata flag.
	 * Only abilities with mcp.public=true are accessible via default MCP server.
	 *
	 * @param string $ability_name The ability name to check.
	 *
	 * @return bool|\WP_Error True if publicly exposed, WP_Error if not.
	 */
	protected static function check_ability_mcp_exposure( string $ability_name ) {
		$ability = wp_get_ability( $ability_name );

		if ( ! $ability ) {
			return new \WP_Error( 'ability_not_found', "Ability '{$ability_name}' not found" );
		}

		$meta          = $ability->get_meta();
		$is_public_mcp = $meta['mcp']['public'] ?? false;

		if ( ! $is_public_mcp ) {
			return new \WP_Error(
				'ability_not_public_mcp',
				sprintf( 'Ability "%s" is not exposed via MCP (mcp.public!=true)', $ability_name )
			);
		}

		return true;
	}

	/**
	 * Check if ability is publicly exposed via MCP (simple boolean version).
	 *
	 * This is a simplified version that returns only boolean values,
	 * useful for filtering operations where WP_Error handling isn't needed.
	 *
	 * @param \WP_Ability $ability The ability object to check.
	 *
	 * @return bool True if publicly exposed, false otherwise.
	 */
	protected static function is_ability_mcp_public( \WP_Ability $ability ): bool {
		$meta = $ability->get_meta();
		return (bool) ( $meta['mcp']['public'] ?? false );
	}

	/**
	 * Get the MCP type of an ability.
	 *
	 * Returns the type specified in meta.mcp.type, defaulting to 'tool' if not specified.
	 *
	 * @param \WP_Ability $ability The ability object to check.
	 *
	 * @return string The MCP type ('tool', 'resource', or 'prompt'). Defaults to 'tool'.
	 */
	protected static function get_ability_mcp_type( \WP_Ability $ability ): string {
		$meta = $ability->get_meta();
		$type = $meta['mcp']['type'] ?? 'tool';

		// Validate type is one of the allowed values
		if ( ! in_array( $type, array( 'tool', 'resource', 'prompt' ), true ) ) {
			return 'tool';
		}

		return $type;
	}
}
