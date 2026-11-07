<?php
/**
 * Stand-in for the Agent Users helper from WordPress/ai#1068.
 *
 * The real helper reads the `wpai_agent` user meta through `Agent_Account::is_agent()`.
 * Loaded only by tests that need it; returns false for every user without the meta.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

if ( ! function_exists( 'wpai_is_agent_user' ) ) {
	/**
	 * Whether a user is marked as an agent.
	 *
	 * @param \WP_User|int $user User object or ID.
	 */
	function wpai_is_agent_user( $user ): bool {
		$user_id = $user instanceof \WP_User ? $user->ID : (int) $user;

		return $user_id > 0 && '1' === get_user_meta( $user_id, 'wpai_agent', true );
	}
}
