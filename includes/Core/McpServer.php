<?php
/**
 * WordPress MCP Server class for managing server-specific tools, resources, and prompts.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Core;

use WP\MCP\Domain\Prompts\Contracts\McpPromptBuilderInterface;
use WP\MCP\Domain\Prompts\McpPrompt;
use WP\MCP\Domain\Prompts\RegisterAbilityAsMcpPrompt;
use WP\MCP\Domain\Resources\McpResource;
use WP\MCP\Domain\Resources\RegisterAbilityAsMcpResource;
use WP\MCP\Domain\Tools\McpTool;
use WP\MCP\Domain\Tools\RegisterAbilityAsMcpTool;
use WP\MCP\Handlers\Initialize\InitializeHandler;
use WP\MCP\Handlers\Prompts\PromptsHandler;
use WP\MCP\Handlers\Resources\ResourcesHandler;
use WP\MCP\Handlers\System\SystemHandler;
use WP\MCP\Handlers\Tools\ToolsHandler;
use WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface;
use WP\MCP\Infrastructure\ErrorHandling\NullMcpErrorHandler;
use WP\MCP\Transport\Contracts\McpTransportInterface;
use WP\MCP\Transport\Infrastructure\McpRequestRouter;
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
	 * Tools registered to this server.
	 *
	 * @var array
	 */
	private array $tools = array();

	/**
	 * Resources registered to this server.
	 *
	 * @var array
	 */
	private array $resources = array();

	/**
	 * Prompts registered to this server.
	 *
	 * @var array
	 */
	private array $prompts = array();

	/**
	 * Error handler instance.
	 *
	 * @var \WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface|null
	 */
	public ?McpErrorHandlerInterface $error_handler;

	/**
	 * Observability handler class name (e.g., NullMcpObservabilityHandler::class).
	 *
	 * @var string
	 */
	public string $observability_handler;

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

		$this->mcp_validation_enabled = apply_filters( 'mcp_validation_enabled', true );

		$this->server_id              = $server_id;
		$this->server_route_namespace = $server_route_namespace;
		$this->server_route           = $server_route;
		$this->server_name            = $server_name;
		$this->server_description     = $server_description;
		$this->server_version         = $server_version;

		// Validate and set transport permission callback
		if ( null !== $transport_permission_callback && ! is_callable( $transport_permission_callback ) ) {
			throw new \InvalidArgumentException(
				esc_html__( 'Transport permission callback must be callable.', 'mcp-adapter' )
			);
		}
		$this->transport_permission_callback = $transport_permission_callback;

		// Instantiate error handler
		if ( $error_handler && class_exists( $error_handler ) ) {
			$this->error_handler = new $error_handler();
		} else {
			$this->error_handler = new NullMcpErrorHandler();
		}

		$this->observability_handler = $observability_handler;

		// Register tools, resources, and prompts if provided.
		if ( ! empty( $tools ) ) {
			$this->register_tools( $tools );
		}
		if ( ! empty( $resources ) ) {
			$this->register_resources( $resources );
		}
		if ( ! empty( $prompts ) ) {
			$this->register_prompts( $prompts );
		}

		$this->initialize_transport( $mcp_transports );
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
	 * Register tools to this server.
	 *
	 * @param array $abilities Array of ability names to convert to MCP tools.
	 *
	 * @return void
	 */
	public function register_tools( array $abilities ): void {
		foreach ( $abilities as $ability_name ) {
			if ( ! is_string( $ability_name ) ) {
				continue;
			}

			try {
				$ability = wp_get_ability( $ability_name );

				if ( ! $ability ) {
					throw new \InvalidArgumentException( esc_html( "WordPress ability '{$ability_name}' does not exist." ) );
				}

				$tool = RegisterAbilityAsMcpTool::make( $ability, $this );
				// Add the processed tools to this server.
				$this->tools[ $tool->get_name() ] = $tool;

				// Track successful tool registration.
				$this->observability_handler::record_event(
					'mcp.component.registered',
					array(
						'component_type' => 'tool',
						'component_name' => $ability_name,
						'server_id'      => $this->server_id,
					)
				);
			} catch ( \InvalidArgumentException $e ) {
				if ( $this->error_handler ) {
					$this->error_handler->log( $e->getMessage(), array( "RegisterAbilityAsMcpTool::{$ability_name}" ) );
				}

				// Track tool registration failure.
				$this->observability_handler::record_event(
					'mcp.component.registration_failed',
					array(
						'component_type' => 'tool',
						'component_name' => $ability_name,
						'error_type'     => get_class( $e ),
						'server_id'      => $this->server_id,
					)
				);
			}
		}
	}

	/**
	 * Register a resource to this server.
	 *
	 * @param array $abilities Array of ability names to convert to MCP resources.
	 *
	 * @return void
	 */
	public function register_resources( array $abilities ): void {
		foreach ( $abilities as $ability_name ) {
			if ( ! is_string( $ability_name ) ) {
				continue;
			}

			try {
				$ability = wp_get_ability( $ability_name );

				if ( ! $ability ) {
					throw new \InvalidArgumentException( esc_html( "WordPress ability '{$ability_name}' does not exist." ) );
				}

				$resource = RegisterAbilityAsMcpResource::make( $ability, $this );
				// Add the processed resources to this server.
				$this->resources[ $resource->get_uri() ] = $resource;

				// Track successful resource registration.
				$this->observability_handler::record_event(
					'mcp.component.registered',
					array(
						'component_type' => 'resource',
						'component_name' => $ability_name,
						'server_id'      => $this->server_id,
					)
				);
			} catch ( \InvalidArgumentException $e ) {
				if ( $this->error_handler ) {
					$this->error_handler->log( $e->getMessage(), array( "RegisterAbilityAsMcpResource::{$ability_name}" ) );
				}

				// Track resource registration failure.
				$this->observability_handler::record_event(
					'mcp.component.registration_failed',
					array(
						'component_type' => 'resource',
						'component_name' => $ability_name,
						'error_type'     => get_class( $e ),
						'server_id'      => $this->server_id,
					)
				);
			}
		}
	}

	/**
	 * Register a prompt to this server.
	 *
	 * @param array $prompts Array of prompts to register. Can be ability names (strings) or prompt builder class names.
	 *
	 * @return void
	 */
	public function register_prompts( array $prompts ): void {
		foreach ( $prompts as $prompt_item ) {
			if ( ! is_string( $prompt_item ) ) {
				continue;
			}

			// Check if it's a class that implements McpPromptBuilderInterface
			if ( class_exists( $prompt_item ) && in_array( McpPromptBuilderInterface::class, class_implements( $prompt_item ) ?: array(), true ) ) {
				try {
					// Create instance of the prompt builder class
					$builder = new $prompt_item();
					$prompt  = $builder->build();

					// Set the MCP server after building
					$prompt->set_mcp_server( $this );

					// Validate if validation is enabled
					if ( $this->is_mcp_validation_enabled() ) {
						$prompt->validate( "McpPromptBuilder::{$prompt_item}" );
					}

					// Add the prompt to this server
					$this->prompts[ $prompt->get_name() ] = $prompt;

					// Track successful prompt registration
					$this->observability_handler::record_event(
						'mcp.component.registered',
						array(
							'component_type' => 'prompt',
							'component_name' => $prompt_item,
							'server_id'      => $this->server_id,
						)
					);
				} catch ( \InvalidArgumentException $e ) {
					if ( $this->error_handler ) {
						$this->error_handler->log( $e->getMessage(), array( "McpPromptBuilder::{$prompt_item}" ) );
					}

					// Track prompt registration failure
					$this->observability_handler::record_event(
						'mcp.component.registration_failed',
						array(
							'component_type' => 'prompt',
							'component_name' => $prompt_item,
							'error_type'     => get_class( $e ),
							'server_id'      => $this->server_id,
						)
					);
				}
			} else {
				// Treat as ability name (legacy behavior)
				try {
					$ability = wp_get_ability( $prompt_item );

					if ( ! $ability ) {
						throw new \InvalidArgumentException( esc_html( "WordPress ability '{$prompt_item}' does not exist." ) );
					}

					// Use RegisterMcpPrompt to handle all validation and processing.
					$prompt = RegisterAbilityAsMcpPrompt::make( $ability, $this );

					// Add the processed prompts to this server.
					$this->prompts[ $prompt->get_name() ] = $prompt;

					// Track successful prompt registration.
					$this->observability_handler::record_event(
						'mcp.component.registered',
						array(
							'component_type' => 'prompt',
							'component_name' => $prompt_item,
							'server_id'      => $this->server_id,
						)
					);
				} catch ( \InvalidArgumentException $e ) {
					if ( $this->error_handler ) {
						$this->error_handler->log( $e->getMessage(), array( "RegisterAbilityAsMcpPrompt::{$prompt_item}" ) );
					}

					// Track prompt registration failure.
					$this->observability_handler::record_event(
						'mcp.component.registration_failed',
						array(
							'component_type' => 'prompt',
							'component_name' => $prompt_item,
							'error_type'     => get_class( $e ),
							'server_id'      => $this->server_id,
						)
					);
				}
			}
		}
	}

	/**
	 * Get all tools registered to this server.
	 *
	 * @return array
	 */
	public function get_tools(): array {
		return $this->tools;
	}

	/**
	 * Get all resources registered to this server.
	 *
	 * @return \WP\MCP\Domain\Resources\McpResource[]
	 */
	public function get_resources(): array {
		return $this->resources;
	}

	/**
	 * Get all prompts registered to this server.
	 *
	 * @return array
	 */
	public function get_prompts(): array {
		return $this->prompts;
	}

	/**
	 * Get a specific tool by name.
	 *
	 * @param string $tool_name Tool name.
	 *
	 * @return \WP\MCP\Domain\Tools\McpTool|null
	 */
	public function get_tool( string $tool_name ): ?McpTool {
		return $this->tools[ $tool_name ] ?? null;
	}

	/**
	 * Get a specific resource by URI.
	 *
	 * @param string $resource_uri Resource URI.
	 *
	 * @return \WP\MCP\Domain\Resources\McpResource|null
	 */
	public function get_resource( string $resource_uri ): ?McpResource {
		return $this->resources[ $resource_uri ] ?? null;
	}

	/**
	 * Get a specific prompt by name.
	 *
	 * @param string $prompt_name Prompt name.
	 *
	 * @return \WP\MCP\Domain\Prompts\McpPrompt|null
	 */
	public function get_prompt( string $prompt_name ): ?McpPrompt {
		return $this->prompts[ $prompt_name ] ?? null;
	}

	/**
	 * Remove a tool from this server.
	 *
	 * @param string $tool_name Tool name.
	 *
	 * @return bool True if removed, false if not found.
	 */
	public function remove_tool( string $tool_name ): bool {
		if ( isset( $this->tools[ $tool_name ] ) ) {
			unset( $this->tools[ $tool_name ] );

			return true;
		}

		return false;
	}

	/**
	 * Remove a resource from this server.
	 *
	 * @param string $resource_uri Resource URI.
	 *
	 * @return bool True if removed, false if not found.
	 */
	public function remove_resource( string $resource_uri ): bool {
		if ( isset( $this->resources[ $resource_uri ] ) ) {
			unset( $this->resources[ $resource_uri ] );

			return true;
		}

		return false;
	}

	/**
	 * Remove a prompt from this server.
	 *
	 * @param string $prompt_name Prompt name.
	 *
	 * @return bool True if removed, false if not found.
	 */
	public function remove_prompt( string $prompt_name ): bool {
		if ( isset( $this->prompts[ $prompt_name ] ) ) {
			unset( $this->prompts[ $prompt_name ] );

			return true;
		}

		return false;
	}

	/**
	 * Initialize MCP transports for this server.
	 *
	 * @param array $mcp_transports Array of MCP transport class names to initialize.
	 *
	 * @throws \Exception If any transport class does not implement McpTransportInterface.
	 */
	public function initialize_transport( array $mcp_transports ): void {
		foreach ( $mcp_transports as $mcp_transport ) {
			// Check for interface implementation
			if ( ! in_array( McpTransportInterface::class, class_implements( $mcp_transport ) ?: array(), true ) ) {
				throw new \Exception(
					esc_html__( 'MCP transport class must implement the McpTransportInterface.', 'mcp-adapter' )
				);
			}

			// Interface-based instantiation with dependency injection
			$context = $this->create_transport_context();
			new $mcp_transport( $context );
		}
	}

	/**
	 * Create transport context with all required dependencies.
	 *
	 * @return \WP\MCP\Transport\Infrastructure\McpTransportContext
	 */
	private function create_transport_context(): McpTransportContext {
		// Create handlers
		$initialize_handler = new InitializeHandler( $this );
		$tools_handler      = new ToolsHandler( $this );
		$resources_handler  = new ResourcesHandler( $this );
		$prompts_handler    = new PromptsHandler( $this );
		$system_handler     = new SystemHandler( $this );

		// Create context for the router first (without router to avoid circular dependency)
		$router_context = new McpTransportContext(
			array(
				'mcp_server'                    => $this,
				'initialize_handler'            => $initialize_handler,
				'tools_handler'                 => $tools_handler,
				'resources_handler'             => $resources_handler,
				'prompts_handler'               => $prompts_handler,
				'system_handler'                => $system_handler,
				'observability_handler'         => $this->observability_handler,
				'request_router'                => null,
				'transport_permission_callback' => $this->transport_permission_callback,
			)
		);

		// Create the router
		$request_router = new McpRequestRouter( $router_context );

		// Create the final context with the router
		return new McpTransportContext(
			array(
				'mcp_server'                    => $this,
				'initialize_handler'            => $initialize_handler,
				'tools_handler'                 => $tools_handler,
				'resources_handler'             => $resources_handler,
				'prompts_handler'               => $prompts_handler,
				'system_handler'                => $system_handler,
				'observability_handler'         => $this->observability_handler,
				'request_router'                => $request_router,
				'transport_permission_callback' => $this->transport_permission_callback,
			)
		);
	}

	/**
	 * Check if MCP validation is enabled.
	 *
	 * @return bool
	 */
	public function is_mcp_validation_enabled(): bool {
		return $this->mcp_validation_enabled;
	}
}
