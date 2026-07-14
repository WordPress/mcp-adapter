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
use WP_User;

/**
 * Trait McpAbilityHelperTrait
 *
 * Provides helper methods for MCP abilities including MCP exposure checking and metadata handling.
 */
trait McpAbilityHelperTrait {

	/**
	 * Checks if ability is exposed via MCP for a specific exposure path.
	 *
	 * Wraps `is_ability_mcp_exposed()` and translates a negative decision
	 * into the `ability_not_public_mcp` WP_Error that callers of this
	 * helper (`GetAbilityInfoAbility`, `ExecuteAbilityAbility`) return
	 * as their permission failure.
	 *
	 * @param string $ability_name  The ability name to check.
	 * @param string $exposure_path One of `McpAbilityExposureContext::PATH_*`.
	 *
	 * @return bool|\WP_Error True if exposed, WP_Error if not.
	 */
	protected static function check_ability_mcp_exposure( string $ability_name, string $exposure_path ) {
		$ability = wp_get_ability( $ability_name );

		if ( ! $ability ) {
			return new WP_Error( 'ability_not_found', "Ability '{$ability_name}' not found" );
		}

		if ( ! self::is_ability_mcp_exposed( $ability, $exposure_path ) ) {
			// Error code preserved for backward compatibility with clients
			// catching the pre-filter behavior; the message is intentionally
			// unchanged for the same reason.
			return new WP_Error(
				'ability_not_public_mcp',
				sprintf( 'Ability "%s" is not exposed via MCP (mcp.public!=true)', $ability_name )
			);
		}

		return true;
	}

	/**
	 * Decides whether an ability is exposed via MCP for the given path.
	 *
	 * The default answer is the ability's static `meta.mcp.public` flag.
	 * The `mcp_adapter_is_ability_exposed` filter allows integrators to
	 * override that answer per ability and per context (server, principal,
	 * site, exposure path).
	 *
	 * All three built-in tools — discover-abilities, get-ability-info,
	 * and execute-ability — route through this method with their own
	 * `PATH_*` value, so the filter is the single point of truth for the
	 * exposure decision. See `check_ability_mcp_exposure()` for the
	 * WP_Error-returning wrapper used by the permission-check paths.
	 *
	 * @param \WP_Ability $ability       The ability object to check.
	 * @param string      $exposure_path One of `McpAbilityExposureContext::PATH_*`.
	 *
	 * @return bool True if exposed, false otherwise.
	 */
	protected static function is_ability_mcp_exposed( \WP_Ability $ability, string $exposure_path ): bool {
		$meta       = $ability->get_meta();
		$is_exposed = (bool) ( $meta['mcp']['public'] ?? false );

		$context = self::build_exposure_context( $exposure_path );

		/**
		 * Filters whether an ability is exposed via MCP for the built-in
		 * discover / get-info / execute tools.
		 *
		 * The default value is the ability's `meta.mcp.public` flag.
		 * Return true to expose an ability that is not statically marked
		 * public, or false to hide one that is. The current request
		 * context is provided so integrators can implement per-server,
		 * per-principal, or per-tenant exposure without touching the
		 * ability's own metadata.
		 *
		 * **Exposure is not authorization.** Returning true here does not
		 * bypass the ability's own `permission_callback`; the execute path
		 * still runs `WP_Ability::check_permissions()` before invoking the
		 * ability. Use exposure to control *visibility* (whether the tool
		 * acknowledges the ability exists / is invocable at all) and use
		 * capability filters (`mcp_adapter_execute_ability_capability`,
		 * etc.) and the ability's own permission callback to control
		 * *authorization*.
		 *
		 * If you cache the result of this decision, the cache key MUST
		 * include every dimension that can influence it: the ability
		 * name, the exposure path, and any context field your callback
		 * reads (server ID, principal ID, roles, site ID, plus any
		 * feature-flag state you consult). Missing dimensions will
		 * cross-contaminate contexts and can silently expose an ability
		 * to the wrong principal or server.
		 *
		 * @since 0.6.0
		 *
		 * @param bool                                       $is_exposed Whether the ability is exposed by default (from `meta.mcp.public`).
		 * @param \WP_Ability                                $ability    The ability being checked.
		 * @param \WP\MCP\Abilities\McpAbilityExposureContext $context   The exposure context (server, principal, site, path).
		 */
		return (bool) apply_filters( 'mcp_adapter_is_ability_exposed', $is_exposed, $ability, $context );
	}

	/**
	 * Builds the exposure context for the current request.
	 *
	 * All context construction lives here so every exposure decision — no
	 * matter which of the three built-in tools issued it — uses the same
	 * definition of "who's asking" and "which server". Downstream callers
	 * should not attempt to build their own contexts by reaching into
	 * request globals; that will silently drift out of sync with this
	 * one.
	 *
	 * @param string $exposure_path One of `McpAbilityExposureContext::PATH_*`.
	 *
	 * @return \WP\MCP\Abilities\McpAbilityExposureContext
	 */
	private static function build_exposure_context( string $exposure_path ): McpAbilityExposureContext {
		$server = class_exists( McpAdapter::class )
			? McpAdapter::instance()->get_current_server()
			: null;

		$principal_id = 0;
		$roles        = array();
		if ( function_exists( 'wp_get_current_user' ) ) {
			$user = wp_get_current_user();
			if ( $user instanceof WP_User && $user->ID > 0 ) {
				$principal_id = (int) $user->ID;
				$roles        = array_values( array_map( 'strval', (array) $user->roles ) );
			}
		}

		$site_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;

		return new McpAbilityExposureContext( $server, $principal_id, $roles, $site_id, $exposure_path );
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
