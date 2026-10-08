<?php
/**
 * Ability for executing WordPress abilities.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities;

use WP\MCP\Domain\Utils\AbilityArgumentNormalizer;
use WP\MCP\Infrastructure\ErrorHandling\ExecutionErrorSanitizer;
use WP_Error;

/**
 * Execute Ability - Executes a WordPress ability with provided parameters.
 *
 * This ability provides the primary execution layer for running any registered
 * WordPress ability through the MCP protocol.
 *
 * SECURITY CONSIDERATIONS:
 * - This ability has openWorldHint=true, allowing execution of any registered ability
 * - Only abilities with effective MCP public exposure can be executed via default MCP server.
 * - Requires proper WordPress capability checks for secure operation
 * - Caller identity verification is enforced through WordPress authentication
 *
 * @see https://developer.wordpress.org/apis/security/ for detailed security guidance
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
					'type'       => 'object',
					'properties' => array(
						'ability_name' => array(
							'type'        => 'string',
							'description' => 'The full name of the ability to execute',
						),
						'parameters'   => array(
							'type'        => 'object',
							'description' => 'Parameters to pass to the ability',
						),
					),
					'required'   => array( 'ability_name', 'parameters' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
						'data'       => array(
							'type'        => array(
								'object',
								'array',
								'string',
								'number',
								'integer',
								'boolean',
								'null',
							),
							'description' => 'The result data from the ability execution',
						),
						'error'      => array(
							'type'        => 'string',
							'description' => 'Error message if execution failed',
						),
						'error_code' => array(
							'type'        => 'string',
							'description' => 'Stable error code if execution failed',
						),
						'error_data' => array(
							'type'        => array(
								'object',
								'array',
								'string',
								'number',
								'integer',
								'boolean',
								'null',
							),
							'description' => 'Additional error context if execution failed',
						),
					),
					'required'   => array( 'success' ),
				),
				'permission_callback' => array( self::class, 'check_permission' ),
				'execute_callback'    => array( self::class, 'execute' ),
				'meta'                => array(
					'annotations' => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					),
				),
			)
		);
	}

	/**
	 * Execute the ability execution functionality.
	 *
	 * Note: Permission checks are handled by the WP_Ability::execute() framework method
	 * before this callback is invoked. This ensures all ability executions are properly
	 * authorized by the framework.
	 *
	 * @see \WP_Ability::execute()
	 *
	 * @param array $input Input parameters containing ability_name and parameters.
	 *
	 * @return array Array containing execution results.
	 */
	public static function execute( $input = array() ): array {
		$ability_name = $input['ability_name'] ?? '';
		// Note: Use null coalescing instead of empty() to preserve empty arrays/objects ({} → [])
		$parameters = $input['parameters'] ?? null;

		if ( empty( $ability_name ) ) {
			return self::error_result(
				new WP_Error( 'missing_ability_name', __( 'Ability name is required', 'mcp-adapter' ) )
			);
		}

		$ability = wp_get_ability( $ability_name );

		if ( ! $ability ) {
			return self::error_result(
				new WP_Error(
					'ability_not_found',
					sprintf(
						/* translators: %s: ability name */
						__( "Ability '%s' not found", 'mcp-adapter' ),
						$ability_name
					)
				)
			);
		}

		// Normalize parameters for ability's schema requirements
		// Empty {} from MCP is treated as null for abilities without input schema
		$parameters = AbilityArgumentNormalizer::normalize( $ability, $parameters );

		try {
			// Execute the ability
			$result = $ability->execute( $parameters );

			// Check if the result is a WP_Error
			if ( is_wp_error( $result ) ) {
				return self::error_result( $result );
			}

			return array(
				'success' => true,
				'data'    => $result,
			);
		} catch ( \Throwable $e ) {
			return self::error_result(
				new WP_Error(
					'mcp_execution_failed',
					$e->getMessage(),
					array( 'error_type' => get_class( $e ) )
				)
			);
		}
	}

	/**
	 * Build a failure envelope preserving the WP_Error code and data.
	 *
	 * Error data is sanitized to JSON-safe values so direct callers of this
	 * ability (outside the MCP tool machinery) never see raw internals.
	 * The `mcp_adapter_tool_error_data` filter is intentionally not applied
	 * here: when this ability is invoked as the `execute-ability` MCP tool,
	 * ToolsHandler re-wraps this envelope into a WP_Error and runs it through
	 * `create_error_result()`, which is the single place that filter fires.
	 * Applying it here too would run it twice per failure.
	 *
	 * @since 0.7.0
	 *
	 * @param \WP_Error $error The execution failure.
	 *
	 * @return array Array containing the failure envelope.
	 */
	private static function error_result( WP_Error $error ): array {
		return array(
			'success'    => false,
			'error'      => $error->get_error_message(),
			'error_code' => ExecutionErrorSanitizer::sanitize_code( $error->get_error_code() ),
			'error_data' => ExecutionErrorSanitizer::sanitize_data( $error->get_error_data() ),
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
	 */
	public static function check_permission( $input = array() ) {
		$ability_name = $input['ability_name'] ?? '';

		if ( empty( $ability_name ) ) {
			return new WP_Error( 'missing_ability_name', __( 'Ability name is required', 'mcp-adapter' ) );
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
			return new WP_Error(
				'ability_not_found',
				sprintf(
					/* translators: %s: ability name */
					__( "Ability '%s' not found", 'mcp-adapter' ),
					$ability_name
				)
			);
		}

		// Normalize parameters for ability's schema requirements
		// Empty {} from MCP is treated as null for abilities without input schema
		$parameters        = $input['parameters'] ?? null;
		$parameters        = AbilityArgumentNormalizer::normalize( $ability, $parameters );
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
		// Verify caller identity - ensure the user is authenticated
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'authentication_required', __( 'User must be authenticated to access this ability', 'mcp-adapter' ) );
		}

		/**
		 * Filters the capability required to execute abilities.
		 *
		 * This is intentionally set to 'read' as the minimum baseline capability.
		 * Each ability defines its own permission_callback that enforces the actual
		 * capability requirements for that specific operation. This filter serves
		 * only as a gate to prevent completely unauthenticated or capability-less
		 * users from reaching the ability execution layer.
		 *
		 * @since 0.3.0
		 *
		 * @param string $capability The required capability. Default 'read'.
		 */
		$required_capability = apply_filters( 'mcp_adapter_execute_ability_capability', 'read' );
		// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Capability is determined dynamically via filter
		if ( ! current_user_can( $required_capability ) ) {
			return new WP_Error(
				'insufficient_capability',
				sprintf(
					/* translators: %s: required WordPress capability */
					__( 'User lacks required capability: %s', 'mcp-adapter' ),
					$required_capability
				)
			);
		}

		return true;
	}
}
