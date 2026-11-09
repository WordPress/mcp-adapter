<?php
/**
 * The OAuth connections list on the user profile.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\OAuth;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Lists a user's OAuth connections on this site, each with a Revoke button.
 *
 * The section appears on the user's own profile and on the Edit User screen, so anyone
 * who can edit a user can revoke that user's connections. Each Revoke button is a
 * nonce-protected link to admin-post.php rather than a submit button, because a submit
 * button printed before the profile's own would become the form's default, and pressing
 * Enter in any profile field would revoke a connection.
 *
 * @since n.e.x.t
 */
final class ProfileConnections {

	/**
	 * The admin-post action that revokes a connection.
	 *
	 * @var string
	 */
	private const ACTION = 'mcp_adapter_oauth_revoke';

	/**
	 * Registers the hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'show_user_profile', array( self::class, 'render' ) );
		add_action( 'edit_user_profile', array( self::class, 'render' ) );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'revoke' ) );
	}

	/**
	 * Prints the section when the user has connections on this site.
	 *
	 * @param \WP_User $user The profile's user.
	 * @return void
	 */
	public static function render( $user ): void {
		if ( ! $user instanceof \WP_User ) {
			return;
		}

		$connections = Grants::list_for_user( $user );
		if ( array() === $connections ) {
			return;
		}

		uasort(
			$connections,
			static function ( array $a, array $b ): int {
				return (int) ( $b['used'] ?? 0 ) <=> (int) ( $a['used'] ?? 0 );
			}
		);

		echo '<h2 id="mcp-adapter-oauth">' . esc_html__( 'MCP connections', 'mcp-adapter' ) . '</h2>';
		echo '<p>' . esc_html__( 'Apps approved to use this site as this user through OAuth. Revoking one disconnects it immediately.', 'mcp-adapter' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'App', 'mcp-adapter' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Returns to', 'mcp-adapter' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Approved', 'mcp-adapter' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Last active', 'mcp-adapter' ) . '</th>';
		echo '<td></td></tr></thead><tbody>';
		foreach ( $connections as $id => $connection ) {
			echo '<tr>';
			echo '<td>' . esc_html( (string) ( $connection['client_name'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( Clients::return_label( (string) ( $connection['redirect_uri'] ?? '' ) ) ) . '</td>';
			echo '<td>' . esc_html( (string) wp_date( (string) get_option( 'date_format' ), (int) ( $connection['created'] ?? 0 ) ) ) . '</td>';
			/* translators: %s: human-readable time difference, such as "3 hours". */
			echo '<td>' . esc_html( sprintf( __( '%s ago', 'mcp-adapter' ), human_time_diff( (int) ( $connection['used'] ?? 0 ) ) ) ) . '</td>';
			$url = add_query_arg(
				array(
					'action'     => self::ACTION,
					'user_id'    => $user->ID,
					'connection' => $id,
				),
				admin_url( 'admin-post.php' )
			);
			echo '<td><a class="button" href="' . esc_url( wp_nonce_url( $url, self::nonce_action( $user->ID, (string) $id ) ) ) . '">' . esc_html__( 'Revoke', 'mcp-adapter' ) . '</a></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Deletes the connection a Revoke link names, then returns to the profile.
	 * Hooked to `admin_post_mcp_adapter_oauth_revoke`.
	 *
	 * @return void
	 */
	public static function revoke(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read to build the nonce action that check_admin_referer() verifies next.
		$user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
		$id      = isset( $_GET['connection'] ) ? sanitize_key( wp_unslash( $_GET['connection'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		check_admin_referer( self::nonce_action( $user_id, $id ) );
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to edit this user.', 'mcp-adapter' ), 403 );
		}

		Grants::delete( $user_id, $id );
		wp_safe_redirect( get_edit_user_link( $user_id ) . '#mcp-adapter-oauth' );
		exit;
	}

	/**
	 * The nonce action for revoking one connection of one user.
	 *
	 * @param int    $user_id The user ID.
	 * @param string $id      The connection ID.
	 * @return string The nonce action.
	 */
	private static function nonce_action( int $user_id, string $id ): string {
		return self::ACTION . '_' . $user_id . '_' . $id;
	}
}
