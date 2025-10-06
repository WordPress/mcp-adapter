<?php
/**
 * WordPress MCP Server class for managing server-specific tools, resources, and prompts.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Core;

use WP\MCP\Domain\Prompts\McpPrompt;
use WP\MCP\Domain\Resources\McpResource;
use WP\MCP\Domain\Tools\McpTool;
use WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface;
use WP\MCP\Infrastructure\ErrorHandling\NullMcpErrorHandler;
use WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface;
use WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler;
use WP\MCP\Transport\Infrastructure\McpTransportContext;

/**
 * WordPress MCP Server - Represents a single MCP server with its tools, resources, and prompts.
 */
class McpServer {
	/**
	 * Server ID.
	 *
	 * @var string
	 */
	private string $server_id;

	/**
	 * Server URL.
	 *
	 * @var string
	 */
	private string $server_route_namespace;

	/**
	 * Server route.
	 *
	 * @var string
	 */
	private string $server_route;

	/**
	 * Server name.
	 *
	 * @var string
	 */
	private string $server_name;

	/**
	 * Server description.
	 *
	 * @var string
	 */
	private string $server_description;

	/**
	 * Server version.
	 *
	 * @var string
	 */
	private string $server_version;

	/**
	 * Component registry for managing tools, resources, and prompts.
	 *
	 * @var \WP\MCP\Core\McpComponentRegistry
	 */
	private McpComponentRegistry $component_registry;

	/**
	 * Transport factory for initializing transports.
	 *
	 * @var \WP\MCP\Core\McpTransportFactory
	 */
	private McpTransportFactory $transport_factory;

	/**
	 * Error handler instance.
	 *
	 * @var \WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface
	 */
	public McpErrorHandlerInterface $error_handler;

	/**
	 * Observability handler instance.
	 *
	 * @var \WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface
	 */
	public McpObservabilityHandlerInterface $observability_handler;

	/**
	 * Whether MCP validation is enabled.
	 *
	 * @var bool
	 */
	private bool $mcp_validation_enabled;

	/**
	 * Transport permission callback.
	 *
	 * @var callable|null
	 */
	private $transport_permission_callback;


	/**
	 * Constructor.
	 *
	 * @param string                                              $server_id Unique identifier for the server.
	 * @param string                                              $server_route_namespace Server route namespace.
	 * @param string                                              $server_route Server route.
	 * @param string                                              $server_name Human-readable server name.
	 * @param string                                              $server_description Server description.
	 * @param string                                              $server_version Server version.
	 * @param array                                               $mcp_transports Array of MCP transport class names to initialize (e.g., [McpRestTransport::class]).
	 * @param class-string<\WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface>|null         $error_handler Error handler class to use (e.g., NullMcpErrorHandler::class). Must implement McpErrorHandlerInterface. If null, NullMcpErrorHandler will be used.
	 * @param class-string<\WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface>|null $observability_handler Observability handler class to use (e.g., NullMcpObservabilityHandler::class). Must implement McpObservabilityHandlerInterface. If null, NullMcpObservabilityHandler will be used.
	 * @param array                                               $tools Optional ability names to register as tools during construction.
	 * @param array                                               $resources Optional resources to register during construction.
	 * @param array                                               $prompts Optional prompts to register during construction.
	 * @param callable|null                                       $transport_permission_callback Optional custom permission callback for transport-level authentication. If null, defaults to is_user_logged_in().
	 *
	 * @throws \Exception Thrown if the MCP transport class does not extend AbstractMcpTransport.
	 */
	public function __construct(
		string $server_id,
		string $server_route_namespace,
		string $server_route,
		string $server_name,
		string $server_description,
		string $server_version,
		array $mcp_transports,
		?string $error_handler,
		?string $observability_handler,
		array $tools = array(),
		array $resources = array(),
		array $prompts = array(),
		?callable $transport_permission_callback = null
	) {
		// Store server configuration
		$this->server_id                     = $server_id;
		$this->server_route_namespace        = $server_route_namespace;
		$this->server_route                  = $server_route;
		$this->server_name                   = $server_name;
		$this->server_description            = $server_description;
		$this->server_version                = $server_version;
		$this->transport_permission_callback = $transport_permission_callback;

		// Setup validation flag. Validation is disabled by default for performance.
		// Abilities API is also validating all abilities.
		$this->mcp_validation_enabled = apply_filters( 'mcp_adapter_validation_enabled', false );

		// Setup handlers and components
		$this->setup_handlers( $error_handler, $observability_handler );
		$this->setup_components( $tools, $resources, $prompts, $mcp_transports );
	}

	/**
	 * Setup error and observability handlers.
	 *
	 * @param string|null $error_handler Error handler class name.
	 * @param string|null $observability_handler Observability handler class name.
	 */
	private function setup_handlers( ?string $error_handler, ?string $observability_handler ): void {
		// Instantiate error handler
		if ( $error_handler && class_exists( $error_handler ) ) {
			/** @var \WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface $handler */
			$handler             = new $error_handler();
			$this->error_handler = $handler;
		} else {
			$this->error_handler = new NullMcpErrorHandler();
		}

		// Instantiate observability handler
		if ( $observability_handler && class_exists( $observability_handler ) ) {
			/** @var \WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface $handler */
			$handler                     = new $observability_handler();
			$this->observability_handler = $handler;
		} else {
			$this->observability_handler = new NullMcpObservabilityHandler();
		}
	}

	/**
	 * Setup component registry and transport factory.
	 *
	 * @param array $tools Tools to register.
	 * @param array $resources Resources to register.
	 * @param array $prompts Prompts to register.
	 * @param array $mcp_transports Transport classes to initialize.
	 *
	 * @throws \Exception
	 */
	private function setup_components( array $tools, array $resources, array $prompts, array $mcp_transports ): void {
		// Initialize component registry
		$this->component_registry = new McpComponentRegistry(
			$this,
			$this->error_handler,
			$this->observability_handler,
			$this->mcp_validation_enabled
		);

		// Initialize transport factory
		$this->transport_factory = new McpTransportFactory( $this );

		// Register tools, resources, and prompts
		$this->register_mcp_components( $tools, $resources, $prompts );

		// Initialize transports
		$this->transport_factory->initialize_transports( $mcp_transports );
	}

	/**
	 * Register initial tools, resources, and prompts.
	 *
	 * @param array $tools Tools to register.
	 * @param array $resources Resources to register.
	 * @param array $prompts Prompts to register.
	 */
	private function register_mcp_components( array $tools, array $resources, array $prompts ): void {
		// Register tools if provided
		if ( ! empty( $tools ) ) {
			$this->component_registry->register_tools( $tools );
		}

		// Register resources if provided
		if ( ! empty( $resources ) ) {
			$this->component_registry->register_resources( $resources );
		}

		// Register prompts if provided
		if ( empty( $prompts ) ) {
			return;
		}

		$this->component_registry->register_prompts( $prompts );
	}

	/**
	 * Get server ID.
	 *
	 * @return string
	 */
	public function get_server_id(): string {
		return $this->server_id;
	}

	/**
	 * Get server route namespace.
	 *
	 * @return string
	 */
	public function get_server_route_namespace(): string {
		return $this->server_route_namespace;
	}

	/**
	 * Get server route.
	 *
	 * @return string
	 */
	public function get_server_route(): string {
		return $this->server_route;
	}

	/**
	 * Get server name.
	 *
	 * @return string
	 */
	public function get_server_name(): string {
		return $this->server_name;
	}

	/**
	 * Get server description.
	 *
	 * @return string
	 */
	public function get_server_description(): string {
		return $this->server_description;
	}

	/**
	 * Get server version.
	 *
	 * @return string
	 */
	public function get_server_version(): string {
		return $this->server_version;
	}

	/**
	 * Get the transport permission callback.
	 *
	 * @return callable|null
	 */
	public function get_transport_permission_callback(): ?callable {
		return $this->transport_permission_callback;
	}

	/**
	 * Get the observability handler instance.
	 *
	 * @return \WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface
	 */
	public function get_observability_handler(): McpObservabilityHandlerInterface {
		return $this->observability_handler;
	}

	public function get_error_handler(): McpErrorHandlerInterface {
		return $this->error_handler;
	}

	/**
	 * Get all tools registered to this server.
	 *
	 * @return array
	 */
	public function get_tools(): array {
		return $this->component_registry->get_tools();
	}

	/**
	 * Get all resources registered to this server.
	 *
	 * @return \WP\MCP\Domain\Resources\McpResource[]
	 */
	public function get_resources(): array {
		return $this->component_registry->get_resources();
	}

	/**
	 * Get all prompts registered to this server.
	 *
	 * @return array
	 */
	public function get_prompts(): array {
		return $this->component_registry->get_prompts();
	}

	/**
	 * Get a specific tool by name.
	 *
	 * @param string $tool_name Tool name.
	 *
	 * @return \WP\MCP\Domain\Tools\McpTool|null
	 */
	public function get_tool( string $tool_name ): ?McpTool {
		return $this->component_registry->get_tool( $tool_name );
	}

	/**
	 * Get a specific resource by URI.
	 *
	 * @param string $resource_uri Resource URI.
	 *
	 * @return \WP\MCP\Domain\Resources\McpResource|null
	 */
	public function get_resource( string $resource_uri ): ?McpResource {
		return $this->component_registry->get_resource( $resource_uri );
	}

	/**
	 * Get a specific prompt by name.
	 *
	 * @param string $prompt_name Prompt name.
	 *
	 * @return \WP\MCP\Domain\Prompts\McpPrompt|null
	 */
	public function get_prompt( string $prompt_name ): ?McpPrompt {
		return $this->component_registry->get_prompt( $prompt_name );
	}

	/**
	 * Create transport context with all required dependencies.
	 *
	 * @return \WP\MCP\Transport\Infrastructure\McpTransportContext
	 */
	public function create_transport_context(): McpTransportContext {
		return $this->transport_factory->create_transport_context();
	}

	/**
	 * Check if MCP validation is enabled.
	 *
	 * @return bool
	 */
	public function is_mcp_validation_enabled(): bool {
		return $this->mcp_validation_enabled;
	}

	/**
	 * Get the component registry instance.
	 *
	 * @return \WP\MCP\Core\McpComponentRegistry
	 */
	public function get_component_registry(): McpComponentRegistry {
		return $this->component_registry;
	}
}
