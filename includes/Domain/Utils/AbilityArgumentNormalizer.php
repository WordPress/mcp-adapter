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
 * Abilities with an input_schema usually expect an empty array, never null:
 * PHP's [] satisfies an empty object or empty array schema, but null fails
 * validation as "not of type <schema type>" (object, array, and so on). Two
 * exceptions keep null: a schema whose type explicitly permits null (an
 * explicit null value is passed through), and a schema with a top-level
 * default, where null lets the Abilities API apply that default for both a
 * null and an empty {} input. In both, null is the author's declared intent.
 *
 * @since 0.5.0
 */
class AbilityArgumentNormalizer {

	/**
	 * Normalize parameters for an ability based on its input schema.
	 *
	 * No input schema: empty arrays are converted to null, so abilities that
	 * take no parameters see null.
	 * Has input schema: null or an empty array normalizes to an empty array so a
	 * zero-argument call passes schema validation instead of failing as "not of
	 * type <schema type>" (object, array, and so on). Two exceptions return null:
	 * a top-level default (both null and {} return null so the Abilities API
	 * applies the default) and a type that explicitly permits null (an explicit
	 * null is passed through).
	 *
	 * @param \WP_Ability $ability    The ability to normalize parameters for.
	 * @param mixed       $parameters The parameters to normalize.
	 *
	 * @return mixed Normalized parameters (null when no schema and params are empty; empty array when a schema is present and params are empty or null, unless the schema declares a top-level default or its type permits null, in which case null is returned).
	 * @since 0.5.0
	 * @since 0.6.0 Empty or null parameters for schema-defining abilities normalize to an empty array, except when the schema declares a top-level default (honored for both null and empty {} input) or its type explicitly permits null.
	 */
	public static function normalize( \WP_Ability $ability, $parameters ) {
		$input_schema = $ability->get_input_schema();

		// No schema: an empty {} means "no arguments" -> null.
		if ( empty( $input_schema ) ) {
			return is_array( $parameters ) && empty( $parameters ) ? null : $parameters;
		}

		// Has schema, missing/empty argument set (null or {}).
		if ( null === $parameters || array() === $parameters ) {
			// A top-level default is the author's declared "no input" value.
			// Return null for BOTH null and {} so WP_Ability::normalize_input()
			// applies the default -- it fills the default only when input is null.
			// An MCP client sends {} to mean "no arguments", so {} must honor the
			// default too, not just an omitted parameter.
			if ( array_key_exists( 'default', $input_schema ) ) {
				return null;
			}

			// An explicit null from the client is kept only when the schema's
			// type permits null; {} stays [] as an empty object.
			if ( null === $parameters && self::schema_permits_null( $input_schema ) ) {
				return null;
			}

			// Otherwise use [], which satisfies an empty object or array schema
			// so a zero-argument call validates. null never validates on its own.
			return array();
		}

		return $parameters;
	}

	/**
	 * Whether the schema's top-level type explicitly permits null.
	 *
	 * Only an explicit top-level type is honored (JSON Schema "null", or a type
	 * array containing "null"). Composition keywords that also permit null
	 * (anyOf/oneOf/enum/const) are not inspected; such a schema falls through to
	 * [], which still validates when object or array is among the allowed forms.
	 * A schema with no type is treated as not permitting null, so a zero-argument
	 * call still normalizes to [] for callbacks that expect an array.
	 *
	 * @param array<string,mixed> $input_schema The ability input schema.
	 *
	 * @return bool True when null is a valid top-level value for the schema.
	 * @since 0.6.0
	 */
	private static function schema_permits_null( array $input_schema ): bool {
		$type = $input_schema['type'] ?? null;

		if ( is_array( $type ) ) {
			return in_array( 'null', $type, true );
		}

		return 'null' === $type;
	}
}
