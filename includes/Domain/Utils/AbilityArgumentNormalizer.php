<?php
/**
 * Normalizes ability arguments for MCP protocol compatibility.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Domain\Utils;

/**
 * Normalizes ability arguments between MCP and WordPress Abilities API.
 *
 * MCP clients send {} (empty object) for tools without arguments.
 * PHP decodes this as [] (empty array).
 * Abilities without input_schema expect null, not empty array.
 *
 * @since n.e.x.t
 */
class AbilityArgumentNormalizer {

	/**
	 * Normalize parameters for an ability based on its input schema.
	 *
	 * If the ability has no input schema, empty arrays are converted to null.
	 * This ensures compatibility with abilities that don't accept parameters.
	 *
	 * @param \WP_Ability $ability The ability to normalize parameters for.
	 * @param mixed $parameters The parameters to normalize.
	 *
	 * @return mixed Normalized parameters (null if ability has no schema and params are empty).
	 * @since n.e.x.t
	 *
	 */
	public static function normalize( \WP_Ability $ability, $parameters ) {
		$input_schema = $ability->get_input_schema();

		if ( empty( $input_schema ) && is_array( $parameters ) && empty( $parameters ) ) {
			return null;
		}

		return $parameters;
	}
}
