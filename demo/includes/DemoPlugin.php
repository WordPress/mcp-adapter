<?php
/**
 * Demo plugin main class.
 *
 * @package MCP\Demo
 */

declare(strict_types=1);

namespace WP\MCP\Demo;

use WP\MCP\Core\McpAdapter;
use WP\MCP\Demo\Admin\McpTestPage;

/**
 * MCP Adapter Demo Plugin
 */
final class DemoPlugin {
	/**
	 * The one true instance.
	 *
	 * @var ?static
	 */
	private static $instance;

	/**
	 * Get instance.
	 */
	public static function instance(): self {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
			self::$instance->setup();
		}

		return self::$instance;
	}

	/**
	 * Setup the demo plugin.
	 */
	private function setup(): void {
		// Initialize admin interface
		if ( is_admin() ) {
			$test_page = new McpTestPage();
			$test_page->init();
		}
		
		// Register demo abilities during the correct action
		add_action( 'abilities_api_init', array( $this, 'register_demo_abilities' ) );
		
		// Set up demo MCP server
		add_action( 'mcp_adapter_init', array( $this, 'setup_demo_server' ) );
		
		// Load examples early so their hooks are registered before mcp_adapter_init fires
		$this->load_examples();
	}
	
	/**
	 * Register demo abilities during abilities_api_init.
	 * 
	 * Note: In production, abilities would come pre-bundled with the Abilities API.
	 * This is just for demo/testing purposes.
	 */
	public function register_demo_abilities(): void {
		// Demo abilities are assumed to come from the Abilities API itself
		// This method is kept for potential future demo-specific abilities
	}
	
	/**
	 * Set up demo MCP server.
	 */
	public function setup_demo_server( McpAdapter $adapter ): void {
		// Create demo MCP server using abilities from the Abilities API
		$adapter->create_server(
			'wordpress-demo',
			'mcp',
			'demo',
			'WordPress Demo Server',
			'Demo MCP server showcasing MCP Adapter functionality',
			'1.0.0',
			array(
				\WP\MCP\Transport\Http\RestTransport::class,
			),
			null,
			null,
			array(), // Tools will be populated from existing abilities
			array(),
			array(),
			function() { return true; } // Public access
		);
	}
	
	/**
	 * Load examples.
	 */
	private function load_examples(): void {
		// Load server examples
		$server_examples_file = WP_MCP_DEMO_DIR . 'examples/server-usage.php';
		if ( file_exists( $server_examples_file ) ) {
			include_once $server_examples_file;
		}
		
		// Load client examples  
		$client_examples_file = WP_MCP_DEMO_DIR . 'examples/client-usage.php';
		if ( file_exists( $client_examples_file ) ) {
			include_once $client_examples_file;
		}
	}
}