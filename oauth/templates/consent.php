<?php
/**
 * Consent screen.
 *
 * @package WP\OAuth
 *
 * @var array{client: Client, user: WP_User, params: array<string, string>, request_id: string, action_url: string, nonce_action: string, redirect_host: string, switch_user_url: string} $data
 */

declare( strict_types=1 );

use WP\OAuth\Clients\Client;

defined( 'ABSPATH' ) || exit;

$oauth_server_site = get_bloginfo( 'name' );
$oauth_server_app  = $data['client']->get_name();

login_header(
	/* translators: %s: application name. */
	sprintf( __( 'Connect %s', 'mcp-adapter' ), $oauth_server_app )
);
?>
<style>
	#oauth-server-consent .oauth-server-user { display: flex; gap: 12px; align-items: center; margin-bottom: 16px; }
	#oauth-server-consent .oauth-server-user img { border-radius: 50%; }
	#login #oauth-server-consent p:not(.submit) { margin: 0 0 12px; }
	#oauth-server-consent .oauth-server-warning { border-left: 4px solid #dba617; padding: 8px 12px; background: #fff8e5; }
	#login #oauth-server-consent p.submit { display: flex; gap: 8px; justify-content: flex-end; margin: 20px 0 0; padding: 0; }
</style>
<form id="oauth-server-consent" method="post" action="<?php echo esc_url( $data['action_url'] ); ?>">
	<h1 style="font-size:1.3em;margin-bottom:16px;">
		<?php
		/* translators: 1: application name, 2: site name. */
		echo esc_html( sprintf( __( 'Connect %1$s to %2$s', 'mcp-adapter' ), $oauth_server_app, $oauth_server_site ) );
		?>
	</h1>

	<div class="oauth-server-user">
		<?php echo get_avatar( $data['user']->ID, 48 ); ?>
		<span>
			<?php
			echo wp_kses(
				sprintf(
					/* translators: %s: user display name. */
					__( 'Signed in as <strong>%s</strong>', 'mcp-adapter' ),
					esc_html( $data['user']->display_name )
				),
				array( 'strong' => array() )
			);
			?>
		</span>
	</div>

	<?php if ( '' !== $data['params']['resource'] ) : ?>
		<p>
			<?php
			echo wp_kses(
				sprintf(
					/* translators: %s: application name. */
					__( '<strong>%s</strong> wants to use this site as you.', 'mcp-adapter' ),
					esc_html( $oauth_server_app )
				),
				array( 'strong' => array() )
			);
			?>
		</p>
		<p>
			<?php
			/* translators: %s: exact OAuth resource URL. */
			echo esc_html( sprintf( __( 'Access will be limited to this resource: %s', 'mcp-adapter' ), $data['params']['resource'] ) );
			?>
		</p>
	<?php else : ?>
		<p>
			<?php
			echo wp_kses(
				sprintf(
					/* translators: %s: application name. */
					__( '<strong>%s</strong> wants unrestricted REST API access. It will be able to do anything through the REST API that your account can do.', 'mcp-adapter' ),
					esc_html( $oauth_server_app )
				),
				array( 'strong' => array() )
			);
			?>
		</p>
	<?php endif; ?>
	<?php if ( $data['client']->is_cimd() ) : ?>
		<p>
			<?php
			/* translators: %s: metadata publisher host. */
			echo esc_html( sprintf( __( 'Application details were published by %s.', 'mcp-adapter' ), $data['client']->get_publisher_host() ) );
			?>
		</p>
		<?php if ( ! $data['client']->is_trusted() ) : ?>
			<p class="oauth-server-warning"><strong><?php esc_html_e( 'This publisher has not been verified by this site. Only approve if you started this connection and recognize the application.', 'mcp-adapter' ); ?></strong></p>
		<?php endif; ?>
	<?php endif; ?>
	<?php if ( '' !== $data['redirect_host'] ) : ?>
		<p>
			<?php
			/* translators: %s: host name the user is sent back to. */
			echo esc_html( sprintf( __( 'If you approve, you will be sent back to %s.', 'mcp-adapter' ), $data['redirect_host'] ) );
			?>
		</p>
	<?php endif; ?>
	<p><?php esc_html_e( 'You can disconnect it at any time from Application Passwords on your profile.', 'mcp-adapter' ); ?></p>

	<?php
	/**
	 * Fires inside the consent form, before the buttons.
	 *
	 * Use it to add fields, such as options that limit what the application can do.
	 *
	 * @param Client $client Client asking for access.
	 * @param WP_User                 $user   Current user.
	 * @param array<string, string>    $params Authorization request parameters.
	 */
	do_action( 'oauth_server_consent_form', $data['client'], $data['user'], $data['params'] );
	?>

	<input type="hidden" name="request_id" value="<?php echo esc_attr( $data['request_id'] ); ?>" />
	<?php wp_nonce_field( $data['nonce_action'] ); ?>

	<p class="submit">
		<button type="submit" name="deny" value="1" class="button button-large"><?php esc_html_e( 'Cancel', 'mcp-adapter' ); ?></button>
		<button type="submit" name="approve" value="1" class="button button-primary button-large"><?php esc_html_e( 'Approve', 'mcp-adapter' ); ?></button>
	</p>
</form>
<p id="nav">
	<a href="<?php echo esc_url( $data['switch_user_url'] ); ?>"><?php esc_html_e( 'Not you? Switch user', 'mcp-adapter' ); ?></a>
</p>
<?php
login_footer();
