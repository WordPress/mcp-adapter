<?php
/**
 * Stand-ins for the Agent Users helpers from WordPress/ai#1068.
 *
 * The real helpers read the `wpai_agent` and `wpai_agent_parent` user meta through
 * `Agent_Account`. Loaded only by tests that need them; they return false and null
 * for every user without the meta.
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

if ( ! function_exists( 'wpai_get_agent_parent' ) ) {
	/**
	 * The user an agent acts on behalf of.
	 *
	 * @param \WP_User|int $agent Agent user object or ID.
	 */
	function wpai_get_agent_parent( $agent ): ?\WP_User {
		if ( ! wpai_is_agent_user( $agent ) ) {
			return null;
		}
		$agent_id = $agent instanceof \WP_User ? $agent->ID : (int) $agent;
		$parent   = get_userdata( (int) get_user_meta( $agent_id, 'wpai_agent_parent', true ) );

		return $parent instanceof \WP_User ? $parent : null;
	}
}
