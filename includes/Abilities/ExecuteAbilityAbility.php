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
 *
 * SECURITY CONSIDERATIONS:
 * - This ability has openWorldHint=true, allowing execution of any registered ability
 * - Only abilities with mcp.public=true metadata can be executed via default MCP server.
 * - Requires proper WordPress capability checks for secure operation
 * - Caller identity verification is enforced through WordPress authentication
 *
 * @see https://github.com/your-repo/mcp-adapter/docs/security.md for detailed security configuration
 */
final class ExecuteAbilityAbility {
	use McpAbilityHelperTrait;

	/**
	 * Register the ability.
	 */
	public static function register(): void {
		wp_register_ability(
			'mcp-adapter/execute-ability',
			array(
				'label'               => 'Execute Ability',
				'description'         => 'Execute a WordPress ability with the provided parameters. This is the primary execution layer that can run any registered ability.',
				'category'            => 'mcp-adapter',
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
						'priority'      => '1.0',
						'readOnlyHint'  => false,
						'openWorldHint' => true,
					),
				),
			)
		);
	}

	/**
	 * Check permissions for executing abilities.
	 *
	 * Validates user capabilities, caller identity, and MCP exposure restrictions.
	 *
	 * @param array $input Input parameters containing ability_name and parameters.
	 *
	 * @return bool|\WP_Error True if the user has permission to execute the specified ability.
	 * @phpstan-return bool|\WP_Error
	 */
	public static function check_permission( $input = array() ) {
		$ability_name = $input['ability_name'] ?? '';

		if ( empty( $ability_name ) ) {
			return new \WP_Error( 'missing_ability_name', 'Ability name is required' );
		}

		// Validate user authentication and capabilities
		$user_check = self::validate_user_access();
		if ( is_wp_error( $user_check ) ) {
			return $user_check;
		}

		// Check MCP exposure restrictions
		$exposure_check = self::check_ability_mcp_exposure( $ability_name );
		if ( is_wp_error( $exposure_check ) ) {
			return $exposure_check;
		}

		// Get the target ability
		$ability = wp_get_ability( $ability_name );
		if ( ! $ability ) {
			return new \WP_Error( 'ability_not_found', "Ability '{$ability_name}' not found" );
		}

		// Check if the user has permission to execute the target ability
		$parameters        = empty( $input['parameters'] ) ? null : $input['parameters'];
		$permission_result = $ability->check_permissions( $parameters );

		// Return WP_Error as-is, or convert other values to boolean
		if ( is_wp_error( $permission_result ) ) {
			return $permission_result;
		}

		return (bool) $permission_result;
	}

	/**
	 * Validate user authentication and basic capabilities for execute ability.
	 *
	 * @return bool|\WP_Error True if valid, WP_Error if validation fails.
	 */
	private static function validate_user_access() {
		// Verify caller identity - ensure user is authenticated
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'authentication_required', 'User must be authenticated to access this ability' );
		}

		// Check basic capability requirement - allow customization via filter
		$required_capability = apply_filters( 'mcp_adapter_execute_ability_capability', 'read' );
		// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Capability is determined dynamically via filter
		if ( ! current_user_can( $required_capability ) ) {
			return new \WP_Error(
				'insufficient_capability',
				sprintf( 'User lacks required capability: %s', $required_capability )
			);
		}

		return true;
	}

	/**
	 * Execute the ability execution functionality.
	 *
	 * Enforces security checks before executing any ability.
	 *
	 * @param array $input Input parameters containing ability_name and parameters.
	 *
	 * @return array Array containing execution results.
	 */
	public static function execute( $input = array() ): array {
		$ability_name = $input['ability_name'] ?? '';
		$parameters   = empty( $input['parameters'] ) ? null : $input['parameters'];

		if ( empty( $ability_name ) ) {
			return array(
				'success' => false,
				'error'   => 'Ability name is required',
			);
		}

		// Enforce security checks before execution
		// Note: WordPress will have already called check_permission, but we double-check
		// as an additional security layer for direct method calls
		$permission_check = self::check_permission( $input );
		if ( is_wp_error( $permission_check ) ) {
			return array(
				'success' => false,
				'error'   => $permission_check->get_error_message(),
			);
		}

		if ( ! $permission_check ) {
			return array(
				'success' => false,
				'error'   => 'Permission denied for ability execution',
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
