<?php
/**
 * WordPress MCP Registry - Main class for managing multiple MCP servers.
 *
 * @package WP\MCP
 */

declare(strict_types=1);

namespace WP\MCP;

use WP\MCP\Registry\Server;
use WP\MCP\Tools\RegisterTool;
use WP\MCP\Utils\ErrorHandler;
/**
 * WordPress MCP Registry - Main class for managing multiple MCP servers.
 */
class Registry {
	/**
	 * Registry instance
	 *
	 * @var Registry|null
	 */
	private static ?Registry $instance = null;

	/**
	 * The initialized flag.
	 *
	 * @var bool
	 */
	private static bool $initialized = false;

	/**
	 * Registered servers
	 *
	 * @var Server[]
	 */
	private array $servers = array();

	/**
	 * The has triggered init flag.
	 *
	 * @var bool
	 */
	private bool $has_triggered_init = false;

	/**
	 * The dir.
	 *
	 * @var string
	 */
	private $plugin_dir = '';

	/**
	 * Constructor
	 */
	private function __construct() {
		// Only initialize if not already initialized.
		if ( ! self::$initialized ) {
			// Register the MCP assets late in the rest_api_init hook (required for rest_alias tools).
			// This is to ensure that the rest_api_init hook is not called too early.
			// We use a priority of 20000 to ensure that the rest_api_init hook is called after the rest_api_init hook of the FeaturesAPI plugin.
			add_action( 'rest_api_init', array( $this, 'init' ), 20000 );

			self::$initialized = true;
		}
	}

	/**
	 * Sets our plugin dir.
	 *
	 * @param string $plugin_dir The dir.
	 * @return $this
	 */
	public function set_plugin_dir( $plugin_dir ) {
		$this->plugin_dir = $plugin_dir;
		return $this;
	}

	/**
	 * Initialize the plugin.
	 */
	public function init(): void {
		// Only trigger the mcp_init action if MCP is enabled and hasn't been triggered before.
		if ( ! $this->has_triggered_init ) {
			do_action( 'wp_mcp_init', $this );
			$this->has_triggered_init = true;
		}
	}

	/**
	 * Get the registry instance
	 *
	 * @return Registry
	 */
	public static function instance(): Registry {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register(): void {
		spl_autoload_register( array( $this, 'autoload' ) );
	}

	/**
	 * Autoloader for SDK classes.
	 *
	 * @param string $class_name class name to autoload.
	 *
	 * @return void
	 */
	private function autoload( string $class_name ): void {
		// Check if it's one of our classes.
		if ( ! str_starts_with( $class_name, 'WP\\MCP\\' ) ) {
			return;
		}

		// Convert class name to file path.
		$class_file = str_replace( '\\', DIRECTORY_SEPARATOR, $class_name );
		$class_file = str_replace( 'WP' . DIRECTORY_SEPARATOR . 'MCP' . DIRECTORY_SEPARATOR, '', $class_file );

		// Include the class file.
		$file_path = $this->plugin_dir . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . $class_file . '.php';
		if ( file_exists( $file_path ) ) {
			require_once $file_path;
		}
	}

	/**
	 * Create and register a new MCP server.
	 *
	 * @param string $server_id     Unique identifier for the server.
	 * @param string $server_url    Server URL.
	 * @param string $server_name   Human-readable server name.
	 * @param string $server_description Server description.
	 * @param array  $tools Tools to register.
	 * @param array  $resources Resources to register.
	 * @param array  $prompts Prompts to register.
	 * @return self|null
	 */
	public function create_server( string $server_id, string $server_url, string $server_name, string $server_description, array $tools = array(), array $resources = array(), array $prompts = array() ): self {

		if ( ! doing_action( 'wp_mcp_init' ) ) {
			ErrorHandler::log(
				'Server creation must be done during wp_mcp_init action.',
				array(
					'method' => __METHOD__,
				)
			);
		}
		if ( isset( $this->servers[ $server_id ] ) ) {
			ErrorHandler::log(
				"Server with ID '{$server_id}' already exists.",
				array(
					'server_id' => $server_id,
					'method'    => __METHOD__,
				)
			);
		}

		// Create server with tools, resources, and prompts - let server handle all registration logic.
		$server                      = new Server( $server_id, $server_url, $server_name, $server_description, $tools, $resources, $prompts );
		$this->servers[ $server_id ] = $server;

		return $this;
	}

	/**
	 * Get a server by ID.
	 *
	 * @param string $server_id Server ID.
	 * @return Server|null
	 */
	public function get_server( string $server_id ): ?Server {
		return $this->servers[ $server_id ] ?? null;
	}

	/**
	 * Get all registered servers
	 *
	 * @return Server[]
	 */
	public function get_servers(): array {
		return $this->servers;
	}

	/**
	 * Remove a server by ID
	 *
	 * @param string $server_id Server ID.
	 * @return bool True if server was removed, false if not found
	 */
	public function remove_server( string $server_id ): bool {
		if ( isset( $this->servers[ $server_id ] ) ) {
			unset( $this->servers[ $server_id ] );
			return true;
		}
		return false;
	}

	/**
	 * Get all tools from all servers as callbacks for tool execution.
	 *
	 * @return array Array of tool callbacks indexed by tool name.
	 */
	public function get_tools_callbacks(): array {
		$tools_callbacks = array();

		foreach ( $this->servers as $server ) {
			$tools = $server->get_tools(); // This will respect enabled/disabled state.
			foreach ( $tools as $tool_name => $tool ) {
				// Avoid naming conflicts by prefixing with server ID if necessary.
				$unique_tool_name = $tool_name;
				if ( isset( $tools_callbacks[ $tool_name ] ) ) {
					$unique_tool_name = $server->get_server_id() . '_' . $tool_name;
				}
				$tools_callbacks[ $unique_tool_name ] = $tool;
			}
		}

		return $tools_callbacks;
	}

	/**
	 * Get server statistics
	 *
	 * @return array
	 */
	public function get_statistics(): array {
		$stats = array(
			'total_servers'   => count( $this->servers ),
			'total_tools'     => 0,
			'total_resources' => 0,
			'total_prompts'   => 0,
		);

		foreach ( $this->servers as $server ) {
			$stats['total_tools']     += count( $server->get_tools() );
			$stats['total_resources'] += count( $server->get_resources() );
			$stats['total_prompts']   += count( $server->get_prompts() );
		}

		return $stats;
	}

	/**
	 * Get comprehensive debug information about the MCP system.
	 *
	 * @return array Debug information array.
	 */
	public function get_debug_info(): array {
		$debug_info = array(
			'wp_mcp_initialized'     => self::$initialized,
			'init_action_triggered'  => $this->has_triggered_init,
			'servers'                => array(),
			'statistics'             => $this->get_statistics(),
		);

		// Add basic registration system info without potentially problematic data.
		$reg_debug = RegisterTool::get_registration_debug_info();
		$debug_info['registration_system'] = array(
			'rest_routes_cached'         => $reg_debug['rest_routes_cached'] ?? false,
			'rest_routes_load_attempted' => $reg_debug['rest_routes_load_attempted'] ?? false,
			'rest_routes_count'          => $reg_debug['rest_routes_count'] ?? 0,
			'rest_server_available'      => $reg_debug['rest_server_available'] ?? false,
			'rest_server_instance'       => $reg_debug['rest_server_instance'] ?? false,
		);

		// Add error message if present.
		if ( isset( $reg_debug['rest_server_error'] ) ) {
			$debug_info['registration_system']['rest_server_error'] = $reg_debug['rest_server_error'];
		}

		// Get detailed information about each server.
		foreach ( $this->servers as $server_id => $server ) {
			$server_debug = $server->get_tools_debug_info();
			$server_debug['server_url'] = $server->get_server_url();
			$server_debug['resources_count'] = count( $server->get_resources() );
			$server_debug['prompts_count'] = count( $server->get_prompts() );

			$debug_info['servers'][ $server_id ] = $server_debug;
		}

		return $debug_info;
	}
}
