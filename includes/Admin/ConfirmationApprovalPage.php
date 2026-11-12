<?php
/**
 * wp-admin page where a person approves a confirmed Ability call.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Admin;

use WP\MCP\Handlers\Tools\ToolCallConfirmation;

/**
 * Approval link for clients with URL elicitation.
 *
 * The link opens admin-post.php, so the person signs in to WordPress as themselves
 * first. Only the approver recorded with the confirmation (the calling person, or the
 * agent user's parent) can see the request or answer it; answering needs a nonce for
 * that confirmation. The link stops working when the confirmation expires.
 *
 * Experimental.
 *
 * @internal
 * @since n.e.x.t
 */
final class ConfirmationApprovalPage {

	/**
	 * admin-post.php action name.
	 *
	 * @var string
	 */
	public const ACTION = 'mcp_adapter_confirmation';

	/**
	 * Register the admin-post handlers.
	 */
	public static function register(): void {
		if ( has_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) ) ) {
			return;
		}
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( self::class, 'require_login' ) );
	}

	/**
	 * The approval link for a confirmation.
	 *
	 * @param string $id Confirmation identifier.
	 */
	public static function url( string $id ): string {
		return add_query_arg(
			array(
				'action'       => self::ACTION,
				'confirmation' => $id,
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Nonce action for one confirmation.
	 *
	 * @param string $id Confirmation identifier.
	 */
	public static function nonce_action( string $id ): string {
		return self::ACTION . '_' . $id;
	}

	/**
	 * Send signed-out visitors to the login screen and back.
	 */
	public static function require_login(): void {
		auth_redirect();
	}

	/**
	 * Show the request (GET) or record the answer (POST).
	 */
	public static function handle(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The identifier is checked against the stored record; answering verifies a nonce below.
		$id      = isset( $_REQUEST['confirmation'] ) ? sanitize_key( wp_unslash( (string) $_REQUEST['confirmation'] ) ) : '';
		$user_id = get_current_user_id();
		$title   = __( 'Approve an agent change', 'mcp-adapter' );

		if ( 'POST' === strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) : '' ) ) {
			check_admin_referer( self::nonce_action( $id ) );
			$approve = isset( $_POST['decision'] ) && 'approve' === sanitize_key( wp_unslash( (string) $_POST['decision'] ) );
			$result  = ToolCallConfirmation::answer( $id, $user_id, $approve );
			if ( is_wp_error( $result ) ) {
				self::finish( esc_html( $result->get_error_message() ), $title, self::status( $result ) );
				return;
			}
			self::finish(
				$approve
					? esc_html__( 'Approved. Go back to the conversation: the agent can continue now.', 'mcp-adapter' )
					: esc_html__( 'Declined. Nothing will change. You can go back to the conversation.', 'mcp-adapter' ),
				$title,
				200
			);
			return;
		}

		$request = ToolCallConfirmation::approval_request( $id, $user_id );
		if ( is_wp_error( $request ) ) {
			self::finish( esc_html( $request->get_error_message() ), $title, self::status( $request ) );
			return;
		}
		if ( $request['answered'] ) {
			self::finish( esc_html__( 'This request was already answered.', 'mcp-adapter' ), $title, 409 );
			return;
		}

		$requester = get_userdata( $request['requested_by'] );
		$html      = '<h1>' . esc_html( $title ) . '</h1>';
		$html     .= '<p>' . esc_html(
			sprintf(
				/* translators: %s: display name of the user or agent asking */
				__( '%s asks to make this change:', 'mcp-adapter' ),
				$requester ? $requester->display_name : __( 'An agent', 'mcp-adapter' )
			)
		) . '</p>';
		$html .= '<blockquote>' . nl2br( esc_html( $request['summary'] ) ) . '</blockquote>';
		$html .= '<p>' . esc_html(
			sprintf(
				/* translators: %s: expiry time */
				__( 'This request expires at %s.', 'mcp-adapter' ),
				wp_date( get_option( 'time_format' ), $request['expires'] )
			)
		) . '</p>';
		$html .= '<form method="post" action="' . esc_url( self::url( $id ) ) . '">';
		$html .= wp_nonce_field( self::nonce_action( $id ), '_wpnonce', true, false );
		$html .= '<p><button class="button button-primary" type="submit" name="decision" value="approve">' . esc_html__( 'Approve', 'mcp-adapter' ) . '</button> ';
		$html .= '<button class="button" type="submit" name="decision" value="decline">' . esc_html__( 'Decline', 'mcp-adapter' ) . '</button></p>';
		$html .= '</form>';

		self::finish( $html, $title, 200 );
	}

	/**
	 * HTTP status for an error from ToolCallConfirmation.
	 *
	 * @param \WP_Error $error Error.
	 */
	private static function status( \WP_Error $error ): int {
		$data = $error->get_error_data();

		return is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
	}

	/**
	 * Render a minimal page and stop.
	 *
	 * @param string $html  Page body. Every caller escapes what it puts in.
	 * @param string $title Page title.
	 * @param int    $code  HTTP status.
	 */
	private static function finish( string $html, string $title, int $code ): void {
		wp_die( $html, esc_html( $title ), array( 'response' => $code ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts by the callers.
	}
}
