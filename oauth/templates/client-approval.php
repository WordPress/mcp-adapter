<?php
/**
 * Site-level CIMD approval screen.
 *
 * @package WP\OAuth
 *
 * @var array{approval: CimdApproval, client: Client, user: WP_User, params: array<string, string>, approval_id: string, action_url: string, nonce_action: string, switch_user_url: string} $data
 */

declare( strict_types=1 );

use WP\OAuth\Clients\Cimd\CimdApproval;
use WP\OAuth\Clients\Client;

defined( 'ABSPATH' ) || exit;

$oauth_server_app = $data['client']->get_name();

login_header(
	$data['approval']->is_update()
		? __( 'Approve application changes', 'mcp-adapter' )
		: __( 'Approve application for this site', 'mcp-adapter' )
);
?>
<style>
	#oauth-server-client-approval .oauth-server-user { display: flex; gap: 12px; align-items: center; margin-bottom: 16px; }
	#oauth-server-client-approval .oauth-server-user img { border-radius: 50%; }
	#login #oauth-server-client-approval p:not(.submit) { margin: 0 0 12px; }
	#oauth-server-client-approval .oauth-server-warning { border-left: 4px solid #dba617; padding: 8px 12px; background: #fff8e5; }
	#oauth-server-client-approval dl { margin: 0 0 16px; }
	#oauth-server-client-approval dt { color: #646970; font-size: 12px; margin-top: 8px; }
	#oauth-server-client-approval dd { margin: 2px 0 0; overflow-wrap: anywhere; }
	#oauth-server-client-approval code { font-size: 12px; }
	#login #oauth-server-client-approval p.submit { display: flex; gap: 8px; justify-content: flex-end; margin: 20px 0 0; padding: 0; }
</style>
<form id="oauth-server-client-approval" method="post" action="<?php echo esc_url( $data['action_url'] ); ?>">
	<h1 style="font-size:1.3em;margin-bottom:16px;">
		<?php
		echo esc_html(
			$data['approval']->is_update()
				? sprintf(
					/* translators: %s: application name. */
					__( 'Approve changes to %s', 'mcp-adapter' ),
					$oauth_server_app
				)
				: sprintf(
					/* translators: %s: application name. */
					__( 'Allow %s on this site', 'mcp-adapter' ),
					$oauth_server_app
				)
		);
		?>
	</h1>

	<div class="oauth-server-user">
		<?php echo get_avatar( $data['user']->ID, 48 ); ?>
		<span>
			<?php
			echo wp_kses(
				sprintf(
					/* translators: %s: user display name. */
					__( 'Administrator <strong>%s</strong>', 'mcp-adapter' ),
					esc_html( $data['user']->display_name )
				),
				array( 'strong' => array() )
			);
			?>
		</span>
	</div>

	<?php if ( $data['approval']->is_update() ) : ?>
		<p><?php esc_html_e( 'The application changed security-sensitive metadata. Approving replaces its site registration and revokes its existing user connections.', 'mcp-adapter' ); ?></p>
	<?php else : ?>
		<p><?php esc_html_e( 'This application has not been approved for the site. Review its published registration before allowing users to connect it.', 'mcp-adapter' ); ?></p>
	<?php endif; ?>

	<?php if ( ! $data['approval']->is_trusted() ) : ?>
		<p class="oauth-server-warning"><strong><?php esc_html_e( 'The publisher has not been independently verified by this site.', 'mcp-adapter' ); ?></strong></p>
	<?php endif; ?>

	<dl>
		<dt><?php esc_html_e( 'Metadata publisher', 'mcp-adapter' ); ?></dt>
		<dd><code><?php echo esc_html( $data['approval']->get_client_id() ); ?></code></dd>
		<dt><?php esc_html_e( 'Application name', 'mcp-adapter' ); ?></dt>
		<dd><?php echo esc_html( $data['client']->get_name() ); ?></dd>
		<dt><?php esc_html_e( 'Redirect URIs', 'mcp-adapter' ); ?></dt>
		<dd>
			<?php foreach ( $data['client']->get_redirect_uris() as $oauth_server_redirect_uri ) : ?>
				<code><?php echo esc_html( $oauth_server_redirect_uri ); ?></code><br />
			<?php endforeach; ?>
		</dd>
	</dl>

	<p><?php esc_html_e( 'This approval only allows the application to request access. Each user must still approve access to their own account on a separate consent screen.', 'mcp-adapter' ); ?></p>

	<input type="hidden" name="cimd_approval_id" value="<?php echo esc_attr( $data['approval_id'] ); ?>" />
	<?php wp_nonce_field( $data['nonce_action'] ); ?>

	<p class="submit">
		<button type="submit" name="deny_client" value="1" class="button button-large"><?php esc_html_e( 'Deny', 'mcp-adapter' ); ?></button>
		<button type="submit" name="approve_client" value="1" class="button button-primary button-large"><?php esc_html_e( 'Allow on this site', 'mcp-adapter' ); ?></button>
	</p>
</form>
<p id="nav">
	<a href="<?php echo esc_url( $data['switch_user_url'] ); ?>"><?php esc_html_e( 'Not you? Switch user', 'mcp-adapter' ); ?></a>
</p>
<?php
login_footer();
