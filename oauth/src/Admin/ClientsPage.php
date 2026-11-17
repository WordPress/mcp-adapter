<?php
/**
 * Client registration screen.
 *
 * @package WP\OAuth
 */

declare( strict_types=1 );

namespace WP\OAuth\Admin;

use WP\OAuth\Clients\Client;
use WP\OAuth\Clients\ClientRepository;
use WP\OAuth\Server;

defined( 'ABSPATH' ) || exit;

/**
 * Settings > OAuth Clients, where administrators register and edit clients.
 *
 * Follows WP-API/OAuth2's admin screens: a list, an edit screen, and secret
 * regeneration that shows the new secret once.
 */
final class ClientsPage {

	private const SLUG          = 'oauth-server-clients';
	private const ADD_SLUG      = 'oauth-server-add-client';
	private const ADD_ACTION    = 'oauth_server_add_client';
	private const UPDATE_ACTION = 'oauth_server_update_client';
	private const SECRET_ACTION = 'oauth_server_reset_client_secret';
	private const DELETE_ACTION = 'oauth_server_delete_client';
	private const NOTICE_PREFIX = 'oauth_server_notice_';

	/**
	 * Client registry.
	 *
	 * @var ClientRepository
	 */
	private $clients;

	/**
	 * Constructor.
	 *
	 * @param ClientRepository $clients Client registry.
	 */
	public function __construct( ClientRepository $clients ) {
		$this->clients = $clients;
	}

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_head', array( $this, 'hide_add_page_from_menu' ) );
		add_action( 'admin_post_' . self::ADD_ACTION, array( $this, 'handle_add' ) );
		add_action( 'admin_post_' . self::UPDATE_ACTION, array( $this, 'handle_update' ) );
		add_action( 'admin_post_' . self::SECRET_ACTION, array( $this, 'handle_secret' ) );
		add_action( 'admin_post_' . self::DELETE_ACTION, array( $this, 'handle_delete' ) );
	}

	/**
	 * Adds the settings page.
	 */
	public function add_page(): void {
		add_options_page(
			__( 'OAuth Clients', 'mcp-adapter' ),
			__( 'OAuth Clients', 'mcp-adapter' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);

		add_submenu_page(
			'options-general.php',
			__( 'Add OAuth Client', 'mcp-adapter' ),
			__( 'Add OAuth Client', 'mcp-adapter' ),
			'manage_options',
			self::ADD_SLUG,
			array( $this, 'render_add' )
		);
	}

	/**
	 * Hides the add page after WordPress has resolved the current page title.
	 */
	public function hide_add_page_from_menu(): void {
		remove_submenu_page( 'options-general.php', self::ADD_SLUG );
	}

	/**
	 * Handles the add client form.
	 */
	public function handle_add(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage OAuth clients.', 'mcp-adapter' ), 403 );
		}
		check_admin_referer( self::ADD_ACTION );

		$name          = isset( $_POST['client_name'] ) ? sanitize_text_field( wp_unslash( $_POST['client_name'] ) ) : '';
		$redirect_uris = isset( $_POST['redirect_uris'] ) ? ( preg_split( '/\R/', wp_unslash( $_POST['redirect_uris'] ) ) ?: array() ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each URI is validated by the repository; sanitizing would alter percent-encoding.

		$result = $this->clients->create( $name, $redirect_uris, ! empty( $_POST['confidential'] ) );
		if ( is_wp_error( $result ) ) {
			$this->finish_add( array( 'error' => $result->get_error_message() ) );
		}

		[ $client, $secret ] = $result;
		$this->finish(
			array(
				/* translators: %s: client name. */
				'success' => sprintf( __( '%s was registered. Enter these details in the application.', 'mcp-adapter' ), $client->get_name() ),
				'secret'  => $secret,
			),
			$client->get_id()
		);
	}

	/**
	 * Handles the edit client form.
	 */
	public function handle_update(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage OAuth clients.', 'mcp-adapter' ), 403 );
		}
		$client_id = isset( $_POST['client_id'] ) && is_string( $_POST['client_id'] ) ? trim( wp_unslash( $_POST['client_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Exact client ID is part of the signed nonce.
		check_admin_referer( self::UPDATE_ACTION . '_' . $client_id );

		$name          = isset( $_POST['client_name'] ) ? sanitize_text_field( wp_unslash( $_POST['client_name'] ) ) : '';
		$redirect_uris = isset( $_POST['redirect_uris'] ) ? ( preg_split( '/\R/', wp_unslash( $_POST['redirect_uris'] ) ) ?: array() ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each URI is validated by the repository; sanitizing would alter percent-encoding.

		$result = $this->clients->update( $client_id, $name, $redirect_uris );
		if ( is_wp_error( $result ) ) {
			$this->finish( array( 'error' => $result->get_error_message() ), $client_id );
		}

		$this->finish( array( 'success' => __( 'Client updated.', 'mcp-adapter' ) ), $client_id );
	}

	/**
	 * Handles generating or removing a client secret.
	 */
	public function handle_secret(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage OAuth clients.', 'mcp-adapter' ), 403 );
		}
		$client_id = isset( $_POST['client_id'] ) && is_string( $_POST['client_id'] ) ? trim( wp_unslash( $_POST['client_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Exact client ID is part of the signed nonce.
		check_admin_referer( self::SECRET_ACTION . '_' . $client_id );

		$confidential = ! isset( $_POST['mode'] ) || 'remove' !== $_POST['mode'];
		$result       = $this->clients->reset_secret( $client_id, $confidential );
		if ( is_wp_error( $result ) ) {
			$this->finish( array( 'error' => $result->get_error_message() ) );
		}

		$this->finish(
			array(
				'success' => $confidential
					? __( 'A new client secret was generated. The previous secret no longer works.', 'mcp-adapter' )
					: __( 'The client secret was removed. This is now a public client and must use PKCE alone.', 'mcp-adapter' ),
				'secret'  => $result[1],
			),
			$client_id
		);
	}

	/**
	 * Handles the delete client form.
	 */
	public function handle_delete(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage OAuth clients.', 'mcp-adapter' ), 403 );
		}
		$client_id = isset( $_POST['client_id'] ) && is_string( $_POST['client_id'] ) ? trim( wp_unslash( $_POST['client_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Exact client ID is part of the signed nonce.
		check_admin_referer( self::DELETE_ACTION . '_' . $client_id );

		$client = $this->clients->get( $client_id );
		$this->clients->delete( $client_id );

		$this->finish(
			null === $client ? array() : array(
				/* translators: %s: client name. */
				'success' => sprintf( __( '%s was deleted, along with its connections.', 'mcp-adapter' ), $client->get_name() ),
			)
		);
	}

	/**
	 * Renders the page.
	 */
	public function render(): void {
		$client_id = isset( $_GET['client'] ) && is_string( $_GET['client'] ) ? trim( wp_unslash( $_GET['client'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Exact client ID only selects a record and is escaped on output.
		$client    = '' === $client_id ? null : $this->clients->get( $client_id );
		$notice    = $this->take_notice();
		?>
		<div class="wrap">
			<?php if ( null !== $client ) : ?>
				<h1 class="wp-heading-inline"><?php echo esc_html( $client->get_name() ); ?></h1>
				<a href="<?php echo esc_url( $this->page_url() ); ?>" class="page-title-action"><?php esc_html_e( 'All clients', 'mcp-adapter' ); ?></a>
				<hr class="wp-header-end" />
			<?php else : ?>
				<h1 class="wp-heading-inline"><?php esc_html_e( 'OAuth Clients', 'mcp-adapter' ); ?></h1>
				<a href="<?php echo esc_url( $this->add_url() ); ?>" class="page-title-action"><?php esc_html_e( 'Add client', 'mcp-adapter' ); ?></a>
				<hr class="wp-header-end" />
				<p><?php esc_html_e( 'Register applications that can ask users to connect to this site. Each connection appears in the user’s Application Passwords and can be revoked there.', 'mcp-adapter' ); ?></p>
			<?php endif; ?>

			<?php if ( ! Server::is_available() ) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e( 'Application Passwords are not available on this site, so OAuth sign-in is turned off. They need HTTPS, or a local environment type.', 'mcp-adapter' ); ?></p></div>
			<?php endif; ?>

			<?php $this->render_notice( $notice, $client ); ?>

			<?php
			if ( null !== $client ) {
				$this->render_edit( $client );
			} else {
				if ( '' !== $client_id ) {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'That client no longer exists.', 'mcp-adapter' ) . '</p></div>';
				}
				$this->render_list();
			}
			?>
		</div>
		<?php
	}

	/**
	 * Renders the add client page.
	 */
	public function render_add(): void {
		$notice = $this->take_notice();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Add OAuth Client', 'mcp-adapter' ); ?></h1>
			<a href="<?php echo esc_url( $this->page_url() ); ?>" class="page-title-action"><?php esc_html_e( 'All clients', 'mcp-adapter' ); ?></a>
			<hr class="wp-header-end" />

			<?php if ( ! Server::is_available() ) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e( 'Application Passwords are not available on this site, so OAuth sign-in is turned off. They need HTTPS, or a local environment type.', 'mcp-adapter' ); ?></p></div>
			<?php endif; ?>

			<?php $this->render_notice( $notice, null ); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ADD_ACTION ); ?>" />
				<?php wp_nonce_field( self::ADD_ACTION ); ?>
				<table class="form-table" role="presentation">
					<?php $this->render_detail_fields( null ); ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Client secret', 'mcp-adapter' ); ?></th>
						<td>
							<label><input type="checkbox" name="confidential" value="1" /> <?php esc_html_e( 'Generate a client secret', 'mcp-adapter' ); ?></label>
							<p class="description"><?php esc_html_e( 'Only for applications that run on a server and can keep a secret. Leave unchecked for desktop and browser apps.', 'mcp-adapter' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Add client', 'mcp-adapter' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Renders the client list.
	 */
	private function render_list(): void {
		$clients = $this->clients->all();
		?>
		<table class="widefat striped" style="margin:1em 0 2em;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Name', 'mcp-adapter' ); ?></th>
					<th><?php esc_html_e( 'Client ID', 'mcp-adapter' ); ?></th>
					<th><?php esc_html_e( 'Redirect URIs', 'mcp-adapter' ); ?></th>
					<th><?php esc_html_e( 'Type', 'mcp-adapter' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $clients ) ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'No clients registered yet.', 'mcp-adapter' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $clients as $client ) : ?>
					<tr>
						<td>
							<strong><a class="row-title" href="<?php echo esc_url( $this->edit_url( $client ) ); ?>"><?php echo esc_html( $client->get_name() ); ?></a></strong>
							<div class="row-actions visible">
								<span class="edit"><a href="<?php echo esc_url( $this->edit_url( $client ) ); ?>"><?php echo $client->is_cimd() ? esc_html__( 'View', 'mcp-adapter' ) : esc_html__( 'Edit', 'mcp-adapter' ); ?></a> | </span>
								<span class="delete"><?php $this->render_delete_button( $client, true ); ?></span>
							</div>
						</td>
						<td><code><?php echo esc_html( $client->get_id() ); ?></code></td>
						<td><?php echo wp_kses( implode( '<br />', array_map( 'esc_html', $client->get_redirect_uris() ) ), array( 'br' => array() ) ); ?></td>
						<td><?php echo $client->is_cimd() ? esc_html__( 'Metadata document', 'mcp-adapter' ) : ( $client->is_confidential() ? esc_html__( 'Confidential', 'mcp-adapter' ) : esc_html__( 'Public', 'mcp-adapter' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<h2><?php esc_html_e( 'Server details', 'mcp-adapter' ); ?></h2>
		<p><?php esc_html_e( 'Applications normally discover these automatically.', 'mcp-adapter' ); ?></p>
		<ul>
			<li><?php esc_html_e( 'Authorization endpoint:', 'mcp-adapter' ); ?> <code><?php echo esc_html( Server::authorization_endpoint() ); ?></code></li>
			<li><?php esc_html_e( 'Token endpoint:', 'mcp-adapter' ); ?> <code><?php echo esc_html( Server::token_endpoint() ); ?></code></li>
			<li><?php esc_html_e( 'Revocation endpoint:', 'mcp-adapter' ); ?> <code><?php echo esc_html( Server::revocation_endpoint() ); ?></code></li>
		</ul>
		<?php
	}

	/**
	 * Renders the edit screen for a client.
	 *
	 * @param Client $client Client.
	 */
	private function render_edit( Client $client ): void {
		$post_url = admin_url( 'admin-post.php' );
		if ( $client->is_cimd() ) {
			?>
			<p><?php esc_html_e( 'This is a permanent local record of a Client ID Metadata Document. Its fields are read-only and are refreshed from the publisher during authorization.', 'mcp-adapter' ); ?></p>
			<table class="form-table" role="presentation">
				<tr><th scope="row"><?php esc_html_e( 'Client ID', 'mcp-adapter' ); ?></th><td><code><?php echo esc_html( $client->get_id() ); ?></code></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Name', 'mcp-adapter' ); ?></th><td><?php echo esc_html( $client->get_name() ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Redirect URIs', 'mcp-adapter' ); ?></th><td><code><?php echo wp_kses( implode( '<br />', array_map( 'esc_html', $client->get_redirect_uris() ) ), array( 'br' => array() ) ); ?></code></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Publisher trust', 'mcp-adapter' ); ?></th><td><?php echo $client->is_trusted() ? esc_html__( 'Trusted by site policy', 'mcp-adapter' ) : esc_html__( 'Not verified', 'mcp-adapter' ); ?></td></tr>
			</table>
			<h2><?php esc_html_e( 'Delete client', 'mcp-adapter' ); ?></h2>
			<p><?php esc_html_e( 'Deleting this record revokes its connections. The record can be discovered again unless site policy blocks its URL.', 'mcp-adapter' ); ?></p>
			<?php $this->render_delete_button( $client, false ); ?>
			<?php
			return;
		}
		?>
		<form method="post" action="<?php echo esc_url( $post_url ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::UPDATE_ACTION ); ?>" />
			<input type="hidden" name="client_id" value="<?php echo esc_attr( $client->get_id() ); ?>" />
			<?php wp_nonce_field( self::UPDATE_ACTION . '_' . $client->get_id() ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Client ID', 'mcp-adapter' ); ?></th>
					<td><code><?php echo esc_html( $client->get_id() ); ?></code></td>
				</tr>
				<?php $this->render_detail_fields( $client ); ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Registered', 'mcp-adapter' ); ?></th>
					<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $client->get_created() ) ?: '' ); ?></td>
				</tr>
			</table>
			<p class="description"><?php esc_html_e( 'Existing connections keep working after changes. New sign-ins must use one of the redirect URIs listed here.', 'mcp-adapter' ); ?></p>
			<?php submit_button( __( 'Save changes', 'mcp-adapter' ) ); ?>
		</form>

		<h2><?php esc_html_e( 'Client secret', 'mcp-adapter' ); ?></h2>
		<p>
			<?php
			echo $client->is_confidential()
				? esc_html__( 'This is a confidential client. It authenticates with a secret as well as PKCE. Secrets are stored hashed, so a lost secret cannot be shown again; generate a new one instead.', 'mcp-adapter' )
				: esc_html__( 'This is a public client. It has no secret and relies on PKCE, which suits desktop and browser apps.', 'mcp-adapter' );
			?>
		</p>
		<div style="display:flex;gap:8px;">
			<?php $this->render_secret_button( $client, 'new', $client->is_confidential() ? __( 'Generate new secret', 'mcp-adapter' ) : __( 'Generate a secret', 'mcp-adapter' ) ); ?>
			<?php
			if ( $client->is_confidential() ) {
				$this->render_secret_button( $client, 'remove', __( 'Remove secret', 'mcp-adapter' ) );
			}
			?>
		</div>

		<h2><?php esc_html_e( 'Delete client', 'mcp-adapter' ); ?></h2>
		<p><?php esc_html_e( 'Deleting the client also removes all of its connections from users’ Application Passwords, and it can no longer ask users to connect.', 'mcp-adapter' ); ?></p>
		<?php
		$this->render_delete_button( $client, false );
	}

	/**
	 * Renders the name and redirect URI fields.
	 *
	 * @param Client|null $client Client being edited, or null when adding.
	 */
	private function render_detail_fields( ?Client $client ): void {
		?>
		<tr>
			<th scope="row"><label for="oauth-server-client-name"><?php esc_html_e( 'Name', 'mcp-adapter' ); ?></label></th>
			<td>
				<input type="text" id="oauth-server-client-name" name="client_name" class="regular-text" required value="<?php echo esc_attr( null === $client ? '' : $client->get_name() ); ?>" />
				<p class="description"><?php esc_html_e( 'Shown to users on the consent screen and in their Application Passwords.', 'mcp-adapter' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="oauth-server-redirect-uris"><?php esc_html_e( 'Redirect URIs', 'mcp-adapter' ); ?></label></th>
			<td>
				<textarea id="oauth-server-redirect-uris" name="redirect_uris" rows="3" class="large-text code" required><?php echo esc_textarea( null === $client ? '' : implode( "\n", $client->get_redirect_uris() ) ); ?></textarea>
				<p class="description"><?php esc_html_e( 'One per line, matched exactly, except that loopback addresses used by desktop apps match any port. The application’s documentation lists these, for example https://claude.ai/api/mcp/auth_callback.', 'mcp-adapter' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Renders a button that generates or removes a client secret.
	 *
	 * @param Client $client Client.
	 * @param string                   $mode   'new' or 'remove'.
	 * @param string                   $label  Button label.
	 */
	private function render_secret_button( Client $client, string $mode, string $label ): void {
		$confirm = 'remove' === $mode
			? __( 'Remove the secret? The application must then sign in as a public client.', 'mcp-adapter' )
			: __( 'Generate a new secret? Any existing secret stops working at once.', 'mcp-adapter' );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm( <?php echo esc_attr( wp_json_encode( $confirm ) ?: '""' ); ?> );">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::SECRET_ACTION ); ?>" />
			<input type="hidden" name="client_id" value="<?php echo esc_attr( $client->get_id() ); ?>" />
			<input type="hidden" name="mode" value="<?php echo esc_attr( $mode ); ?>" />
			<?php wp_nonce_field( self::SECRET_ACTION . '_' . $client->get_id() ); ?>
			<button type="submit" class="button"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	/**
	 * Renders a delete button.
	 *
	 * @param Client $client Client.
	 * @param bool                     $link   Whether to style it as a link.
	 */
	private function render_delete_button( Client $client, bool $link ): void {
		/* translators: %s: client name. */
		$confirm = sprintf( __( 'Delete %s? Its connections are removed from users’ Application Passwords.', 'mcp-adapter' ), $client->get_name() );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;" onsubmit="return confirm( <?php echo esc_attr( wp_json_encode( $confirm ) ?: '""' ); ?> );">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::DELETE_ACTION ); ?>" />
			<input type="hidden" name="client_id" value="<?php echo esc_attr( $client->get_id() ); ?>" />
			<?php wp_nonce_field( self::DELETE_ACTION . '_' . $client->get_id() ); ?>
			<button type="submit" class="<?php echo esc_attr( $link ? 'button-link button-link-delete' : 'button button-link-delete' ); ?>"><?php esc_html_e( 'Delete', 'mcp-adapter' ); ?></button>
		</form>
		<?php
	}

	/**
	 * Renders a one-time notice.
	 *
	 * @param array<string, string|null>    $notice Notice data.
	 * @param Client|null $client Client being shown, if any.
	 */
	private function render_notice( array $notice, ?Client $client ): void {
		if ( ! empty( $notice['error'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $notice['error'] ) . '</p></div>';
			return;
		}

		if ( empty( $notice['success'] ) ) {
			return;
		}
		?>
		<div class="notice notice-success">
			<p><?php echo esc_html( $notice['success'] ); ?></p>
			<?php if ( null !== $client && ! empty( $notice['secret'] ) ) : ?>
				<p><?php esc_html_e( 'Client ID:', 'mcp-adapter' ); ?> <code><?php echo esc_html( $client->get_id() ); ?></code></p>
				<p><?php esc_html_e( 'Client secret:', 'mcp-adapter' ); ?> <code><?php echo esc_html( (string) $notice['secret'] ); ?></code></p>
				<p><strong><?php esc_html_e( 'Copy the secret now. It will not be shown again.', 'mcp-adapter' ); ?></strong></p>
			<?php elseif ( null !== $client && isset( $notice['secret'] ) ) : ?>
				<p><?php esc_html_e( 'Client ID:', 'mcp-adapter' ); ?> <code><?php echo esc_html( $client->get_id() ); ?></code></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Stores a notice and redirects back to the page. Always ends the request.
	 *
	 * @param array<string, string|null> $notice    Notice data.
	 * @param string                     $client_id Client to show, if any.
	 * @return never
	 */
	private function finish( array $notice, string $client_id = '' ): void {
		if ( ! empty( $notice ) ) {
			set_transient( self::NOTICE_PREFIX . get_current_user_id(), $notice, 5 * MINUTE_IN_SECONDS );
		}

		$client = '' === $client_id ? null : $this->clients->get( $client_id );
		wp_safe_redirect( null === $client ? $this->page_url() : $this->edit_url( $client ) );
		exit;
	}

	/**
	 * Stores a notice and redirects to the add client page. Always ends the request.
	 *
	 * @param array<string, string|null> $notice Notice data.
	 * @return never
	 */
	private function finish_add( array $notice ): void {
		if ( ! empty( $notice ) ) {
			set_transient( self::NOTICE_PREFIX . get_current_user_id(), $notice, 5 * MINUTE_IN_SECONDS );
		}

		wp_safe_redirect( $this->add_url() );
		exit;
	}

	/**
	 * Reads and clears the current user's notice.
	 *
	 * @return array<string, string|null>
	 */
	private function take_notice(): array {
		$key    = self::NOTICE_PREFIX . get_current_user_id();
		$notice = get_transient( $key );
		delete_transient( $key );

		return is_array( $notice ) ? $notice : array();
	}

	/**
	 * Gets the list page URL.
	 */
	private function page_url(): string {
		return admin_url( 'options-general.php?page=' . self::SLUG );
	}

	/**
	 * Gets the add client page URL.
	 */
	private function add_url(): string {
		return admin_url( 'options-general.php?page=' . self::ADD_SLUG );
	}

	/**
	 * Gets a client's edit URL.
	 *
	 * @param Client $client Client.
	 */
	private function edit_url( Client $client ): string {
		return add_query_arg( 'client', rawurlencode( $client->get_id() ), $this->page_url() );
	}
}
