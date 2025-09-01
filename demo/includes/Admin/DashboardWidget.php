<?php
/**
 * Dashboard Widget for MCP Demo Plugin.
 *
 * @package MCP\Demo
 */

declare(strict_types=1);

namespace WP\MCP\Demo\Admin;

/**
 * Dashboard Widget class for displaying MCP site overview.
 */
class DashboardWidget {

	/**
	 * Initialize the dashboard widget.
	 */
	public function init(): void {
		add_action( 'wp_dashboard_setup', array( $this, 'register_widget' ) );
		add_action( 'wp_ajax_mcp_dashboard_refresh', array( $this, 'handle_ajax_refresh' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		
		// Cache invalidation hooks
		add_action( 'save_post', array( $this, 'clear_cache' ) );
		add_action( 'comment_post', array( $this, 'clear_cache' ) );
		add_action( 'wp_set_comment_status', array( $this, 'clear_cache' ) );
	}

	/**
	 * Register the dashboard widget.
	 */
	public function register_widget(): void {
		if ( current_user_can( 'read' ) ) {
			wp_add_dashboard_widget(
				'mcp_demo_overview',
				'MCP',
				array( $this, 'render_widget_content' )
			);
		}
	}

	/**
	 * Enqueue widget assets.
	 */
	public function enqueue_assets( $hook_suffix ): void {
		if ( 'index.php' !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'mcp-dashboard-widget',
			plugins_url( 'assets/dashboard-widget.css', __FILE__ ),
			array(),
			'1.0.0'
		);

		wp_enqueue_script(
			'mcp-dashboard-widget',
			plugins_url( 'assets/dashboard-widget.js', __FILE__ ),
			array( 'jquery' ),
			'1.0.0',
			true
		);

		wp_localize_script( 'mcp-dashboard-widget', 'mcpDashboard', array(
			'nonce' => wp_create_nonce( 'mcp_dashboard_nonce' ),
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
		) );
	}

	/**
	 * Render the widget content.
	 */
	public function render_widget_content(): void {
		$servers = $this->get_mcp_servers();
		$clients = $this->get_mcp_clients();
		?>
		<div id="mcp-widget">
			<div id="mcp-servers" class="activity-block">
				<h3>Servers</h3>
				<?php if ( ! empty( $servers ) ) : ?>
					<ul>
						<?php foreach ( $servers as $server_id => $server ) : ?>
							<li>
								<strong><?php echo esc_html( $server['name'] ); ?></strong>
								<span class="mcp-server-id">(<?php echo esc_html( $server_id ); ?>)</span>
								<br>
								<span class="mcp-stats">
									Tools: <?php echo esc_html( $server['tools_count'] ); ?>, 
									Resources: <?php echo esc_html( $server['resources_count'] ); ?>, 
									Prompts: <?php echo esc_html( $server['prompts_count'] ); ?>
								</span>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php else : ?>
					<p>No MCP servers are currently configured.</p>
				<?php endif; ?>
			</div>

			<div id="mcp-clients" class="activity-block">
				<h3>Clients</h3>
				<?php if ( ! empty( $clients ) ) : ?>
					<ul>
						<?php foreach ( $clients as $client_id => $client ) : ?>
							<li>
								<strong><?php echo esc_html( $client_id ); ?></strong>
								<?php if ( $client['connected'] ) : ?>
									<span class="mcp-connected">[Connected]</span>
								<?php else : ?>
									<span class="mcp-disconnected">[Disconnected]</span>
								<?php endif; ?>
								<br>
								<code><?php echo esc_html( $client['server_url'] ); ?></code>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php else : ?>
					<p>No MCP clients are currently connected.</p>
				<?php endif; ?>
			</div>

			<p class="mcp-actions">
				<a href="<?php echo esc_url( admin_url( 'options-general.php?page=mcp-settings' ) ); ?>" class="button">Manage MCP</a>
				<button type="button" class="button mcp-refresh" onclick="mcpDashboardRefresh()">Refresh</button>
			</p>
		</div>
		<?php
	}

	/**
	 * Get MCP servers information.
	 *
	 * @return array MCP servers data.
	 */
	private function get_mcp_servers(): array {
		$cache_key = 'mcp_dashboard_servers_' . get_current_user_id();
		$cached_data = get_transient( $cache_key );

		if ( false !== $cached_data ) {
			return $cached_data;
		}

		$servers = array();

		// Check if MCP Adapter is available
		if ( class_exists( '\WP\MCP\Core\McpAdapter' ) ) {
			$adapter = \WP\MCP\Core\McpAdapter::instance();
			if ( $adapter ) {
				// Ensure MCP adapter has been initialized
				if ( ! $adapter->get_servers() ) {
					$adapter->mcp_adapter_init();
				}

				$mcp_servers = $adapter->get_servers();
				if ( is_array( $mcp_servers ) ) {
					foreach ( $mcp_servers as $server_id => $server ) {
						if ( $server ) {
							$servers[ $server_id ] = array(
								'name' => $server->get_server_name(),
								'description' => $server->get_server_description(),
								'tools_count' => count( $server->get_tools() ),
								'resources_count' => count( $server->get_resources() ),
								'prompts_count' => count( $server->get_prompts() ),
							);
						}
					}
				}
			}
		}

		// Cache for 2 minutes
		set_transient( $cache_key, $servers, 2 * MINUTE_IN_SECONDS );

		return $servers;
	}

	/**
	 * Get MCP clients information.
	 *
	 * @return array MCP clients data.
	 */
	private function get_mcp_clients(): array {
		$cache_key = 'mcp_dashboard_clients_' . get_current_user_id();
		$cached_data = get_transient( $cache_key );

		if ( false !== $cached_data ) {
			return $cached_data;
		}

		$clients = array();

		// Check if MCP Adapter is available
		if ( class_exists( '\WP\MCP\Core\McpAdapter' ) ) {
			$adapter = \WP\MCP\Core\McpAdapter::instance();
			if ( $adapter ) {
				// Ensure MCP adapter has been initialized
				$adapter->mcp_adapter_init();

				$mcp_clients = $adapter->get_clients();
				if ( is_array( $mcp_clients ) ) {
					foreach ( $mcp_clients as $client_id => $client ) {
						if ( $client ) {
							$clients[ $client_id ] = array(
								'server_url' => $client->get_server_url(),
								'connected' => $client->is_connected(),
							);
						}
					}
				}
			}
		}

		// Cache for 1 minute
		set_transient( $cache_key, $clients, MINUTE_IN_SECONDS );

		return $clients;
	}

	/**
	 * Handle AJAX refresh request.
	 */
	public function handle_ajax_refresh(): void {
		check_ajax_referer( 'mcp_dashboard_nonce', 'nonce' );

		if ( ! current_user_can( 'read' ) ) {
			wp_die( 'Unauthorized' );
		}

		// Clear cache to force fresh data
		$this->clear_cache();

		// Get fresh data
		$servers = $this->get_mcp_servers();
		$clients = $this->get_mcp_clients();

		wp_send_json_success( array(
			'servers' => $servers,
			'clients' => $clients,
		) );
	}

	/**
	 * Clear widget cache.
	 */
	public function clear_cache(): void {
		$user_id = get_current_user_id();
		delete_transient( 'mcp_dashboard_servers_' . $user_id );
		delete_transient( 'mcp_dashboard_clients_' . $user_id );
	}
}