<?php
/**
 * Helper trait for WordPress abilities providing MCP-related utilities.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Abilities;

use WP_Error;

/**
 * Trait McpAbilityHelperTrait
 *
 * Provides helper methods for MCP abilities including MCP exposure checking and metadata handling.
 */
trait McpAbilityHelperTrait {

	/**
	 * Checks if ability is publicly exposed via MCP.
	 *
	 * Validates against the ability's resolved mcp.public metadata flag.
	 * Only abilities with effective MCP public exposure are accessible via default MCP server.
	 *
	 * @param string $ability_name The ability name to check.
	 *
	 * @return bool|\WP_Error True if publicly exposed, WP_Error if not.
	 */
	protected static function check_ability_mcp_exposure( string $ability_name ) {
		// An unknown name from an MCP client is expected input, not a developer
		// mistake, so check the registry before wp_get_ability(), which flags a
		// miss with _doing_it_wrong().
		$ability = wp_has_ability( $ability_name ) ? wp_get_ability( $ability_name ) : null;

		// An ability that is registered but not exposed through MCP does not
		// exist for an MCP client either, so it gets the same answer as an
		// unknown name. Naming the difference only sent agents looking for a
		// switch the site owner does not have.
		if ( ! $ability || ! McpAbilityExposure::is_public( $ability ) ) {
			$suggestions = AbilityNameSuggester::suggest( $ability_name );

			return new WP_Error(
				'ability_not_found',
				self::ability_not_found_message( $ability_name, $suggestions ),
				array( 'suggestions' => $suggestions )
			);
		}

		return true;
	}

	/**
	 * Builds the message for an ability name that is not registered.
	 *
	 * Says plainly that the ability is not on this site and names the closest
	 * abilities the client can call, so it takes one of them or stops instead
	 * of guessing more names.
	 *
	 * @since n.e.x.t
	 *
	 * @param string        $ability_name The ability name the client asked for.
	 * @param string[]|null $suggestions  Suggestions already computed for it, or null to compute them.
	 *
	 * @return string
	 */
	protected static function ability_not_found_message( string $ability_name, ?array $suggestions = null ): string {
		$suggestions = $suggestions ?? AbilityNameSuggester::suggest( $ability_name );

		if ( array() !== $suggestions ) {
			return sprintf(
				'Ability \'%1$s\' not found on this WordPress site. Closest abilities you can call: %2$s. Use one of these if it does what you need; guessing other spellings will not find \'%1$s\'.',
				$ability_name,
				implode( ', ', $suggestions )
			);
		}

		return sprintf(
			'Ability \'%s\' not found on this WordPress site, and no ability you can call has a similar name. Call the mcp-adapter-discover-abilities tool to see what this site offers, or tell the user this site cannot do it.',
			$ability_name
		);
	}

	/**
	 * Checks if ability is publicly exposed via MCP (simple boolean version).
	 *
	 * This is a simplified version that returns only boolean values,
	 * useful for filtering operations where WP_Error handling isn't needed.
	 *
	 * @param \WP_Ability $ability The ability object to check.
	 *
	 * @return bool True if publicly exposed, false otherwise.
	 */
	protected static function is_ability_mcp_public( \WP_Ability $ability ): bool {
		return McpAbilityExposure::is_public( $ability );
	}

	/**
	 * Gets the MCP type of an ability.
	 *
	 * Returns the type specified in meta.mcp.type, defaulting to 'tool' if not specified.
	 *
	 * @param \WP_Ability $ability The ability object to check.
	 *
	 * @return string The MCP type ('tool', 'resource', or 'prompt'). Defaults to 'tool'.
	 */
	protected static function get_ability_mcp_type( \WP_Ability $ability ): string {
		$meta = $ability->get_meta();
		$type = $meta['mcp']['type'] ?? 'tool';

		// Validate type is one of the allowed values
		if ( ! in_array( $type, array( 'tool', 'resource', 'prompt' ), true ) ) {
			return 'tool';
		}

		return $type;
	}
}
