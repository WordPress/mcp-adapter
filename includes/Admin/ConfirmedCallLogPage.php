<?php
/**
 * Tools page listing confirmed agent changes.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Admin;

/**
 * Tools → Agent changes: what agents changed after a person said yes.
 *
 * Experimental.
 *
 * @internal
 * @since n.e.x.t
 */
final class ConfirmedCallLogPage {

	/**
	 * Admin page slug.
	 *
	 * @var string
	 */
	public const SLUG = 'mcp-adapter-agent-changes';

	/**
	 * Add the page under Tools.
	 */
	public static function add_page(): void {
		add_management_page(
			__( 'Agent changes', 'mcp-adapter' ),
			__( 'Agent changes', 'mcp-adapter' ),
			'edit_posts',
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	/**
	 * Render the page for the current user.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		$entries = ConfirmedCallLog::entries( get_current_user_id() );

		echo '<div class="wrap"><h1>' . esc_html__( 'Agent changes', 'mcp-adapter' ) . '</h1>';
		echo '<p>' . esc_html__( 'Changes made over MCP after a person confirmed them, newest first. The input itself is not kept, only what the person was shown.', 'mcp-adapter' ) . '</p>';

		if ( array() === $entries ) {
			echo '<p>' . esc_html__( 'No confirmed changes yet.', 'mcp-adapter' ) . '</p></div>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'When', 'mcp-adapter' ), __( 'Who', 'mcp-adapter' ), __( 'Change', 'mcp-adapter' ), __( 'Confirmed', 'mcp-adapter' ), __( 'Result', 'mcp-adapter' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $entries as $entry ) {
			echo '<tr>';
			echo '<td>' . esc_html( (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $entry['confirmed_at'] ) ) . '</td>';
			echo '<td>' . esc_html( self::who( $entry ) ) . '</td>';
			echo '<td>' . nl2br( esc_html( (string) $entry['summary'] ) ) . '<br><code>' . esc_html( (string) $entry['ability'] ) . '</code></td>';
			echo '<td>' . esc_html( self::how( $entry ) ) . '</td>';
			echo '<td>' . esc_html( self::result( (string) $entry['status'] ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Who ran the call.
	 *
	 * @param array<string, mixed> $entry Log entry.
	 */
	private static function who( array $entry ): string {
		$name = '' !== (string) $entry['user_name'] ? (string) $entry['user_name'] : sprintf( '#%d', (int) $entry['user_id'] );
		if ( ! (int) $entry['is_agent'] ) {
			return $name;
		}
		if ( '' === (string) $entry['parent_name'] ) {
			/* translators: %s: agent name */
			return sprintf( __( '%s (agent)', 'mcp-adapter' ), $name );
		}

		/* translators: 1: agent name, 2: parent name */
		return sprintf( __( '%1$s (agent of %2$s)', 'mcp-adapter' ), $name, (string) $entry['parent_name'] );
	}

	/**
	 * How the person said yes.
	 *
	 * @param array<string, mixed> $entry Log entry.
	 */
	private static function how( array $entry ): string {
		switch ( (string) $entry['channel'] ) {
			case 'url':
				/* translators: %s: approver name */
				$how = sprintf( __( 'Approved by %s in wp-admin', 'mcp-adapter' ), '' !== (string) $entry['approver_name'] ? (string) $entry['approver_name'] : sprintf( '#%d', (int) $entry['approved_by'] ) );
				break;
			case 'form':
				$how = __( 'Answered in the MCP client', 'mcp-adapter' );
				break;
			default:
				$how = __( 'Agent reported a yes in the chat', 'mcp-adapter' );
		}
		if ( '' !== (string) $entry['follow_up_of'] ) {
			$how .= ' ' . __( '(covered by an earlier yes)', 'mcp-adapter' );
		}

		return $how;
	}

	/**
	 * Plain result label.
	 *
	 * @param string $status Stored status.
	 */
	private static function result( string $status ): string {
		$labels = array(
			'running' => __( 'Running or unknown', 'mcp-adapter' ),
			'success' => __( 'Done', 'mcp-adapter' ),
			'error'   => __( 'Returned an error', 'mcp-adapter' ),
			'failed'  => __( 'Did not complete', 'mcp-adapter' ),
		);

		return $labels[ $status ] ?? $labels['running'];
	}
}
