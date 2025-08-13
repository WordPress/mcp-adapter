<?php
/**
 * WordPress MCP Registry - Main class for managing multiple MCP servers.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Core;

use Exception;
use WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface;
use WP\MCP\Infrastructure\ErrorHandling\NullMcpErrorHandler;
use WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface;
use WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler;
use WP\MCP\Core\McpServer;

/**
 * WordPress MCP Registry - Main class for managing multiple MCP servers.
 */
class McpAdapter {
	/**
	 * Registry instance
	 *
	 * @var McpAdapter|null
	 */
	private static ?McpAdapter $instance = null;

	/**
	 * The initialized flag.
	 *
	 * @var bool
	 */
	private static bool $initialized = false;

	/**
	 * Flag to track if initialization failed due to missing dependencies.
	 *
	 * @var bool
	 */
	private static bool $initialization_failed = false;

	/**
	 * Stores the reason for initialization failure.
	 *
	 * @var string[]
	 */
	private static array $initialization_errors = array();

	/**
	 * Registered servers
	 *
	 * @var McpServer[]
	 */
	private array $servers = array();

	/**
	 * The has triggered init flag.
	 *
	 * @var bool
	 */
	private bool $has_triggered_init = false;

	/**
	 * Constructor
	 */
	private function __construct() {
		if ( ! self::$initialized && ! self::$initialization_failed ) {
			if ( ! $this->check_dependencies() ) {
				self::$initialization_failed = true;
				return;
			}
			add_action( 'rest_api_init', array( $this, 'mcp_adapter_init' ), 20000 );
			self::$initialized = true;
		}
	}

	/**
	 * Check if all required dependencies are available.
	 *
	 * @return bool True if all dependencies are met, false otherwise.
	 */
	private function check_dependencies(): bool {
		$errors = array();

		// Check if we're in a WordPress environment.
		if ( ! defined( 'ABSPATH' ) ) {
			$errors[] = 'WordPress environment not detected (ABSPATH not defined)';
		}

		// Check if Abilities API is available.
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$errors[] = 'Abilities API not available (wp_register_ability function not found)';
		}

		// Store errors for later retrieval.
		self::$initialization_errors = $errors;

		// Log errors if any.
		if ( ! empty( $errors ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Initialize the MCP adapter.
	 */
	public function mcp_adapter_init(): void {
		// Bail early if initialization failed.
		if ( self::$initialization_failed ) {
			return;
		}

		if ( ! $this->has_triggered_init ) {
			do_action( 'mcp_adapter_init', $this );
			$this->has_triggered_init = true;
		}
	}

	/**
	 * Get the registry instance
	 *
	 * @return McpAdapter|null Returns null if initialization failed due to missing dependencies.
	 */
	public static function instance(): ?McpAdapter {
		if ( null === self::$instance && ! self::$initialization_failed ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Check if the MCP adapter is available (dependencies are met).
	 *
	 * @return bool True if the adapter is available, false otherwise.
	 */
	public static function is_available(): bool {
		return ! self::$initialization_failed;
	}

	/**
	 * Get the initialization errors if any.
	 *
	 * @return string[] Array of error messages.
	 */
	public static function get_initialization_errors(): array {
		return self::$initialization_errors;
	}

	/**
	 * Get detailed dependency status for troubleshooting.
	 *
	 * @return array Array with dependency status information.
	 */
	public static function get_dependency_status(): array {
		return array(
			'is_available'            => self::is_available(),
			'wordpress_detected'      => defined( 'ABSPATH' ),
			'abilities_api_available' => function_exists( 'wp_register_ability' ),
			'initialization_errors'   => self::$initialization_errors,
			'debug_info'              => array(
				'php_version'       => PHP_VERSION,
				'wordpress_version' => function_exists( 'get_bloginfo' ) ? get_bloginfo( 'version' ) : 'Unknown',
				'wp_debug'          => defined( 'WP_DEBUG' ) ? WP_DEBUG : false,
				'wp_debug_log'      => defined( 'WP_DEBUG_LOG' ) ? WP_DEBUG_LOG : false,
			),
		);
	}

	/**
	 * Create and register a new MCP server.
	 *
	 * @param string        $server_id Unique identifier for the server.
	 * @param string        $server_route_namespace Server route namespace.
	 * @param string        $server_route Server route.
	 * @param string        $server_name Server name.
	 * @param string        $server_description Server description.
	 * @param string        $server_version Server version.
	 * @param array         $mcp_transports Array of classes that extend the BaseTransport.
	 * @param string|null   $error_handler The error handler class name. If null, NullMcpErrorHandler will be used.
	 * @param string|null   $observability_handler The observability handler class name. If null, NullMcpObservabilityHandler will be used.
	 * @param array         $tools Ability names to register as tools.
	 * @param array         $resources Resources to register.
	 * @param array         $prompts Prompts to register.
	 * @param callable|null $transport_permission_callback Optional custom permission callback for transport-level authentication. If null, defaults to is_user_logged_in().
	 *
	 * @return McpAdapter
	 * @throws Exception If the server already exists or if called outside of the mcp_adapter_init action.
	 */
	public function create_server( string $server_id, string $server_route_namespace, string $server_route, string $server_name, string $server_description, string $server_version, array $mcp_transports, ?string $error_handler, ?string $observability_handler = null, array $tools = array(), array $resources = array(), array $prompts = array(), ?callable $transport_permission_callback = null ): self {

		// Use NullMcpErrorHandler if no error handler is provided.
		if ( ! $error_handler ) {
			$error_handler = NullMcpErrorHandler::class;
		}

		// Validate error handler class implements McpErrorHandlerInterface.
		if ( ! in_array( McpErrorHandlerInterface::class, class_implements( $error_handler ) ?: array(), true ) ) {
			throw new Exception(
				esc_html__( 'Error handler class must implement the McpErrorHandlerInterface.', 'mcp-adapter' )
			);
		}

		// Use NullMcpObservabilityHandler if no observability handler is provided.
		if ( ! $observability_handler ) {
			$observability_handler = NullMcpObservabilityHandler::class;
		}

		// Validate observability handler class implements McpObservabilityHandlerInterface.
		if ( ! in_array( McpObservabilityHandlerInterface::class, class_implements( $observability_handler ) ?: array(), true ) ) {
			throw new Exception(
				esc_html__( 'Observability handler class must implement the McpObservabilityHandlerInterface interface.', 'mcp-adapter' )
			);
		}

		if ( ! doing_action( 'mcp_adapter_init' ) ) {
			throw new Exception(
				esc_html__( 'MCP Server creation must be done during mcp_adapter_init action.', 'mcp-adapter' )
			);
		}
		if ( isset( $this->servers[ $server_id ] ) ) {
			throw new Exception(
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
}
