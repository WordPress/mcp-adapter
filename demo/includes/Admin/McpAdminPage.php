<?php
/**
 * Admin page for managing MCP servers and clients.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Demo\Admin;

use WP\MCP\Core\McpAdapter;

/**
 * Admin page for MCP servers and clients.
 */
class McpAdminPage {

	/**
	 * Initialize the settings page.
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'add_admin_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/**
	 * Enqueue admin assets.
	 */
	public function enqueue_admin_assets( $hook_suffix ): void {
		if ( 'settings_page_mcp-settings' !== $hook_suffix ) {
			return;
		}

		wp_enqueue_script(
			'mcp-admin',
			plugins_url( 'assets/mcp-admin.js', __FILE__ ),
			array( 'jquery' ),
			'1.0.0',
			true
		);

		wp_localize_script( 'mcp-admin', 'mcpAdmin', array(
			'nonce' => wp_create_nonce( 'mcp_nonce' ),
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
		) );

		wp_enqueue_style(
			'mcp-admin',
			plugins_url( 'assets/mcp-admin.css', __FILE__ ),
			array(),
			'1.0.0'
		);
	}

	/**
	 * Add admin page to WordPress menu.
	 */
	public function add_admin_page(): void {
		add_options_page(
			'MCP Servers & Clients',
			'MCP',
			'manage_options',
			'mcp-settings',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register settings.
	 */
	public function register_settings(): void {
		register_setting( 'mcp_settings', 'mcp_servers', array(
			'type' => 'array',
			'default' => array(),
		) );
	}

	/**
	 * Render the settings page.
	 */
	public function render_page(): void {
		$servers = get_option( 'mcp_servers', array() );
		?>
		<div class="wrap">
			<h1>MCP Integration</h1>
			<p>Manage bidirectional MCP integration: connect to external MCP servers and expose WordPress capabilities as MCP servers.</p>
			
			<?php $mcp_servers = $this->get_registered_mcp_servers(); ?>
			<p style="color: #666; margin-bottom: 20px;">
				Currently running <strong><?php echo count( $mcp_servers ); ?></strong> MCP servers with <strong><?php echo count( $this->get_available_abilities() ); ?></strong> available abilities and <strong><?php echo count( $this->get_registered_mcp_clients() ); ?></strong> active client connections.
			</p>
			
			<div class="nav-tab-wrapper">
				<a href="#exposed" class="nav-tab nav-tab-active" onclick="showTab('exposed')">MCP Servers</a>
				<a href="#connected" class="nav-tab" onclick="showTab('connected')">MCP Clients</a>
			</div>

			<!-- MCP Clients Tab -->
			<div id="connected-tab" class="tab-content">
				<h2>MCP Client Connections</h2>
				<p>External MCP servers that WordPress connects to as a client. Remote tools, resources, and prompts become available as WordPress abilities.</p>
				
				<?php $mcp_clients = $this->get_registered_mcp_clients(); ?>
				<?php if ( empty( $mcp_clients ) ) : ?>
					<div class="mcp-section">
						<div class="mcp-section-content">
							<p><em>No MCP client connections are currently active. Client connections will appear here once they are configured via code.</em></p>
						</div>
					</div>
				<?php else : ?>
					<?php foreach ( $mcp_clients as $client_id => $client_info ) : ?>
						<div class="mcp-section">
							<div class="mcp-section-header">
								<?php echo esc_html( $client_id ); ?>
								<span style="font-size: 12px; margin-left: 10px;">
									<?php if ( $client_info['connected'] ) : ?>
										<span class="status-indicator status-connected">Connected</span>
									<?php else : ?>
										<span class="status-indicator status-disconnected">Disconnected</span>
									<?php endif; ?>
								</span>
							</div>
							<div class="mcp-section-content">
								<div class="endpoint-details">
									<p><strong>Server URL:</strong> <code><?php echo esc_html( $client_info['server_url'] ); ?></code></p>
									<p><strong>Status:</strong> 
										<?php if ( $client_info['connected'] ) : ?>
											✅ Connected
										<?php else : ?>
											❌ Disconnected
										<?php endif; ?>
									</p>
								</div>
								
								<?php if ( $client_info['connected'] ) : ?>
									<div style="margin-top: 15px;">
										<h5>Remote MCP Capabilities Available as WordPress Abilities:</h5>
										<?php 
										$registered_abilities = $this->get_client_registered_abilities( $client_id );
										if ( ! empty( $registered_abilities ) ) : ?>
											<ul style="margin: 10px 0;">
												<?php foreach ( $registered_abilities as $ability ) : ?>
													<li>
														<strong><?php echo esc_html( $ability['name'] ); ?></strong>
														<?php if ( ! empty( $ability['label'] ) ) : ?>
															<em>(<?php echo esc_html( $ability['label'] ); ?>)</em>
														<?php endif; ?>
														<?php if ( ! empty( $ability['description'] ) ) : ?>
															<br><span style="font-size: 12px; color: #666;"><?php echo esc_html( wp_trim_words( $ability['description'], 15 ) ); ?></span>
														<?php endif; ?>
													</li>
												<?php endforeach; ?>
											</ul>
										<?php else : ?>
											<p><em>No abilities registered yet. MCP tools, resources, and prompts from this server are automatically registered with the <code>mcp-<?php echo esc_html( $client_id ); ?>/</code> prefix.</em></p>
										<?php endif; ?>
									</div>
								<?php endif; ?>
							</div>
						</div>
					<?php endforeach; ?>
				<?php endif; ?>
				
				<div class="mcp-section">
					<div class="mcp-section-header">MCP Client Configuration</div>
					<div class="mcp-section-content">
						<p>MCP client connections to external servers are configured programmatically using the <code>mcp_client_init</code> action hook.</p>
						
						<h4>How to Configure MCP Clients:</h4>
						<ol>
							<li><strong>Edit your theme's functions.php</strong> or create a custom plugin</li>
							<li>Use the <code>mcp_client_init</code> action hook</li>
							<li>Create clients using <code>$adapter->create_client()</code></li>
							<li>Remote MCP capabilities become WordPress abilities automatically</li>
						</ol>
						
						<h4>Example:</h4>
						<pre><code>add_action( 'mcp_client_init', function( $adapter ) {
    $client = $adapter->create_client(
        'wpcom-domains',       // Client ID
        'https://wpcom-domains-mcp.a8cai.workers.dev/mcp',  // External MCP server URL
        array(
            'timeout' => 30,   // Configuration options
        )
    );
} );</code></pre>
						
						<p>See <code>examples/client-usage.php</code> for complete working examples.</p>
					</div>
				</div>
			</div>

			<!-- MCP Servers Tab -->
			<div id="exposed-tab" class="tab-content">
				<h2>WordPress MCP Servers</h2>
				<p>MCP servers that your WordPress site exposes for external MCP clients to connect to:</p>
				<?php if ( empty( $mcp_servers ) ) : ?>
					<div class="mcp-section">
						<div class="mcp-section-content">
							<p><em>No MCP servers are currently exposed. MCP servers will appear here once they are configured via code.</em></p>
						</div>
					</div>
				<?php else : ?>
					<?php foreach ( $mcp_servers as $server_id => $server_info ) : ?>
						<div class="mcp-section">
							<div class="mcp-section-header">
								<?php echo esc_html( $server_info['name'] ); ?>
								<span style="font-size: 12px; color: #666; font-weight: normal; margin-left: 10px;">
									(<?php echo esc_html( $server_id ); ?>)
								</span>
							</div>
							<div class="mcp-section-content">
								<p><?php echo esc_html( $server_info['description'] ); ?></p>
								
								<div class="mcp-server-info">
									<div class="server-endpoint-info">
										<h4>Server Endpoint</h4>
										<div class="endpoint-details">
											<p><strong>Server URL:</strong> <code><?php echo esc_html( $server_info['endpoint'] ); ?></code></p>
											<p><strong>Protocol:</strong> HTTP JSON-RPC</p>
											<p><strong>Authentication:</strong> WordPress REST API (optional)</p>
											<p><strong>Version:</strong> <?php echo esc_html( $server_info['version'] ); ?></p>
										</div>
										
										<div class="mcp-stats" style="margin: 15px 0;">
											<div class="mcp-stat">
												<div class="mcp-stat-number"><?php echo esc_html( $server_info['tools_count'] ); ?></div>
												<div class="mcp-stat-label">Tools</div>
											</div>
											<div class="mcp-stat">
												<div class="mcp-stat-number"><?php echo esc_html( $server_info['resources_count'] ); ?></div>
												<div class="mcp-stat-label">Resources</div>
											</div>
											<div class="mcp-stat">
												<div class="mcp-stat-number"><?php echo esc_html( $server_info['prompts_count'] ); ?></div>
												<div class="mcp-stat-label">Prompts</div>
											</div>
										</div>
										
										<div class="connection-instructions">
											<h5>Connect External MCP Clients:</h5>
											<pre><code>{
  "mcpServers": {
    "<?php echo esc_js( $server_id ); ?>": {
      "serverUrl": "<?php echo esc_js( $server_info['endpoint'] ); ?>"
    }
  }
}</code></pre>
										</div>
									</div>
								</div>
							</div>
						</div>
					<?php endforeach; ?>
				<?php endif; ?>
				
				<div class="mcp-section">
					<div class="mcp-section-header">MCP Server Configuration</div>
					<div class="mcp-section-content">
						<p>MCP servers and their exposed capabilities are configured programmatically using the <code>mcp_adapter_init</code> action hook.</p>
						
						<h4>How to Configure MCP Servers:</h4>
						<ol>
							<li><strong>Edit your theme's functions.php</strong> or create a custom plugin</li>
							<li>Use the <code>mcp_adapter_init</code> action hook</li>
							<li>Register abilities using <code>wp_register_ability()</code></li>
							<li>Create MCP servers using <code>$adapter->create_server()</code></li>
						</ol>
						
						<h4>Example:</h4>
						<pre><code>add_action( 'mcp_adapter_init', function( $adapter ) {
    $adapter->create_server(
        'my-server',           // Server ID
        'mcp',                 // Namespace  
        'my-endpoint',         // Route
        'My Server',           // Name
        'Description',         // Description
        '1.0.0',              // Version
        [ RestTransport::class ],
        null, null,
        [ 'my_tool_ability' ],      // Tools to expose
        [ 'my_resource_ability' ],  // Resources to expose
        [ 'my_prompt_ability' ]     // Prompts to expose
    );
} );</code></pre>
						
						<p>See <code>examples/server-usage.php</code> for complete working examples.</p>
					</div>
				</div>
			</div>

		</div>

		<script>
		jQuery(document).ready(function($) {
			// Define all admin functions inline since external JS may not load in Playground
			
			// Tab functionality
			window.showTab = function(tabName) {
				console.log('Switching to tab:', tabName);
				// Hide all tabs
				$('.tab-content').hide().removeClass('active');
				$('.nav-tab').removeClass('nav-tab-active');
				
				// Show selected tab
				$('#' + tabName + '-tab').show().addClass('active');
				$('a[href="#' + tabName + '"]').addClass('nav-tab-active');
			};
			
			// Initialize based on URL hash
			var hash = window.location.hash.substring(1);
			if (hash === 'connected') {
				showTab('connected');
			} else {
				showTab('exposed');
			}
			
			// Server form functions
			window.showAddServerForm = function() {
				$('#server-form').show();
				$('#form-title').text('Add New Server');
				$('#mcp-server-form')[0].reset();
				$('#server-id').val('');
			};

			window.hideServerForm = function() {
				$('#server-form').hide();
			};

			window.toggleAuthFields = function() {
				var authType = $('#auth-type').val();
				$('#bearer-token-row, #api-key-row, #basic-auth-row').hide();
				
				if (authType === 'bearer') {
					$('#bearer-token-row').show();
				} else if (authType === 'api_key') {
					$('#api-key-row').show();
				} else if (authType === 'basic') {
					$('#basic-auth-row').show();
				}
			};
			
			// Status checking function
			window.checkServerStatus = function(serverId) {
				console.log('Checking server status for:', serverId);
				var $statusIndicator = $('#status-' + serverId);
				$statusIndicator.text('Checking...');
				
				// This would normally make an AJAX call
				setTimeout(function() {
					$statusIndicator.removeClass('status-unknown').addClass('status-disconnected').text('Failed');
					alert('Status check functionality requires the external JavaScript file to load properly.');
				}, 1000);
			};
			
		});
		</script>
		<?php
	}

	/**
	 * Handle AJAX save server request.
	 */
	public function handle_ajax_save_server(): void {
		check_ajax_referer( 'mcp_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}

		$server_id = sanitize_text_field( $_POST['server_id'] ?? '' );
		$server_name = sanitize_text_field( $_POST['server_name'] ?? '' );
		$server_url = sanitize_url( $_POST['server_url'] ?? '' );
		$auth_type = sanitize_text_field( $_POST['auth_type'] ?? 'none' );
		$timeout = absint( $_POST['timeout'] ?? 30 );
		$ssl_verify = ! empty( $_POST['ssl_verify'] );

		if ( empty( $server_name ) || empty( $server_url ) ) {
			wp_send_json_error( 'Server name and URL are required.' );
		}

		// Generate server ID if new
		if ( empty( $server_id ) ) {
			$server_id = sanitize_title( $server_name );
		}

		// Build auth config
		$auth = array( 'type' => $auth_type );
		switch ( $auth_type ) {
			case 'bearer':
				$auth['token'] = sanitize_text_field( $_POST['bearer_token'] ?? '' );
				break;
			case 'api_key':
				$auth['key'] = sanitize_text_field( $_POST['api_key'] ?? '' );
				$auth['header'] = sanitize_text_field( $_POST['api_header'] ?? 'X-API-Key' );
				break;
			case 'basic':
				$auth['username'] = sanitize_text_field( $_POST['username'] ?? '' );
				$auth['password'] = sanitize_text_field( $_POST['password'] ?? '' );
				break;
		}

		// Save server config
		$servers = get_option( 'mcp_servers', array() );
		$servers[ $server_id ] = array(
			'name' => $server_name,
			'url' => $server_url,
			'auth' => $auth,
			'timeout' => $timeout,
			'ssl_verify' => (bool) $ssl_verify, // Ensure proper boolean
		);

		update_option( 'mcp_servers', $servers );

		wp_send_json_success( 'Server saved successfully.' );
	}

	/**
	 * Handle AJAX delete server request.
	 */
	public function handle_ajax_delete_server(): void {
		check_ajax_referer( 'mcp_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}

		$server_id = sanitize_text_field( $_POST['server_id'] ?? '' );

		if ( empty( $server_id ) ) {
			wp_send_json_error( 'Server ID is required.' );
		}

		$servers = get_option( 'mcp_servers', array() );
		if ( ! isset( $servers[ $server_id ] ) ) {
			wp_send_json_error( 'Server not found.' );
		}

		unset( $servers[ $server_id ] );
		update_option( 'mcp_servers', $servers );

		wp_send_json_success( 'Server deleted successfully.' );
	}

	/**
	 * Handle AJAX get server request.
	 */
	public function handle_ajax_get_server(): void {
		check_ajax_referer( 'mcp_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}

		$server_id = sanitize_text_field( $_POST['server_id'] ?? '' );

		if ( empty( $server_id ) ) {
			wp_send_json_error( 'Server ID is required.' );
		}

		$servers = get_option( 'mcp_servers', array() );
		if ( ! isset( $servers[ $server_id ] ) ) {
			wp_send_json_error( 'Server not found.' );
		}

		wp_send_json_success( $servers[ $server_id ] );
	}

	/**
	 * Handle AJAX test request.
	 */
	public function handle_ajax_test(): void {
		check_ajax_referer( 'mcp_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}

		$server_url = sanitize_url( $_POST['server_url'] ?? '' );
		$client_id = sanitize_text_field( $_POST['client_id'] ?? '' );

		if ( empty( $server_url ) || empty( $client_id ) ) {
			wp_send_json_error( 'Server URL and Client ID are required. Received: server_url="' . $server_url . '", client_id="' . $client_id . '"' );
		}

		try {
			// Create a test client during the proper action hook
			$test_client = null;
			$client_error = null;
			
			// Create a temporary hook to create our test client
			$test_hook = function( $adapter ) use ( $client_id, $server_url, &$test_client, &$client_error ) {
				$client = $adapter->create_client(
					$client_id,
					$server_url,
					array(
						'timeout' => 10,
						'ssl_verify' => true, // Use proper SSL for HTTPS
					)
				);

				if ( is_wp_error( $client ) ) {
					$client_error = $client->get_error_message();
				} else {
					$test_client = $client;
				}
			};

			// Add the temporary hook
			add_action( 'mcp_client_init', $test_hook );

			// Trigger the mcp_client_init action to create our test client
			$adapter = McpAdapter::instance();
			if ( ! $adapter ) {
				wp_send_json_error( 'MCP Adapter is not available. Please check if the adapter is properly initialized.' );
			}

			// Manually trigger the client init action for testing
			do_action( 'mcp_client_init', $adapter );

			// Remove the temporary hook
			remove_action( 'mcp_client_init', $test_hook );

			// Check if client creation failed
			if ( $client_error ) {
				wp_send_json_error( 'Failed to create client: ' . $client_error );
			}

			if ( ! $test_client ) {
				wp_send_json_error( 'Failed to create test client.' );
			}

			$client = $test_client;

			$output = '<h3>Connection Test Results</h3>';
			
			// Test connection
			$output .= '<p><strong>Connected:</strong> ' . ( $client->is_connected() ? '✅ Yes' : '❌ No' ) . '</p>';
			$output .= '<p><strong>Server URL:</strong> ' . esc_html( $client->get_server_url() ) . '</p>';
			$output .= '<p><strong>Client ID:</strong> ' . esc_html( $client->get_client_id() ) . '</p>';

			if ( ! $client->is_connected() ) {
				wp_send_json_error( $output . '<p>❌ Failed to connect to server. Make sure the MCP server is running.</p>' );
			}

			// List tools
			$tools = $client->list_tools();
			$output .= '<h4>Available Tools</h4>';
			if ( is_wp_error( $tools ) ) {
				$output .= '<p>❌ Error listing tools: ' . esc_html( $tools->get_error_message() ) . '</p>';
			} else {
				$tools_list = $tools['tools'] ?? array();
				if ( empty( $tools_list ) ) {
					$output .= '<p>No tools available</p>';
				} else {
					$output .= '<ul>';
					foreach ( $tools_list as $tool ) {
						$output .= '<li><strong>' . esc_html( $tool['name'] ) . '</strong>';
						if ( isset( $tool['description'] ) ) {
							$output .= ': ' . esc_html( $tool['description'] );
						}
						$output .= '</li>';
					}
					$output .= '</ul>';
				}
			}

			// List resources
			$resources = $client->list_resources();
			$output .= '<h4>Available Resources</h4>';
			if ( is_wp_error( $resources ) ) {
				$output .= '<p>❌ Error listing resources: ' . esc_html( $resources->get_error_message() ) . '</p>';
			} else {
				$resources_list = $resources['resources'] ?? array();
				if ( empty( $resources_list ) ) {
					$output .= '<p>No resources available</p>';
				} else {
					$output .= '<ul>';
					foreach ( $resources_list as $resource ) {
						$output .= '<li><strong>' . esc_html( $resource['uri'] ) . '</strong>';
						if ( isset( $resource['description'] ) ) {
							$output .= ': ' . esc_html( $resource['description'] );
						}
						$output .= '</li>';
					}
					$output .= '</ul>';
				}
			}

			// List prompts
			$prompts = $client->list_prompts();
			$output .= '<h4>Available Prompts</h4>';
			if ( is_wp_error( $prompts ) ) {
				$output .= '<p>❌ Error listing prompts: ' . esc_html( $prompts->get_error_message() ) . '</p>';
			} else {
				$prompts_list = $prompts['prompts'] ?? array();
				if ( empty( $prompts_list ) ) {
					$output .= '<p>No prompts available</p>';
				} else {
					$output .= '<ul>';
					foreach ( $prompts_list as $prompt ) {
						$output .= '<li><strong>' . esc_html( $prompt['name'] ) . '</strong>';
						if ( isset( $prompt['description'] ) ) {
							$output .= ': ' . esc_html( $prompt['description'] );
						}
						$output .= '</li>';
					}
					$output .= '</ul>';
				}
			}

			wp_send_json_success( $output );

		} catch ( \Throwable $e ) {
			wp_send_json_error( 'Exception: ' . $e->getMessage() );
		}
	}


	/**
	 * Get all registered MCP clients.
	 *
	 * @return array Array of registered MCP clients with their information.
	 */
	private function get_registered_mcp_clients(): array {
		$adapter = \WP\MCP\Core\McpAdapter::instance();
		if ( ! $adapter ) {
			return array();
		}

		// Ensure MCP adapter has been initialized (this triggers mcp_client_init)
		$adapter->mcp_adapter_init();

		$clients = $adapter->get_clients();
		$client_info = array();

		foreach ( $clients as $client_id => $client ) {
			if ( ! $client ) {
				continue;
			}
			
			$client_info[ $client_id ] = array(
				'id' => $client_id,
				'server_url' => $client->get_server_url(),
				'connected' => $client->is_connected(),
				'capabilities' => $client->get_capabilities(),
			);
		}

		return $client_info;
	}

	/**
	 * Get all registered MCP servers.
	 *
	 * @return array Array of registered MCP servers with their information.
	 */
	private function get_registered_mcp_servers(): array {
		$adapter = \WP\MCP\Core\McpAdapter::instance();
		if ( ! $adapter ) {
			return array();
		}

		// Ensure MCP adapter has been initialized
		if ( ! $adapter->get_servers() ) {
			$adapter->mcp_adapter_init();
		}

		$servers = $adapter->get_servers();
		$server_info = array();

		foreach ( $servers as $server_id => $server ) {
			if ( ! $server ) {
				continue;
			}
			
			$server_info[ $server_id ] = array(
				'id' => $server_id,
				'name' => $server->get_server_name(),
				'description' => $server->get_server_description(),
				'version' => $server->get_server_version(),
				'endpoint' => home_url( '/wp-json/' . $server->get_server_route_namespace() . '/' . $server->get_server_route() . '/' ),
				'namespace' => $server->get_server_route_namespace(),
				'route' => $server->get_server_route(),
				'tools_count' => count( $server->get_tools() ),
				'resources_count' => count( $server->get_resources() ),
				'prompts_count' => count( $server->get_prompts() ),
			);
		}

		return $server_info;
	}

	/**
	 * Get available WordPress abilities.
	 *
	 * @return array Array of abilities with their information.
	 */
	private function get_available_abilities(): array {
		// Check if the Abilities API is available
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			// Fallback to mock data if API not available
			return array(
				'get_site_info' => array(
					'description' => 'Get basic WordPress site information (registered by MCP plugin)',
					'type' => 'tool',
				),
			);
		}

		// Get real abilities from the Abilities API
		$registered_abilities = wp_get_abilities();
		$abilities = array();

		foreach ( $registered_abilities as $ability ) {
			$ability_name = $ability->get_name();
			$abilities[ $ability_name ] = array(
				'description' => $ability->get_description() ?? 'No description available',
				'type' => 'tool', // Default to tool type
			);
		}

		// If no abilities are registered yet, show what should be available
		if ( empty( $abilities ) ) {
			$abilities['get_site_info'] = array(
				'description' => 'Get basic WordPress site information (will be registered when MCP server initializes)',
				'type' => 'tool',
			);
		}

		return $abilities;
	}

	/**
	 * Handle AJAX check server status request.
	 */
	public function handle_ajax_check_server_status(): void {
		check_ajax_referer( 'mcp_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}

		$server_id = sanitize_text_field( $_POST['server_id'] ?? '' );

		if ( empty( $server_id ) ) {
			wp_send_json_error( 'Server ID is required.' );
		}

		$servers = get_option( 'mcp_servers', array() );
		if ( ! isset( $servers[ $server_id ] ) ) {
			wp_send_json_error( 'Server not found.' );
		}

		$server = $servers[ $server_id ];

		// Debug output array
		$debug_info = array(
			'server_id' => $server_id,
			'server_config' => $server,
			'steps' => array(),
		);

		try {
			$debug_info['steps'][] = 'Starting connection test...';
			
			// Create a test client during the proper action hook
			$test_client = null;
			$client_error = null;
			
			// Create a temporary hook to create our test client
			$test_hook = function( $adapter ) use ( $server_id, $server, &$test_client, &$client_error, &$debug_info ) {
				$debug_info['steps'][] = 'Inside mcp_client_init hook';
				
				$config = array(
					'timeout' => intval( $server['timeout'] ?? 30 ),
					'ssl_verify' => isset( $server['ssl_verify'] ) ? (bool) $server['ssl_verify'] : true, // Proper boolean with default
				);

				// Add auth if configured
				if ( ! empty( $server['auth'] ) && $server['auth']['type'] !== 'none' ) {
					$config['auth'] = $server['auth'];
				}

				$debug_info['client_config'] = $config;
				$debug_info['steps'][] = 'Calling adapter->create_client()';

				$client = $adapter->create_client(
					'status-check-' . $server_id,
					$server['url'],
					$config
				);

				if ( is_wp_error( $client ) ) {
					$client_error = $client->get_error_message();
					$debug_info['client_creation_error'] = $client_error;
					$debug_info['steps'][] = 'Client creation failed: ' . $client_error;
				} else {
					$test_client = $client;
					$debug_info['steps'][] = 'Client created successfully';
				}
			};

			// Add the temporary hook
			add_action( 'mcp_client_init', $test_hook );
			$debug_info['steps'][] = 'Added temporary hook';

			// Trigger the mcp_client_init action to create our test client
			$adapter = McpAdapter::instance();
			if ( ! $adapter ) {
				$debug_info['steps'][] = 'MCP Adapter not available';
				wp_send_json_error( 'MCP Adapter is not available. Debug: ' . wp_json_encode( $debug_info ) );
			}
			$debug_info['steps'][] = 'Got MCP Adapter instance';

			// Manually trigger the client init action for testing
			do_action( 'mcp_client_init', $adapter );
			$debug_info['steps'][] = 'Triggered mcp_client_init action';

			// Remove the temporary hook
			remove_action( 'mcp_client_init', $test_hook );
			$debug_info['steps'][] = 'Removed temporary hook';

			// Check if client creation failed
			if ( $client_error ) {
				$debug_info['steps'][] = 'Client creation failed: ' . $client_error;
				wp_send_json_error( 'Failed to connect: ' . $client_error . '<br><br><strong>Debug Info:</strong><pre>' . print_r( $debug_info, true ) . '</pre>' );
			}

			if ( ! $test_client ) {
				$debug_info['steps'][] = 'No client created';
				wp_send_json_error( 'Failed to create client.<br><br><strong>Debug Info:</strong><pre>' . print_r( $debug_info, true ) . '</pre>' );
			}

			$debug_info['steps'][] = 'Client created, checking connection status';
			$debug_info['client_connected'] = $test_client->is_connected();
			$debug_info['client_capabilities'] = $test_client->get_capabilities();

			// Check connection
			if ( ! $test_client->is_connected() ) {
				$debug_info['steps'][] = 'Client not connected';
				
				// Capture recent error log entries for debugging
				$debug_info['recent_logs'] = $this->get_recent_mcp_logs();
				
				wp_send_json_error( 'Server is not responding.<br><br><strong>Debug Info:</strong><pre>' . print_r( $debug_info, true ) . '</pre>' );
			}

			// Get capabilities
			$tools = $test_client->list_tools();
			$resources = $test_client->list_resources();
			$prompts = $test_client->list_prompts();

			$response_data = array(
				'connected' => true,
				'tools' => is_wp_error( $tools ) ? array() : ( $tools['tools'] ?? array() ),
				'resources' => is_wp_error( $resources ) ? array() : ( $resources['resources'] ?? array() ),
				'prompts' => is_wp_error( $prompts ) ? array() : ( $prompts['prompts'] ?? array() ),
			);

			wp_send_json_success( $response_data );

		} catch ( \Throwable $e ) {
			$debug_info['exception'] = $e->getMessage();
			$debug_info['recent_logs'] = $this->get_recent_mcp_logs();
			
			// Provide specific error context for server status checks
			$error_details = array();
			
			// Common connection issues and their explanations
			if ( strpos( $e->getMessage(), 'cURL error' ) !== false ) {
				if ( strpos( $e->getMessage(), 'Could not resolve host' ) !== false ) {
					$error_details['reason'] = 'DNS Resolution Failed';
					$error_details['explanation'] = 'The server hostname could not be resolved. Check if the URL is correct and the server is accessible.';
				} elseif ( strpos( $e->getMessage(), 'Connection refused' ) !== false ) {
					$error_details['reason'] = 'Connection Refused';
					$error_details['explanation'] = 'The server is not accepting connections. The MCP server may be down or not running on the specified port.';
				} elseif ( strpos( $e->getMessage(), 'timeout' ) !== false ) {
					$error_details['reason'] = 'Connection Timeout';
					$error_details['explanation'] = 'The server took too long to respond. The server may be slow or overloaded.';
				} elseif ( strpos( $e->getMessage(), 'SSL' ) !== false ) {
					$error_details['reason'] = 'SSL/TLS Error';
					$error_details['explanation'] = 'There was an issue with the secure connection. Try disabling SSL verification for local development servers.';
				} else {
					$error_details['reason'] = 'Network Error';
					$error_details['explanation'] = 'A network-level error occurred: ' . $e->getMessage();
				}
			} elseif ( strpos( $e->getMessage(), 'Invalid JSON' ) !== false ) {
				$error_details['reason'] = 'Invalid Response Format';
				$error_details['explanation'] = 'The server returned an invalid JSON response. This may not be a valid MCP server.';
			} elseif ( strpos( $e->getMessage(), 'HTTP' ) !== false ) {
				$error_details['reason'] = 'HTTP Error';
				$error_details['explanation'] = 'The server returned an HTTP error: ' . $e->getMessage();
			} else {
				$error_details['reason'] = 'Unknown Error';
				$error_details['explanation'] = $e->getMessage();
			}
			
			$error_context = array(
				'server_id' => $server_id,
				'server_url' => $server['url'] ?? 'Unknown',
				'error_details' => $error_details,
				'full_exception' => $e->getMessage(),
			);
			
			error_log( 'MCP Server Status Check Failed: ' . print_r( $error_context, true ) );
			
			$error_message = $error_details['reason'] . ': ' . $error_details['explanation'];
			wp_send_json_error( $error_message );
		}
	}

	/**
	 * Get recent MCP-related log entries for debugging.
	 *
	 * @return array Recent log entries.
	 */
	private function get_recent_mcp_logs(): array {
		$logs = array();
		
		// Try to read from WordPress debug log
		$log_file = WP_CONTENT_DIR . '/debug.log';
		if ( file_exists( $log_file ) && is_readable( $log_file ) ) {
			$log_content = file_get_contents( $log_file );
			$log_lines = explode( "\n", $log_content );
			
			// Get the last 20 lines that contain "MCP"
			$mcp_lines = array_filter( $log_lines, function( $line ) {
				return stripos( $line, 'mcp' ) !== false;
			} );
			
			$logs = array_slice( array_values( $mcp_lines ), -10 ); // Last 10 MCP entries
		}
		
		// If no debug log or no MCP entries, return a placeholder
		if ( empty( $logs ) ) {
			$logs[] = 'No recent MCP log entries found. WordPress debug logging may not be enabled.';
		}
		
		return $logs;
	}

	/**
	 * Get WordPress abilities that were registered from a specific MCP client.
	 *
	 * @param string $client_id The MCP client ID.
	 * @return array Array of abilities registered from this client.
	 */
	private function get_client_registered_abilities( string $client_id ): array {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return array();
		}

		$all_abilities = wp_get_abilities();
		$client_abilities = array();
		
		// Create the expected prefix for this client's abilities
		$prefix = 'mcp-' . sanitize_key( $client_id ) . '/';
		
		foreach ( $all_abilities as $ability ) {
			$ability_name = $ability->get_name();
			
			// Check if this ability was registered from the specified client
			if ( strpos( $ability_name, $prefix ) === 0 ) {
				$client_abilities[] = array(
					'name'        => $ability_name,
					'label'       => $ability->get_label(),
					'description' => $ability->get_description(),
				);
			}
		}
		
		return $client_abilities;
	}
}