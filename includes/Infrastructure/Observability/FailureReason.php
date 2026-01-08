<?php
/**
 * Centralized failure reason taxonomy for observability.
 *
 * This class defines a stable, documented vocabulary of failure reasons
 * used in observability events and error metadata. Using constants ensures
 * consistent categorization across all MCP adapter components.
 *
 * @package McpAdapter
 * @since   n.e.x.t
 */

declare( strict_types=1 );

namespace WP\MCP\Infrastructure\Observability;

/**
 * Failure reason taxonomy for observability events.
 *
 * Categories:
 * - Registration failures: Occur during component registration at boot time
 * - Permission failures: Occur when permission checks fail at execution time
 * - Execution failures: Occur during component execution
 *
 * @since n.e.x.t
 */
final class FailureReason {

	// =========================================================================
	// Registration Failures
	// =========================================================================

	/**
	 * WordPress ability was not found in the registry.
	 *
	 * Occurs when an ability name is passed to register_tools/resources/prompts
	 * but wp_get_ability() returns null.
	 */
	public const ABILITY_NOT_FOUND = 'ability_not_found';

	/**
	 * A resource with the same URI is already registered.
	 *
	 * MCP resources must have unique URIs. This failure occurs when
	 * attempting to register a duplicate.
	 */
	public const DUPLICATE_URI = 'duplicate_uri';

	/**
	 * Prompt builder threw an exception during instantiation or build.
	 *
	 * Occurs when a class implementing McpPromptBuilderInterface throws
	 * during construction or the build() method.
	 */
	public const BUILDER_EXCEPTION = 'builder_exception';

	/**
	 * Component was created without a permission callback.
	 *
	 * Domain components (McpTool, McpResource, McpPrompt) require a permission
	 * callback. This failure occurs when check_permission() is called but
	 * no callback was configured.
	 */
	public const NO_PERMISSION_STRATEGY = 'no_permission_strategy';

	/**
	 * Ability conversion returned a WP_Error.
	 *
	 * Occurs when McpTool::fromAbility(), McpResource::fromAbility(), or
	 * McpPrompt::fromAbility() fails due to invalid ability configuration.
	 */
	public const ABILITY_CONVERSION_FAILED = 'ability_conversion_failed';

	// =========================================================================
	// Permission Failures
	// =========================================================================

	/**
	 * Generic permission denied without specific reason.
	 *
	 * Used when a permission callback returns false without providing
	 * a WP_Error with more specific context.
	 */
	public const PERMISSION_DENIED = 'permission_denied';

	/**
	 * Permission callback returned a WP_Error.
	 *
	 * When the permission callback returns a WP_Error, the WP_Error code
	 * is logged separately in the 'wp_error_code' tag while this failure
	 * reason indicates the permission check explicitly failed.
	 */
	public const PERMISSION_CHECK_FAILED = 'permission_check_failed';

	// =========================================================================
	// Execution Failures
	// =========================================================================

	/**
	 * Component not found at execution time.
	 *
	 * Occurs when tools/call, resources/read, or prompts/get targets a
	 * component that doesn't exist. Different from ABILITY_NOT_FOUND which
	 * occurs at registration time.
	 */
	public const NOT_FOUND = 'not_found';

	/**
	 * Component execution returned a WP_Error.
	 *
	 * Occurs when the execute() or read() method returns a WP_Error.
	 * The WP_Error code is logged separately in 'wp_error_code'.
	 */
	public const EXECUTION_FAILED = 'execution_failed';

	/**
	 * Component execution threw an exception.
	 *
	 * Occurs when execute(), read(), or handle() throws an exception.
	 * The exception type is logged separately in 'exception_type'.
	 */
	public const EXECUTION_EXCEPTION = 'execution_exception';

	// =========================================================================
	// Validation Failures
	// =========================================================================

	/**
	 * Required parameter was missing from the request.
	 *
	 * JSON-RPC requests require certain parameters (e.g., 'name' for tools/call).
	 */
	public const MISSING_PARAMETER = 'missing_parameter';

	/**
	 * Parameter validation failed (wrong type, out of range, etc.).
	 *
	 * Occurs when input validation against schema or business rules fails.
	 */
	public const INVALID_PARAMETER = 'invalid_parameter';

	// =========================================================================
	// Helper Methods
	// =========================================================================

	/**
	 * Get all valid failure reason values.
	 *
	 * Useful for validation and documentation generation.
	 *
	 * @return array<string> List of all valid failure reason constants.
	 */
	public static function all(): array {
		return array(
			// Registration.
			self::ABILITY_NOT_FOUND,
			self::DUPLICATE_URI,
			self::BUILDER_EXCEPTION,
			self::NO_PERMISSION_STRATEGY,
			self::ABILITY_CONVERSION_FAILED,
			// Permission.
			self::PERMISSION_DENIED,
			self::PERMISSION_CHECK_FAILED,
			// Execution.
			self::NOT_FOUND,
			self::EXECUTION_FAILED,
			self::EXECUTION_EXCEPTION,
			// Validation.
			self::MISSING_PARAMETER,
			self::INVALID_PARAMETER,
		);
	}

	/**
	 * Check if a value is a valid failure reason.
	 *
	 * @param string $value The value to check.
	 *
	 * @return bool True if valid, false otherwise.
	 */
	public static function is_valid( string $value ): bool {
		return in_array( $value, self::all(), true );
	}
}
