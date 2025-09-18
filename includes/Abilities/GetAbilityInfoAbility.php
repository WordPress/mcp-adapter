<?php
/**
 * Ability for getting detailed information about WordPress abilities.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities;

/**
 * Get Ability Info - Get detailed information about a specific WordPress ability.
 *
 * This ability provides detailed information about any registered WordPress ability,
 * including its input/output schemas, description, and usage examples.
 */
final class GetAbilityInfoAbility {

	/**
	 * Register the ability.
	 */
	public static function register(): void {
		wp_register_ability(
			'mcp-adapter/get-ability-info',
			array(
				'label'               => 'Get Ability Info',
				'description'         => 'Get detailed information about a specific WordPress ability including its input/output schema, description, and usage examples.',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'ability_name' => array(
							'type'        => 'string',
							'description' => 'The full name of the ability to get information about',
						),
					),
					'required'             => array( 'ability_name' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'name'          => array( 'type' => 'string' ),
						'label'         => array( 'type' => 'string' ),
						'description'   => array( 'type' => 'string' ),
						'input_schema'  => array(
							'type'        => 'object',
							'description' => 'JSON Schema for the ability input parameters',
						),
						'output_schema' => array(
							'type'        => 'object',
							'description' => 'JSON Schema for the ability output structure',
						),
						'meta'          => array(
							'type'        => 'object',
							'description' => 'Additional metadata about the ability',
						),
					),
					'required'   => array( 'name', 'label', 'description', 'input_schema' ),
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
	 * Check permissions for getting ability info.
	 *
	 * @param array $input Input parameters.
	 *
	 * @return bool True if the user has permission to get ability info.
	 */
	public static function check_permission( $input = array() ): bool {
		// Allow any authenticated user to get ability information
		return is_user_logged_in();
	}

	/**
	 * Execute the get ability info functionality.
	 *
	 * @param array $input Input parameters containing ability_name.
	 *
	 * @return array Array containing detailed ability information.
	 */
	public static function execute( $input = array() ): array {
		$ability_name = $input['ability_name'] ?? '';

		if ( empty( $ability_name ) ) {
			return array(
				'error' => 'Ability name is required',
			);
		}

		$ability = wp_get_ability( $ability_name );

		if ( ! $ability ) {
			return array(
				'error' => "Ability '{$ability_name}' not found",
			);
		}

		$ability_info = array(
			'name'         => $ability->get_name(),
			'label'        => $ability->get_label(),
			'description'  => $ability->get_description(),
			'input_schema' => $ability->get_input_schema(),
		);

		// Add output schema if available
		$output_schema = $ability->get_output_schema();
		if ( ! empty( $output_schema ) ) {
			$ability_info['output_schema'] = $output_schema;
		}

		// Add meta information if available
		$meta = $ability->get_meta();
		if ( ! empty( $meta ) ) {
			$ability_info['meta'] = $meta;
		}

		return $ability_info;
	}
}
