<?php
/**
 * Reads the saved settings-page option and toggles `meta.mcp.public` on
 * abilities the administrator has opted into the default MCP server.
 *
 * @package WP\MCP\Admin
 */

declare( strict_types=1 );

namespace WP\MCP\Admin;

/**
 * Class - AbilityExposureFilter
 *
 * Translates a stored array of opted-in ability names into the canonical
 * `meta.mcp.public` flag the adapter consumes when materializing the default
 * MCP server. Implements the `wp_register_ability_args` pattern documented in
 * WordPress contributor guides and used by site administrators today.
 */
final class AbilityExposureFilter {

	/**
	 * Option name where the opted-in ability list is stored.
	 *
	 * @var string
	 */
	public const OPTION = 'mcp_adapter_public_abilities';

	/**
	 * Namespace prefix that the adapter manages internally. Abilities under
	 * this prefix are exempt from the toggle.
	 *
	 * @var string
	 */
	private const MANAGED_NAMESPACE = 'mcp-adapter/';

	/**
	 * Register the registration-time filter.
	 */
	public function register(): void {
		add_filter( 'wp_register_ability_args', array( $this, 'maybe_expose' ), 10, 2 );
	}

	/**
	 * Inject `meta.mcp.public = true` onto abilities the administrator has
	 * opted into via the settings page.
	 *
	 * @param array<string, mixed> $args         Ability registration args.
	 * @param string               $ability_name The ability name being registered.
	 * @return array<string, mixed>
	 */
	public function maybe_expose( array $args, string $ability_name ): array {
		if ( 0 === strpos( $ability_name, self::MANAGED_NAMESPACE ) ) {
			return $args;
		}

		$opted_in = get_option( self::OPTION, array() );
		if ( ! is_array( $opted_in ) || ! in_array( $ability_name, $opted_in, true ) ) {
			return $args;
		}

		if ( ! isset( $args['meta'] ) || ! is_array( $args['meta'] ) ) {
			$args['meta'] = array();
		}
		if ( ! isset( $args['meta']['mcp'] ) || ! is_array( $args['meta']['mcp'] ) ) {
			$args['meta']['mcp'] = array();
		}
		$args['meta']['mcp']['public'] = true;

		return $args;
	}
}
