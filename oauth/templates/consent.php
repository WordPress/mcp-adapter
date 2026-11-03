<?php
/**
 * Consent screen.
 *
 * @package WP\OAuth
 *
 * @var array{client: \WP\OAuth\Clients\Client, user: \WP_User, params: array<string, string>, action_url: string, nonce_action: string, redirect_host: string} $data
 */

defined( 'ABSPATH' ) || exit;

$oauth_server_site = get_bloginfo( 'name' );
$oauth_server_app  = $data['client']->get_name();

login_header(
	/* translators: %s: application name. */
	sprintf( __( 'Connect %s', 'mcp-adapter' ), $oauth_server_app )
);
?>
<form id="oauth-server-consent" method="post" action="<?php echo esc_url( $data['action_url'] ); ?>">
	<h1 style="font-size:1.3em;margin-bottom:1em;">
		<?php
		/* translators: 1: application name, 2: site name. */
		echo esc_html( sprintf( __( 'Connect %1$s to %2$s', 'mcp-adapter' ), $oauth_server_app, $oauth_server_site ) );
		?>
	</h1>
	<p>
		<?php
		echo wp_kses(
			sprintf(
				/* translators: 1: application name, 2: user display name. */
				__( '<strong>%1$s</strong> wants to use this site as <strong>%2$s</strong>. It will be able to do anything your account can do.', 'mcp-adapter' ),
				esc_html( $oauth_server_app ),
				esc_html( $data['user']->display_name )
			),
			array( 'strong' => array() )
		);
		?>
	</p>
	<?php if ( '' !== $data['redirect_host'] ) : ?>
		<p>
			<?php
			/* translators: %s: host name the user is sent back to. */
			echo esc_html( sprintf( __( 'If you approve, you will be sent back to %s.', 'mcp-adapter' ), $data['redirect_host'] ) );
			?>
		</p>
	<?php endif; ?>
	<p>
		<?php esc_html_e( 'You can disconnect it at any time from Application Passwords on your profile.', 'mcp-adapter' ); ?>
	</p>

	<?php foreach ( $data['params'] as $oauth_server_name => $oauth_server_value ) : ?>
		<input type="hidden" name="<?php echo esc_attr( $oauth_server_name ); ?>" value="<?php echo esc_attr( $oauth_server_value ); ?>" />
	<?php endforeach; ?>
	<?php wp_nonce_field( $data['nonce_action'] ); ?>

	<p class="submit" style="display:flex;gap:8px;justify-content:flex-end;margin-top:1.5em;">
		<button type="submit" name="deny" value="1" class="button button-large"><?php esc_html_e( 'Cancel', 'mcp-adapter' ); ?></button>
		<button type="submit" name="approve" value="1" class="button button-primary button-large"><?php esc_html_e( 'Approve', 'mcp-adapter' ); ?></button>
	</p>
</form>
<?php
login_footer();
