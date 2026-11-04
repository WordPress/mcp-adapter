<?php
/**
 * Prepare ability input before checking permissions.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Domain\Utils;

/**
 * Keep direct permission checks consistent with WP_Ability::execute().
 */
final class AbilityPermissionChecker {

	/**
	 * Apply input preparation before invoking the ability's permission callback.
	 *
	 * @param \WP_Ability $ability   Ability to check.
	 * @param mixed       $arguments MCP arguments.
	 * @return bool|\WP_Error
	 */
	public static function check( \WP_Ability $ability, $arguments ) {
		$input = AbilityArgumentNormalizer::normalize( $ability, $arguments );
		$input = $ability->normalize_input( $input );
		if ( is_wp_error( $input ) ) {
			return $input;
		}

		$validity = $ability->validate_input( $input );
		if ( is_wp_error( $validity ) ) {
			return $validity;
		}

		// Input coercion was introduced in WordPress 7.2.
		if ( method_exists( $ability, 'coerce_input' ) ) {
			$input = $ability->coerce_input( $input );
			if ( is_wp_error( $input ) ) {
				return $input;
			}
		}

		return $ability->check_permissions( $input );
	}
}
