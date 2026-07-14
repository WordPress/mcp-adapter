<?php
/**
 * Helper trait for WordPress abilities providing MCP-related utilities.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities;

use WP\MCP\Core\McpAdapter;
use WP_Error;

/**
 * Trait McpAbilityHelperTrait
 *
 * Provides helper methods for MCP abilities including MCP exposure checking and metadata handling.
 */
trait McpAbilityHelperTrait {

	/**
	 * Checks if ability is publicly exposed via MCP.
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
			return new WP_Error( 'ability_not_found', "Ability '{$ability_name}' not found" );
		}

		if ( ! self::is_ability_mcp_public( $ability ) ) {
			return new WP_Error(
				'ability_not_public_mcp',
				sprintf( 'Ability "%s" is not exposed via MCP (mcp.public!=true)', $ability_name )
			);
		}

		return true;
	}

	/**
	 * Checks if ability is publicly exposed via MCP (simple boolean version).
	 *
	 * This is a simplified version that returns only boolean values,
	 * useful for filtering operations where WP_Error handling isn't needed.
	 *
	 * @param \WP_Ability $ability The ability object to check.
	 *
	 * @return bool True if publicly exposed, false otherwise.
	 */
	protected static function is_ability_mcp_public( \WP_Ability $ability ): bool {
		$meta      = $ability->get_meta();
		$is_public = (bool) ( $meta['mcp']['public'] ?? false );

		$server = class_exists( McpAdapter::class )
			? McpAdapter::instance()->get_current_server()
			: null;

		/**
		 * Filters whether an ability is considered publicly exposed via MCP
		 * for the purposes of the built-in discover/get-info/execute tools.
		 *
		 * The default value reflects the ability's `meta.mcp.public` flag.
		 * Return true to expose an ability that is not statically marked
		 * public, or false to hide one that is.
		 *
		 * The current MCP server (if any) is passed so integrators can make
		 * per-server exposure decisions — e.g. reveal an ability on an
		 * internal server but hide it on a public one. `$server` is null
		 * when the ability is invoked outside of an MCP request (e.g.
		 * WP-CLI or a direct `wp_get_ability( ... )->execute()` call).
		 *
		 * @since 0.6.0
		 *
		 * @param bool                        $is_public Whether the ability is publicly exposed via MCP by default.
		 * @param \WP_Ability                 $ability   The ability being checked.
		 * @param \WP\MCP\Core\McpServer|null $server    The MCP server handling the current request, or null.
		 */
		return (bool) apply_filters( 'mcp_adapter_is_ability_public', $is_public, $ability, $server );
	}

	/**
	 * Gets the MCP type of an ability.
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
