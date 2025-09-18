<?php
/**
 * Ability for executing WordPress abilities.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities;

/**
 * Execute Ability - Executes a WordPress ability with provided parameters.
 *
 * This ability provides the primary execution layer for running any registered
 * WordPress ability through the MCP protocol.
 */
final class ExecuteAbilityAbility {

	/**
	 * Register the ability.
	 */
	public static function register(): void {
		wp_register_ability(
			'mcp-adapter/execute-ability',
			array(
				'label'               => 'Execute Ability',
				'description'         => 'Execute a WordPress ability with the provided parameters. This is the primary execution layer that can run any registered ability.',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'ability_name' => array(
							'type'        => 'string',
							'description' => 'The full name of the ability to execute',
						),
						'parameters'   => array(
							'type'        => 'object',
							'description' => 'Parameters to pass to the ability',
						),
					),
					'required'             => array( 'ability_name', 'parameters' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array( 'type' => 'boolean' ),
						'data'    => array(
							'description' => 'The result data from the ability execution',
						),
						'error'   => array(
							'type'        => 'string',
							'description' => 'Error message if execution failed',
						),
					),
					'required'   => array( 'success' ),
				),
				'permission_callback' => array( self::class, 'check_permission' ),
				'execute_callback'    => array( self::class, 'execute' ),
				'meta'                => array(
					'annotations' => array(
						'priority'        => 1.0,
						'readOnlyHint'    => false,
						'destructiveHint' => false, // Depends on the executed ability
						'idempotentHint'  => false, // Depends on the executed ability
						'openWorldHint'   => true,  // Can execute any registered ability
					),
				),
			)
		);
	}

	/**
	 * Check permissions for executing abilities.
	 *
	 * @param array $input Input parameters containing ability_name and parameters.
	 *
	 * @return bool|\WP_Error True if the user has permission to execute the specified ability.
	 * @phpstan-return bool|\WP_Error
	 */
	public static function check_permission( $input = array() ) {
		$ability_name = $input['ability_name'] ?? '';

		if ( empty( $ability_name ) ) {
			return false;
		}

		// Get the target ability
		$ability = wp_get_ability( $ability_name );
		if ( ! $ability ) {
			return false;
		}

		// Check if the user has permission to execute the target ability
		$parameters        = $input['parameters'] ?? array();
		$permission_result = $ability->has_permission( $parameters );

		// Return WP_Error as-is, or convert other values to boolean
		if ( is_wp_error( $permission_result ) ) {
			return $permission_result;
		}

		return (bool) $permission_result;
	}

	/**
	 * Execute the ability execution functionality.
	 *
	 * @param array $input Input parameters containing ability_name and parameters.
	 *
	 * @return array Array containing execution results.
	 */
	public static function execute( $input = array() ): array {
		$ability_name = $input['ability_name'] ?? '';
		$parameters   = $input['parameters'] ?? array();

		if ( empty( $ability_name ) ) {
			return array(
				'success' => false,
				'error'   => 'Ability name is required',
			);
		}

		$ability = wp_get_ability( $ability_name );

		if ( ! $ability ) {
			return array(
				'success' => false,
				'error'   => "Ability '{$ability_name}' not found",
			);
		}

		try {
			// Execute the ability
			$result = $ability->execute( $parameters );

			// Check if the result is a WP_Error
			if ( is_wp_error( $result ) ) {
				return array(
					'success' => false,
					'error'   => $result->get_error_message(),
				);
			}

			return array(
				'success' => true,
				'data'    => $result,
			);
		} catch ( \Throwable $e ) {
			return array(
				'success' => false,
				'error'   => $e->getMessage(),
			);
		}
	}
}
