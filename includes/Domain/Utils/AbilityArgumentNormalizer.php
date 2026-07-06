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
 *
 * Abilities without an input_schema expect null, not an empty array.
 * Abilities with an input_schema expect an empty array, never null. PHP's
 * [] satisfies an empty object or empty array schema, but null fails
 * validation as "not of type <schema type>" (object, array, and so on).
 *
 * @since 0.5.0
 */
class AbilityArgumentNormalizer {

	/**
	 * Normalize parameters for an ability based on its input schema.
	 *
	 * No input schema: empty arrays are converted to null, so abilities that
	 * take no parameters see null.
	 * Has input schema: null or an empty array is converted to an empty array,
	 * so a zero-argument call passes schema validation instead of failing as
	 * "not of type <schema type>" (object, array, and so on).
	 *
	 * @param \WP_Ability $ability    The ability to normalize parameters for.
	 * @param mixed       $parameters The parameters to normalize.
	 *
	 * @return mixed Normalized parameters (null when no schema and params are empty; empty array when a schema is present and params are empty or null).
	 * @since 0.5.0
	 * @since n.e.x.t Empty or null parameters for schema-defining abilities normalize to an empty array.
	 */
	public static function normalize( \WP_Ability $ability, $parameters ) {
		$input_schema = $ability->get_input_schema();

		// No schema: an empty {} means "no arguments" -> null.
		if ( empty( $input_schema ) ) {
			return is_array( $parameters ) && empty( $parameters ) ? null : $parameters;
		}

		// Has schema: a missing/empty argument set (null or {}) -> [], which
		// satisfies an empty object or array schema. null never validates.
		if ( null === $parameters || array() === $parameters ) {
			return array();
		}

		return $parameters;
	}
}
