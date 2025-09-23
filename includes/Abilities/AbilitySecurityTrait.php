<?php
/**
 * Security trait for WordPress abilities providing shared security utilities.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities;

/**
 * Trait AbilitySecurityTrait
 *
 * Provides shared security methods for MCP abilities including MCP exposure checking functionality.
 */
trait AbilitySecurityTrait {

	/**
	 * Check if ability is publicly exposed via MCP.
	 *
	 * Validates against the ability's public_mcp metadata flag.
	 * Only abilities with public_mcp=true are accessible via MCP.
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
		$is_public_mcp = $meta['public_mcp'] ?? false;

		if ( ! $is_public_mcp ) {
			return new \WP_Error(
				'ability_not_public_mcp',
				sprintf( 'Ability "%s" is not exposed via MCP (public_mcp!=true)', $ability_name )
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
	 * @param string $ability_name The ability name to check.
	 *
	 * @return bool True if publicly exposed, false otherwise.
	 */
	protected static function is_ability_mcp_public( string $ability_name ): bool {
		$ability = wp_get_ability( $ability_name );

		if ( ! $ability ) {
			return false;
		}

		$meta = $ability->get_meta();
		return (bool) ( $meta['public_mcp'] ?? false );
	}
}
