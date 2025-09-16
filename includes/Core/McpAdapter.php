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
use WP\MCP\Infrastructure\ErrorHandling\NullMcpErrorHandler;
use WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface;
use WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler;

/**
 * WordPress MCP Registry - Main class for managing multiple MCP servers.
 */
final class McpAdapter {
	/**
	 * Registry instance
	 *
	 * @var McpAdapter
	 */
	private static self $instance;

	/**
	 * Registered servers
	 *
	 * @var McpServer[]
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
	 * @return McpAdapter
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
	 * @param string        $server_id              Unique identifier for the server.
	 * @param string        $server_route_namespace Server route namespace.
	 * @param string        $server_route           Server route.
	 * @param string        $server_name            Server name.
	 * @param string        $server_description     Server description.
	 * @param string        $server_version         Server version.
	 * @param array         $mcp_transports         Array of classes that extend the BaseTransport.
	 * @param ?class-string<McpErrorHandlerInterface>         $error_handler The error handler class name. If null, NullMcpErrorHandler will be used.
	 * @param ?class-string<McpObservabilityHandlerInterface> $observability_handler The observability handler class name. If null, NullMcpObservabilityHandler will be used.
	 * @param array         $tools Ability names to register as tools.
	 * @param array         $resources Resources to register.
	 * @param array         $prompts Prompts to register.
	 * @param callable|null $transport_permission_callback Optional custom permission callback for transport-level authentication. If null, defaults to is_user_logged_in().
	 *
	 * @return McpAdapter
	 * @throws \Exception If the server already exists or if called outside of the mcp_adapter_init action.
	 */
	public function create_server( string $server_id, string $server_route_namespace, string $server_route, string $server_name, string $server_description, string $server_version, array $mcp_transports, ?string $error_handler, ?string $observability_handler = null, array $tools = array(), array $resources = array(), array $prompts = array(), ?callable $transport_permission_callback = null ): self {

		// Use NullMcpErrorHandler if no error handler is provided.
		if ( ! $error_handler ) {
			$error_handler = NullMcpErrorHandler::class;
		}

		// Validate error handler class implements McpErrorHandlerInterface.
		if ( ! in_array( McpErrorHandlerInterface::class, class_implements( $error_handler ) ?: array(), true ) ) {
			throw new \Exception(
				esc_html__( 'Error handler class must implement the McpErrorHandlerInterface.', 'mcp-adapter' )
			);
		}

		// Use NullMcpObservabilityHandler if no observability handler is provided.
		if ( ! $observability_handler ) {
			$observability_handler = NullMcpObservabilityHandler::class;
		}

		// Validate observability handler class implements McpObservabilityHandlerInterface.
		if ( ! in_array( McpObservabilityHandlerInterface::class, class_implements( $observability_handler ) ?: array(), true ) ) {
			throw new \Exception(
				esc_html__( 'Observability handler class must implement the McpObservabilityHandlerInterface interface.', 'mcp-adapter' )
			);
		}

		if ( ! doing_action( 'mcp_adapter_init' ) ) {
			throw new \Exception(
				esc_html__( 'MCP Server creation must be done during mcp_adapter_init action.', 'mcp-adapter' )
			);
		}

		if ( isset( $this->servers[ $server_id ] ) ) {
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
	 * @return McpServer|null
	 */
	public function get_server( string $server_id ): ?McpServer {
		return $this->servers[ $server_id ] ?? null;
	}

	/**
	 * Get all registered servers
	 *
	 * @return McpServer[]
	 */
	public function get_servers(): array {
		return $this->servers;
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
