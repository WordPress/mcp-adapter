<?php
/**
 * Helper for managing WordPress Abilities API hooks.
 *
 * @package WP\MCP\Tests\Helpers
 */

declare(strict_types=1);

namespace WP\MCP\Tests\Helpers;

/**
 * Helper class for managing both old and new WordPress Abilities API hook names.
 *
 * The WordPress Abilities API is transitioning from unprefixed hooks (abilities_api_*)
 * to wp-prefixed hooks (wp_abilities_api_*). This helper simplifies attaching callbacks
 * to multiple hook variants during the transition period.
 */
final class HookHelper {

	/**
	 * Attach a callback to multiple hook variants.
	 *
	 * @param array                 $hooks Array of hook names to attach to (e.g., ['abilities_api_init', 'wp_abilities_api_init']).
	 * @param callable|array|string $callback The callback to attach (function name, array, or closure).
	 * @param int                   $priority The priority for the action. Default 10.
	 *
	 * @return void
	 */
	public static function add_actions( array $hooks, $callback, int $priority = 10 ): void {
		foreach ( $hooks as $hook ) {
			add_action( $hook, $callback, $priority );
		}
	}

	/**
	 * Remove a callback from multiple hook variants.
	 *
	 * @param array                 $hooks Array of hook names to remove from (e.g., ['abilities_api_init', 'wp_abilities_api_init']).
	 * @param callable|array|string $callback The callback to remove (function name, array, or closure).
	 * @param int                   $priority The priority for the action. Default 10.
	 *
	 * @return void
	 */
	public static function remove_actions( array $hooks, $callback, int $priority = 10 ): void {
		foreach ( $hooks as $hook ) {
			remove_action( $hook, $callback, $priority );
		}
	}
}
