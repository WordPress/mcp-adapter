<?php
/**
 * WordPress MCP Registry - Main class for managing multiple MCP servers.
 *
 * @package WP\MCP\Core
 */

declare( strict_types=1 );

namespace WP\MCP\Core;

use WP\MCP\Abilities\DiscoverAbilitiesAbility;
use WP\MCP\Abilities\ExecuteAbilityAbility;
use WP\MCP\Abilities\GetAbilityInfoAbility;
use WP\MCP\Cli\McpCommand;
use WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface;
use WP\MCP\Infrastructure\ErrorHandling\NullMcpErrorHandler;
use WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface;
use WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler;
use WP\MCP\Servers\DefaultServerFactory;

/**
 * WordPress MCP Registry - Main class for managing multiple MCP servers.
 */
final class McpAdapter {

	public const VERSION = '0.4.1';

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
	 * Get the registry instance
	 *
	 * @return \WP\MCP\Core\McpAdapter
	 */
	public static function instance(): self {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();

			// In WP-CLI context, initialize immediately so commands have access to servers
			if ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) {
				add_action( 'init', array( self::$instance, 'init' ), 20 );
			} else {
				// Initialize for REST API requests with reasonable priority
				add_action( 'rest_api_init', array( self::$instance, 'init' ), 15 );
			}
		}

		return self::$instance;
	}

		/**
	 * Initialize the registry
	 *
	 * @internal For use by instance initialization only.
	 */
	public function init(): void {
		if ( self::$initialized ) {
			return;
		}

		$this->maybe_create_default_server();

		do_action( 'mcp_adapter_init', $this );
		$this->register_wp_cli_commands();
		self::$initialized = true;
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
	 * @return \WP\MCP\Core\McpAdapter|\WP_Error McpAdapter instance on success, WP_Error on failure.
	 */
	public function create_server( string $server_id, string $server_route_namespace, string $server_route, string $server_name, string $server_description, string $server_version, array $mcp_transports, ?string $error_handler, ?string $observability_handler = null, array $tools = array(), array $resources = array(), array $prompts = array(), ?callable $transport_permission_callback = null ) {
		// Use NullMcpErrorHandler if no error handler is provided.
		if ( ! $error_handler ) {
			$error_handler = NullMcpErrorHandler::class;
		}

		// Validate error handler class exists and implements McpErrorHandlerInterface.
		if ( ! class_exists( $error_handler ) ) {
			return new \WP_Error(
				'invalid_error_handler',
				sprintf(
					/* translators: %s: error handler class name */
					esc_html__( 'Error handler class "%s" does not exist.', 'mcp-adapter' ),
					esc_html( $error_handler )
				)
			);
		}

		if ( ! in_array( McpErrorHandlerInterface::class, class_implements( $error_handler ) ?: array(), true ) ) {
			return new \WP_Error(
				'invalid_error_handler',
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
			return new \WP_Error(
				'invalid_observability_handler',
				sprintf(
				/* translators: %s: observability handler class name */
					esc_html__( 'Observability handler class "%s" does not exist.', 'mcp-adapter' ),
					esc_html( $observability_handler )
				)
			);
		}

		if ( ! in_array( McpObservabilityHandlerInterface::class, class_implements( $observability_handler ) ?: array(), true ) ) {
			return new \WP_Error(
				'invalid_observability_handler',
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
				'0.1.0'
			);
			return new \WP_Error(
				'invalid_timing',
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
				'0.1.0'
			);
			return new \WP_Error(
				'duplicate_server_id',
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
		$server->get_observability_handler()->record_event(
			'mcp.server.created',
			array(
				'status'          => 'success',
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
	 * Conditionally create the default server based on filter.
	 *
	 * @internal For use by adapter initialization only.
	 */
	private function maybe_create_default_server(): void {
		// Allow disabling default server creation
		if ( ! apply_filters( 'mcp_adapter_create_default_server', true ) ) {
			return;
		}

		// Register category before abilities
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_default_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_default_abilities' ) );

		add_action( 'mcp_adapter_init', array( DefaultServerFactory::class, 'create' ) );
	}

	/**
	 * Register the default MCP category.
	 *
	 * @return void
	 */
	public function register_default_category(): void {
		wp_register_ability_category(
			'mcp-adapter',
			array(
				'label'       => 'MCP Adapter',
				'description' => 'Abilities for the MCP Adapter',
			)
		);
	}

	/**
	 * Register the default MCP abilities.
	 *
	 * @return void
	 */
	public function register_default_abilities(): void {
		// Register the three core MCP abilities
		DiscoverAbilitiesAbility::register();
		GetAbilityInfoAbility::register();
		ExecuteAbilityAbility::register();
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

		\WP_CLI::add_command(
			'mcp-adapter',
			McpCommand::class,
			array(
				'shortdesc' => 'Manage MCP servers via WP-CLI.',
				'longdesc'  => 'Commands for managing and serving MCP servers, including STDIO transport.',
			)
		);
	}
}
