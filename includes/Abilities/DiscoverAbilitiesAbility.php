<?php
/**
 * Ability for discovering available WordPress abilities.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities;

use WP_Error;

/**
 * Discover Abilities - Lists all available WordPress abilities in the system.
 *
 * This ability provides discovery functionality for the MCP protocol.
 * It discovers all registered WordPress abilities in the system.
 *
 * SECURITY CONSIDERATIONS:
 * - This ability exposes information about all registered abilities in the system
 * - Only abilities with mcp.public=true metadata will be returned
 * - Requires proper WordPress capability checks for secure operation
 *
 * @see https://developer.wordpress.org/apis/security/ for detailed security guidance
 */
final class DiscoverAbilitiesAbility {
	use McpAbilityHelperTrait;

	/**
	 * Register the ability.
	 */
	public static function register(): void {
		wp_register_ability(
			'mcp-adapter/discover-abilities',
			array(
				'label'               => 'Discover Abilities',
				'description'         => 'Discover all available WordPress abilities in the system. Returns a list of all registered abilities with their basic information.',
				'category'            => 'mcp-adapter',
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'abilities' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'name'        => array( 'type' => 'string' ),
									'label'       => array( 'type' => 'string' ),
									'description' => array( 'type' => 'string' ),
								),
								'required'   => array( 'name', 'label', 'description' ),
							),
						),
					),
					'required'   => array( 'abilities' ),
				),
				'permission_callback' => array( self::class, 'check_permission' ),
				'execute_callback'    => array( self::class, 'execute' ),
				'meta'                => array(
					'annotations' => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			)
		);
	}

	/**
	 * Execute the discover abilities functionality.
	 *
	 * Note: Permission checks are handled by the WP_Ability::execute() framework method
	 * before this callback is invoked.
	 *
	 * @see \WP_Ability::execute()
	 *
	 * @param array $input Input parameters (unused for this ability).
	 *
	 * @return array Array containing public MCP abilities.
	 */
	public static function execute( $input = array() ): array {
		// Get all abilities and filter for publicly exposed ones
		$abilities = wp_get_abilities();

		$ability_list = array();
		foreach ( $abilities as $ability ) {
			$ability_name = $ability->get_name();

			// Check if ability is publicly exposed via MCP
			if ( ! self::is_ability_mcp_public( $ability ) ) {
				continue;
			}

			// Only discover abilities with type='tool' (default type)
			if ( self::get_ability_mcp_type( $ability ) !== 'tool' ) {
				continue;
			}

			$ability_list[] = array(
				'name'        => $ability_name,
				'label'       => $ability->get_label(),
				'description' => $ability->get_description(),
			);
		}

		return array(
			'abilities' => $ability_list,
		);
	}

	/**
	 * Check permissions for discovering abilities.
	 *
	 * Validates user capabilities and caller identity.
	 *
	 * @param array $input Input parameters (unused for this ability).
	 *
	 * @return bool|\WP_Error True if the user has permission to discover abilities.
	 */
	public static function check_permission( $input = array() ) {
		// Verify caller identity - ensure user is authenticated
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'authentication_required', 'User must be authenticated to access this ability' );
		}

		/**
		 * Filters the capability required to discover available abilities.
		 *
		 * This capability is checked before listing all registered WordPress abilities
		 * through the mcp-adapter-discover-abilities tool.
		 *
		 * @since 0.3.0
		 *
		 * @param string $capability The required capability. Default 'read'.
		 */
		$required_capability = apply_filters( 'mcp_adapter_discover_abilities_capability', 'read' );
		// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Capability is determined dynamically via filter
		if ( ! current_user_can( $required_capability ) ) {
			return new WP_Error(
				'insufficient_capability',
				sprintf( 'User lacks required capability: %s', $required_capability )
			);
		}

		return true;
	}
}
