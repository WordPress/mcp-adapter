<?php
/**
 * Client registration screen.
 *
 * @package WP\OAuth
 */

declare( strict_types=1 );

namespace WP\OAuth\Admin;

use WP\OAuth\Clients\ClientRepository;
use WP\OAuth\Server;

defined( 'ABSPATH' ) || exit;

/**
 * Settings > OAuth Clients, where administrators register clients.
 */
final class ClientsPage {

	private const SLUG          = 'oauth-server-clients';
	private const ADD_ACTION    = 'oauth_server_add_client';
	private const DELETE_ACTION = 'oauth_server_delete_client';
	private const NOTICE_PREFIX = 'oauth_server_notice_';

	/**
	 * Client registry.
	 *
	 * @var \WP\OAuth\Clients\ClientRepository
	 */
	private $clients;

	/**
	 * Constructor.
	 *
	 * @param \WP\OAuth\Clients\ClientRepository $clients Client registry.
	 */
	public function __construct( ClientRepository $clients ) {
		$this->clients = $clients;
	}

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_post_' . self::ADD_ACTION, array( $this, 'handle_add' ) );
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
		$redirect_uris = isset( $_POST['redirect_uris'] ) ? preg_split( '/\R/', (string) wp_unslash( $_POST['redirect_uris'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each URI is validated by the repository.
		$confidential  = ! empty( $_POST['confidential'] );

		$result = $this->clients->create( $name, is_array( $redirect_uris ) ? $redirect_uris : array(), $confidential );
		if ( is_wp_error( $result ) ) {
			$this->set_notice( array( 'error' => $result->get_error_message() ) );
		} else {
			[ $client, $secret ] = $result;
			$this->set_notice(
				array(
					'client_id' => $client->get_id(),
					'secret'    => $secret,
					'name'      => $client->get_name(),
				)
			);
		}

		wp_safe_redirect( $this->page_url() );
		exit;
	}

	/**
	 * Handles the delete client form.
	 */
	public function handle_delete(): void {
		$client_id = isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : '';

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage OAuth clients.', 'mcp-adapter' ), 403 );
		}
		check_admin_referer( self::DELETE_ACTION . '_' . $client_id );

		$this->clients->delete( $client_id );

		wp_safe_redirect( $this->page_url() );
		exit;
	}

	/**
	 * Renders the page.
	 */
	public function render(): void {
		$notice  = $this->take_notice();
		$clients = $this->clients->all();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'OAuth Clients', 'mcp-adapter' ); ?></h1>
			<p><?php esc_html_e( 'Register applications that can ask users to connect to this site. Each connection appears in the user’s Application Passwords and can be revoked there.', 'mcp-adapter' ); ?></p>

			<?php if ( ! Server::is_available() ) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e( 'Application Passwords are not available on this site, so OAuth sign-in is turned off. They need HTTPS, or a local environment type.', 'mcp-adapter' ); ?></p></div>
			<?php endif; ?>

			<?php if ( isset( $notice['error'] ) ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $notice['error'] ); ?></p></div>
			<?php elseif ( isset( $notice['client_id'] ) ) : ?>
				<div class="notice notice-success">
					<p>
						<?php
						/* translators: %s: client name. */
						echo esc_html( sprintf( __( '%s was registered. Enter these details in the application.', 'mcp-adapter' ), $notice['name'] ) );
						?>
					</p>
					<p><?php esc_html_e( 'Client ID:', 'mcp-adapter' ); ?> <code><?php echo esc_html( $notice['client_id'] ); ?></code></p>
					<?php if ( ! empty( $notice['secret'] ) ) : ?>
						<p><?php esc_html_e( 'Client secret:', 'mcp-adapter' ); ?> <code><?php echo esc_html( $notice['secret'] ); ?></code></p>
						<p><strong><?php esc_html_e( 'Copy the secret now. It will not be shown again.', 'mcp-adapter' ); ?></strong></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<table class="widefat striped" style="margin:1em 0 2em;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Name', 'mcp-adapter' ); ?></th>
						<th><?php esc_html_e( 'Client ID', 'mcp-adapter' ); ?></th>
						<th><?php esc_html_e( 'Redirect URIs', 'mcp-adapter' ); ?></th>
						<th><?php esc_html_e( 'Type', 'mcp-adapter' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $clients ) ) : ?>
						<tr><td colspan="5"><?php esc_html_e( 'No clients registered yet.', 'mcp-adapter' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $clients as $client ) : ?>
						<tr>
							<td><?php echo esc_html( $client->get_name() ); ?></td>
							<td><code><?php echo esc_html( $client->get_id() ); ?></code></td>
							<td><?php echo wp_kses( implode( '<br />', array_map( 'esc_html', $client->get_redirect_uris() ) ), array( 'br' => array() ) ); ?></td>
							<td><?php echo $client->is_confidential() ? esc_html__( 'Confidential', 'mcp-adapter' ) : esc_html__( 'Public', 'mcp-adapter' ); ?></td>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<input type="hidden" name="action" value="<?php echo esc_attr( self::DELETE_ACTION ); ?>" />
									<input type="hidden" name="client_id" value="<?php echo esc_attr( $client->get_id() ); ?>" />
									<?php wp_nonce_field( self::DELETE_ACTION . '_' . $client->get_id() ); ?>
									<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Delete', 'mcp-adapter' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Add client', 'mcp-adapter' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ADD_ACTION ); ?>" />
				<?php wp_nonce_field( self::ADD_ACTION ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="oauth-server-client-name"><?php esc_html_e( 'Name', 'mcp-adapter' ); ?></label></th>
						<td><input type="text" id="oauth-server-client-name" name="client_name" class="regular-text" required /></td>
					</tr>
					<tr>
						<th scope="row"><label for="oauth-server-redirect-uris"><?php esc_html_e( 'Redirect URIs', 'mcp-adapter' ); ?></label></th>
						<td>
							<textarea id="oauth-server-redirect-uris" name="redirect_uris" rows="3" class="large-text code" required></textarea>
							<p class="description"><?php esc_html_e( 'One per line. The application’s documentation lists these, for example https://claude.ai/api/mcp/auth_callback.', 'mcp-adapter' ); ?></p>
						</td>
					</tr>
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

			<h2><?php esc_html_e( 'Server details', 'mcp-adapter' ); ?></h2>
			<p><?php esc_html_e( 'Applications normally discover these automatically.', 'mcp-adapter' ); ?></p>
			<ul>
				<li><?php esc_html_e( 'Authorization endpoint:', 'mcp-adapter' ); ?> <code><?php echo esc_html( Server::authorization_endpoint() ); ?></code></li>
				<li><?php esc_html_e( 'Token endpoint:', 'mcp-adapter' ); ?> <code><?php echo esc_html( Server::token_endpoint() ); ?></code></li>
			</ul>
		</div>
		<?php
	}

	/**
	 * Gets the page URL.
	 */
	private function page_url(): string {
		return admin_url( 'options-general.php?page=' . self::SLUG );
	}

	/**
	 * Stores a one-time notice for the current user.
	 *
	 * @param array<string, string|null> $notice Notice data.
	 */
	private function set_notice( array $notice ): void {
		set_transient( self::NOTICE_PREFIX . get_current_user_id(), $notice, 5 * MINUTE_IN_SECONDS );
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
}
