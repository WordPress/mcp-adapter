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
 *
 * SECURITY CONSIDERATIONS:
 * - This ability exposes detailed schemas and metadata about abilities
 * - Only abilities with mcp.public=true metadata can be queried via default MCP server.
 * - Requires proper WordPress capability checks for secure operation
 *
 * @see https://github.com/your-repo/mcp-adapter/docs/security.md for detailed security configuration
 */
final class GetAbilityInfoAbility {
	use McpAbilityHelperTrait;

	/**
	 * Register the ability.
	 */
	public static function register(): void {
		wp_register_ability(
			'mcp-adapter/get-ability-info',
			array(
				'label'               => 'Get Ability Info',
				'description'         => 'Get detailed information about a specific WordPress ability including its input/output schema, description, and usage examples.',
				'category'            => 'mcp-adapter',
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
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			)
		);
	}

	/**
	 * Check permissions for getting ability info.
	 *
	 * Validates user capabilities, caller identity, and MCP exposure restrictions.
	 *
	 * @param array $input Input parameters containing ability_name.
	 *
	 * @return bool|\WP_Error True if the user has permission to get ability info.
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
		return self::check_ability_mcp_exposure( $ability_name );
	}

	/**
	 * Validate user authentication and basic capabilities for get ability info.
	 *
	 * @return bool|\WP_Error True if valid, WP_Error if validation fails.
	 */
	private static function validate_user_access() {
		// Verify caller identity - ensure user is authenticated
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'authentication_required', 'User must be authenticated to access this ability' );
		}

		// Check basic capability requirement - allow customization via filter
		$required_capability = apply_filters( 'mcp_adapter_get_ability_info_capability', 'read' );
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
	 * Execute the get ability info functionality.
	 *
	 * Enforces security checks before returning ability information.
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

		// Enforce security checks before execution
		$permission_check = self::check_permission( $input );
		if ( is_wp_error( $permission_check ) ) {
			return array(
				'error' => $permission_check->get_error_message(),
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
