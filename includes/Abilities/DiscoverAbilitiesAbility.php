<?php
/**
 * Ability for discovering available WordPress abilities.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities;

/**
 * Discover Abilities - Lists all available WordPress abilities in the system.
 *
 * This ability provides discovery functionality for the MCP protocol.
 * It discovers all registered WordPress abilities in the system.
 */
class DiscoverAbilitiesAbility {

	/**
	 * Register the ability.
	 */
	public static function register(): void {
		wp_register_ability(
			'mcp-adapter/discover-abilities',
			array(
				'label'               => 'Discover Abilities',
				'description'         => 'Discover all available WordPress abilities in the system. Returns a list of all registered abilities with their basic information.',
				'input_schema'        => array(
					'type'                 => 'object',
					'additionalProperties' => false,
				),
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
						'priority'        => 1.0,
						'readOnlyHint'    => true,
						'destructiveHint' => false,
						'idempotentHint'  => true,
						'openWorldHint'   => false,
					),
				),
			)
		);
	}

	/**
	 * Check permissions for discovering abilities.
	 *
	 * @param array $input Input parameters (unused for this ability).
	 *
	 * @return bool True if the user has permission to discover abilities.
	 */
	public static function check_permission( $input = array() ): bool {
		// Allow any authenticated user to discover abilities
		return is_user_logged_in();
	}

	/**
	 * Execute the discover abilities functionality.
	 *
	 * @param array $input Input parameters (unused for this ability).
	 *
	 * @return array Array containing all available abilities.
	 */
	public static function execute( $input = array() ): array {
		$abilities = wp_get_abilities();

		$ability_list = array();
		foreach ( $abilities as $ability ) {
			$ability_name = $ability->get_name();

			// Exclude abilities that start with 'mcp-adapter/' to prevent self-referencing
			if ( str_starts_with( $ability_name, 'mcp-adapter/' ) ) {
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
}
