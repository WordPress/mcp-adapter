<?php
/**
 * Settings page that lets administrators select which registered WordPress
 * abilities are exposed to the default MCP server.
 *
 * @package WP\MCP\Admin
 */

declare( strict_types=1 );

namespace WP\MCP\Admin;

/**
 * Class - SettingsPage
 *
 * Renders Settings > MCP Adapter. Scope is intentionally narrow:
 *  - single column, native wp-admin chrome
 *  - one option, `mcp_adapter_public_abilities`
 *  - one filter, `wp_register_ability_args`
 *  - no React, no annotation-aware UI, no bulk actions, no source filter
 *
 * A more feature-complete reference UI lives at
 * https://github.com/respira-press/inhale-mcp-abilities for site owners who
 * want the additional affordances while this PR goes through review.
 */
final class SettingsPage {

	private const MENU_SLUG    = 'mcp-adapter';
	private const OPTION_GROUP = 'mcp_adapter_settings';
	private const CAPABILITY   = 'manage_options';

	/**
	 * Wire admin hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_setting' ) );
	}

	/**
	 * Add the page under Settings.
	 */
	public function register_menu(): void {
		add_options_page(
			__( 'MCP Adapter', 'mcp-adapter' ),
			__( 'MCP Adapter', 'mcp-adapter' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register the option backing the page.
	 */
	public function register_setting(): void {
		register_setting(
			self::OPTION_GROUP,
			AbilityExposureFilter::OPTION,
			array(
				'type'              => 'array',
				'description'       => __( 'Abilities exposed to the default MCP server.', 'mcp-adapter' ),
				'sanitize_callback' => array( $this, 'sanitize_option' ),
				'default'           => array(),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Whitelist-based sanitize callback. Only accepts entries that match an
	 * ability currently registered on this site and is not in the adapter's
	 * managed namespace.
	 *
	 * @param mixed $value Submitted value.
	 * @return array<int, string>
	 */
	public function sanitize_option( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$known = array_map(
			static function ( $name ): string {
				return (string) $name;
			},
			array_keys( $this->discover_abilities() )
		);

		$out = array();
		foreach ( $value as $entry ) {
			if ( ! is_string( $entry ) ) {
				continue;
			}
			$entry = sanitize_text_field( wp_unslash( $entry ) );
			if ( '' === $entry || 0 === strpos( $entry, 'mcp-adapter/' ) ) {
				continue;
			}
			if ( ! in_array( $entry, $known, true ) ) {
				continue;
			}
			if ( in_array( $entry, $out, true ) ) {
				continue;
			}
			$out[] = $entry;
		}
		return $out;
	}

	/**
	 * Discover registered abilities on this site, keyed by ability name.
	 *
	 * @return array<string, array{label: string, description: string, managed: bool}>
	 */
	public function discover_abilities(): array {
		$abilities = array();
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return $abilities;
		}

		$registered = wp_get_abilities();

		foreach ( $registered as $ability ) {
			$name        = '';
			$label       = '';
			$description = '';

			if ( is_object( $ability ) ) {
				$name        = method_exists( $ability, 'get_name' ) ? (string) $ability->get_name() : '';
				$label       = method_exists( $ability, 'get_label' ) ? (string) $ability->get_label() : '';
				$description = method_exists( $ability, 'get_description' ) ? (string) $ability->get_description() : '';
			} elseif ( is_array( $ability ) ) {
				$name        = isset( $ability['name'] ) ? (string) $ability['name'] : '';
				$label       = isset( $ability['label'] ) ? (string) $ability['label'] : '';
				$description = isset( $ability['description'] ) ? (string) $ability['description'] : '';
			}

			if ( '' === $name ) {
				continue;
			}

			$abilities[ $name ] = array(
				'label'       => $label,
				'description' => $description,
				'managed'     => 0 === strpos( $name, 'mcp-adapter/' ),
			);
		}
		ksort( $abilities );
		return $abilities;
	}

	/**
	 * Render the settings page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You do not have permission to access this page.', 'mcp-adapter' ),
				esc_html__( 'Permission denied', 'mcp-adapter' ),
				array(
					'response'  => 403,
					'back_link' => true,
				)
			);
		}

		$abilities = $this->discover_abilities();
		$exposed   = get_option( AbilityExposureFilter::OPTION, array() );
		if ( ! is_array( $exposed ) ) {
			$exposed = array();
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'MCP Adapter', 'mcp-adapter' ); ?></h1>
			<p>
				<?php
				esc_html_e(
					'Choose which registered WordPress abilities are exposed to the default MCP server. Abilities are hidden by default; checking one sets meta.mcp.public to true on it at registration time.',
					'mcp-adapter'
				);
				?>
			</p>
			<?php settings_errors(); ?>
			<form method="post" action="options.php">
				<?php settings_fields( self::OPTION_GROUP ); ?>
				<?php if ( empty( $abilities ) ) : ?>
					<p>
						<em>
							<?php
							esc_html_e(
								'No abilities are currently registered on this site. Activate the plugins or themes that register abilities to see them here.',
								'mcp-adapter'
							);
							?>
						</em>
					</p>
				<?php else : ?>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th scope="col" style="width:40px;"><span class="screen-reader-text"><?php esc_html_e( 'Expose', 'mcp-adapter' ); ?></span></th>
								<th scope="col"><?php esc_html_e( 'Ability', 'mcp-adapter' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Description', 'mcp-adapter' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $abilities as $name => $info ) : ?>
								<?php $row_class = $info['managed'] ? 'disabled' : ''; ?>
								<tr class="<?php echo esc_attr( $row_class ); ?>">
									<td>
										<?php if ( $info['managed'] ) : ?>
											<input type="checkbox" disabled aria-label="<?php echo esc_attr( sprintf( /* translators: %s: ability name. */ __( 'Managed by adapter: %s', 'mcp-adapter' ), $name ) ); ?>" />
										<?php else : ?>
											<input
												type="checkbox"
												name="<?php echo esc_attr( AbilityExposureFilter::OPTION ); ?>[]"
												value="<?php echo esc_attr( $name ); ?>"
												<?php checked( in_array( $name, $exposed, true ) ); ?>
												aria-label="<?php echo esc_attr( sprintf( /* translators: %s: ability name. */ __( 'Expose %s to the default MCP server', 'mcp-adapter' ), $name ) ); ?>"
											/>
										<?php endif; ?>
									</td>
									<td>
										<code><?php echo esc_html( $name ); ?></code>
									</td>
									<td>
										<?php
										if ( $info['managed'] ) {
											echo '<em>' . esc_html__( '(managed by the adapter)', 'mcp-adapter' ) . '</em>';
										} else {
											echo esc_html( $info['description'] );
										}
										?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
				<?php submit_button( __( 'Save changes', 'mcp-adapter' ) ); ?>
			</form>
		</div>
		<?php
	}
}
