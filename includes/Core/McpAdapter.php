<?php
/**
 * WordPress MCP Registry - Main class for managing multiple MCP servers.
 *
 * @package WP\MCP\Core
 */

declare( strict_types=1 );

namespace WP\MCP\Core;

use WP\MCP\Cli\McpCommand;
use WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface;
use WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler;
use WP\MCP\Infrastructure\ErrorHandling\NullMcpErrorHandler;
use WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface;
use WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler;
use WP\MCP\Transport\HttpTransport;

/**
 * WordPress MCP Registry - Main class for managing multiple MCP servers.
 */
final class McpAdapter {
	/**
	 * Registry instance
	 *
	 * @var \WP\MCP\Core\McpAdapter
	 */
	private static self $instance;

	/**
	 * Registered servers
	 *
	 * @var \WP\MCP\Core\McpServer[]
	 */
	private array $servers = array();

	/**
	 * Track if adapter has been initialized to prevent duplicate initialization
	 *
	 * @var bool
	 */
	private static bool $initialized = false;

	/**
	 * Initialize the registry
	 *
	 * @internal For use by instance initialization only.
	 */
	public function init(): void {
		if ( self::$initialized ) {
			return;
		}

		// Hook into mcp_adapter_init to create default server
		add_action( 'mcp_adapter_init', array( $this, 'create_default_server' ) );

		do_action( 'mcp_adapter_init', $this );
		$this->register_wp_cli_commands();
		self::$initialized = true;
	}

	/**
	 * Ensure adapter is initialized (can be called multiple times safely)
	 *
	 * This method is safe to call from both REST API and WP-CLI contexts.
	 * It will only initialize once regardless of how many times it's called.
	 *
	 * @return void
	 */
	public function ensure_initialized(): void {
		$this->init();
	}

	/**
	 * Get the registry instance
	 *
	 * @return \WP\MCP\Core\McpAdapter
	 */
	public static function instance(): self {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();

			// Initialize for REST API requests with reasonable priority
			add_action( 'rest_api_init', array( self::$instance, 'init' ), 15 );
		}

		return self::$instance;
	}

	/**
	 * Create and register a new MCP server.
	 *
	 * @param string $server_id Unique identifier for the server.
	 * @param string $server_route_namespace Server route namespace.
	 * @param string $server_route Server route.
	 * @param string $server_name Server name.
	 * @param string $server_description Server description.
	 * @param string $server_version Server version.
	 * @param array $mcp_transports Array of classes that extend the BaseTransport.
	 * @param class-string<\WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface> $error_handler The error handler class name. If null, NullMcpErrorHandler will be used.
	 * @param class-string<\WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface> $observability_handler The observability handler class name. If null, NullMcpObservabilityHandler will be used.
	 * @param array $tools Ability names to register as tools.
	 * @param array $resources Resources to register.
	 * @param array $prompts Prompts to register.
	 * @param callable|null $transport_permission_callback Optional custom permission callback for transport-level authentication. If null, defaults to is_user_logged_in().
	 *
	 * @return \WP\MCP\Core\McpAdapter
	 * @throws \Exception If the server already exists or if called outside of the mcp_adapter_init action.
	 */
	public function create_server( string $server_id, string $server_route_namespace, string $server_route, string $server_name, string $server_description, string $server_version, array $mcp_transports, ?string $error_handler, ?string $observability_handler = null, array $tools = array(), array $resources = array(), array $prompts = array(), ?callable $transport_permission_callback = null ): self {
		// Use NullMcpErrorHandler if no error handler is provided.
		if ( ! $error_handler ) {
			$error_handler = NullMcpErrorHandler::class;
		}

		// Validate error handler class exists and implements McpErrorHandlerInterface.
		if ( ! class_exists( $error_handler ) ) {
			throw new \Exception(
				sprintf(
					/* translators: %s: error handler class name */
					esc_html__( 'Error handler class "%s" does not exist.', 'mcp-adapter' ),
					esc_html( $error_handler )
				)
			);
		}

		if ( ! in_array( McpErrorHandlerInterface::class, class_implements( $error_handler ) ?: array(), true ) ) {
			throw new \Exception(
				sprintf(
					/* translators: %s: error handler class name */
					esc_html__( 'Error handler class "%s" must implement the McpErrorHandlerInterface.', 'mcp-adapter' ),
					esc_html( $error_handler )
				)
			);
		}

		// Use NullMcpObservabilityHandler if no observability handler is provided.
		if ( ! $observability_handler ) {
			$observability_handler = NullMcpObservabilityHandler::class;
		}

		// Validate observability handler class exists and implements McpObservabilityHandlerInterface.
		if ( ! class_exists( $observability_handler ) ) {
			throw new \Exception(
				sprintf(
					/* translators: %s: observability handler class name */
					esc_html__( 'Observability handler class "%s" does not exist.', 'mcp-adapter' ),
					esc_html( $observability_handler )
				)
			);
		}

		if ( ! in_array( McpObservabilityHandlerInterface::class, class_implements( $observability_handler ) ?: array(), true ) ) {
			throw new \Exception(
				sprintf(
					/* translators: %s: observability handler class name */
					esc_html__( 'Observability handler class "%s" must implement the McpObservabilityHandlerInterface interface.', 'mcp-adapter' ),
					esc_html( $observability_handler )
				)
			);
		}

		if ( ! doing_action( 'mcp_adapter_init' ) ) {
			_doing_it_wrong(
				__FUNCTION__,
				esc_html__( 'MCP Servers must be created during the "mcp_adapter_init" action. Hook into "mcp_adapter_init" to register your server.', 'mcp-adapter' ),
				'1.0.0'
			);
			throw new \Exception(
				esc_html__( 'MCP Server creation must be done during mcp_adapter_init action.', 'mcp-adapter' )
			);
		}

		if ( isset( $this->servers[ $server_id ] ) ) {
			_doing_it_wrong(
				__FUNCTION__,
				sprintf(
				// translators: %s: server ID
					esc_html__( 'Server with ID "%s" already exists. Each server must have a unique ID.', 'mcp-adapter' ),
					esc_html( $server_id )
				),
				'1.0.0'
			);
			throw new \Exception(
			// translators: %s: server ID.
				sprintf( esc_html__( 'Server with ID "%s" already exists.', 'mcp-adapter' ), esc_html( $server_id ) )
			);
		}

		// Create server with tools, resources, and prompts - let server handle all registration logic.
		$server = new McpServer(
			$server_id,
			$server_route_namespace,
			$server_route,
			$server_name,
			$server_description,
			$server_version,
			$mcp_transports,
			$error_handler,
			$observability_handler,
			$tools,
			$resources,
			$prompts,
			$transport_permission_callback
		);

		// Track server creation.
		$observability_handler::record_event(
			'mcp.server.created',
			array(
				'server_id'       => $server_id,
				'transport_count' => count( $mcp_transports ),
				'tools_count'     => count( $tools ),
				'resources_count' => count( $resources ),
				'prompts_count'   => count( $prompts ),
			)
		);

		// Add server to registry.
		$this->servers[ $server_id ] = $server;

		return $this;
	}

	/**
	 * Get a server by ID.
	 *
	 * @param string $server_id Server ID.
	 *
	 * @return \WP\MCP\Core\McpServer|null
	 */
	public function get_server( string $server_id ): ?McpServer {
		return $this->servers[ $server_id ] ?? null;
	}

	/**
	 * Get all registered servers
	 *
	 * @return \WP\MCP\Core\McpServer[]
	 */
	public function get_servers(): array {
		return $this->servers;
	}

	/**
	 * Create a default server with layered tools if no servers have been registered.
	 *
	 * This method is called with very low priority during mcp_adapter_init to ensure
	 * plugins have had a chance to register their own servers first.
	 *
	 * The default server configuration can be customized using:
	 * - mcp_adapter_create_default_server (bool): Whether to create default server (default: true)
	 * - mcp_adapter_default_server_config (array): Complete server configuration array
	 *
	 * The config array supports the following keys:
	 * - id (string): Server ID
	 * - namespace (string): Route namespace
	 * - route (string): Server route
	 * - name (string): Server name
	 * - description (string): Server description
	 * - version (string): Server version
	 * - transports (array): Transport classes
	 * - error_handler (string): Error handler class
	 * - observability_handler (string): Observability handler class
	 * - resources (array): Resources to register
	 * - prompts (array): Prompts to register
	 *
	 * @internal For use by adapter initialization only.
	 */
	public function create_default_server(): void {
		// Allow disabling default server creation
		if ( ! apply_filters( 'mcp_adapter_create_default_server', true ) ) {
			return;
		}

		// Default configuration
		$defaults = array(
			'id'                    => 'mcp-adapter-default-server',
			'namespace'             => 'mcp-adapter',
			'route'                 => 'mcp',
			'name'                  => 'MCP Adapter Default Server',
			'description'           => 'Default MCP server with layered tools for WordPress abilities discovery and execution',
			'version'               => 'v1.0.0',
			'transports'            => array( HttpTransport::class ),
			'error_handler'         => ErrorLogMcpErrorHandler::class,
			'observability_handler' => NullMcpObservabilityHandler::class,
			'resources'             => array(),
			'prompts'               => array(),
		);

		// Allow customization through single filter
		$config = apply_filters( 'mcp_adapter_default_server_config', $defaults );

		// Ensure config is an array and has required values
		if ( ! is_array( $config ) ) {
			$config = $defaults;
		}

		// Merge with defaults to ensure all keys exist
		$config = wp_parse_args( $config, $defaults );

		$this->create_server(
			$config['id'],
			$config['namespace'],
			$config['route'],
			$config['name'],
			$config['description'],
			$config['version'],
			$config['transports'],
			$config['error_handler'],
			$config['observability_handler'],
			array(), // Empty tools array = layered tools automatically add required tools
			array(), // No resources by default
			array() // No prompts by default
		);
	}

	/**
	 * Register WP-CLI commands if WP-CLI is available
	 *
	 * @internal For use by adapter initialization only.
	 */
	private function register_wp_cli_commands(): void {
		// Only register if WP-CLI is available
		if ( ! defined( 'WP_CLI' ) || ! constant( 'WP_CLI' ) ) {
			return;
		}

		if ( ! class_exists( 'WP_CLI' ) ) {
			return;
		}

		call_user_func(
			array( 'WP_CLI', 'add_command' ),
			'mcp-adapter',
			McpCommand::class,
			array(
				'shortdesc' => 'Manage MCP servers via WP-CLI.',
				'longdesc'  => 'Commands for managing and serving MCP servers, including STDIO transport for subprocess communication.',
			)
		);
	}
}
