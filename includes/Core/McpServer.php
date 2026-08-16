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
use WP\MCP\Domain\Resources\McpResource;
use WP\MCP\Domain\Tools\McpTool;
use WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface;
use WP\MCP\Infrastructure\ErrorHandling\NullMcpErrorHandler;
use WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface;
use WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler;
use WP\MCP\Infrastructure\Protocol\V20260728WireEncoder;
use WP\MCP\Infrastructure\Protocol\WireEncoder;
use WP\MCP\Infrastructure\Protocol\WireEncoderInterface;
use WP\MCP\Transport\Infrastructure\McpTransportContext;

/**
 * WordPress MCP Server - Represents a single MCP server with its tools, resources, and prompts.
 */
class McpServer {
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
	 * Whether MCP validation is enabled.
	 *
	 * @var bool
	 */
	private bool $mcp_validation_enabled;

	/**
	 * Revision-bound encoders for protocol wire output, built on first use.
	 *
	 * @var array<string, \WP\MCP\Infrastructure\Protocol\WireEncoderInterface>
	 */
	private array $wire_encoders = array();

	/**
	 * Transport permission callback.
	 *
	 * @var callable|null
	 */
	private $transport_permission_callback;


	/**
	 * Constructor.
	 *
	 * @param string $server_id Unique identifier for the server.
	 * @param string $server_route_namespace Server route namespace.
	 * @param string $server_route Server route.
	 * @param string $server_name Human-readable server name.
	 * @param string $server_description Server description.
	 * @param string $server_version Server version.
	 * @param array<class-string<\WP\MCP\Transport\Contracts\McpTransportInterface>> $mcp_transports Array of MCP transport class names to initialize (e.g., [McpRestTransport::class]).
	 * @param class-string<\WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface>|null $error_handler Error handler class to use (e.g., NullMcpErrorHandler::class). Must implement McpErrorHandlerInterface. If null, NullMcpErrorHandler will be used.
	 * @param class-string<\WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface>|null $observability_handler Observability handler class to use (e.g., NullMcpObservabilityHandler::class). Must implement McpObservabilityHandlerInterface. If null, NullMcpObservabilityHandler will be used.
	 * @param list<string> $tools Optional ability names to register as tools during construction.
	 * @param list<string> $resources Optional resources to register during construction.
	 * @param list<string> $prompts Optional prompts to register during construction.
	 * @param callable|null $transport_permission_callback Optional custom permission callback for transport-level authentication. If null, defaults to is_user_logged_in().
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

		/**
		 * Filters whether MCP protocol validation is enabled for a server.
		 *
		 * Validation is disabled by default for performance, as the Abilities API
		 * also validates all abilities. Enable this filter for stricter MCP protocol
		 * compliance checking during development or debugging.
		 *
		 * @since 0.3.0
		 *
		 * @param bool      $enabled   Whether validation is enabled. Default false.
		 * @param string    $server_id The server ID being configured.
		 * @param \WP\MCP\Core\McpServer $server    The McpServer instance being constructed.
		 */
		$this->mcp_validation_enabled = apply_filters( 'mcp_adapter_validation_enabled', false, $this->server_id, $this );

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
	 * @param list<string> $tools Tools to register.
	 * @param list<string> $resources Resources to register.
	 * @param list<string> $prompts Prompts to register.
	 * @param array<class-string<\WP\MCP\Transport\Contracts\McpTransportInterface>> $mcp_transports Transport classes to initialize.
	 *
	 * @throws \Exception
	 */
	private function setup_components( array $tools, array $resources, array $prompts, array $mcp_transports ): void {
		// Initialize component registry
		$this->component_registry = new McpComponentRegistry(
			$this,
			$this->error_handler,
			$this->observability_handler
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
	 * @param list<string> $tools Tools to register.
	 * @param list<string> $resources Resources to register.
	 * @param list<string> $prompts Prompts to register.
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
	 * Get the server name.
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

	/**
	 * Get the error handler instance.
	 *
	 * @since 0.5.0
	 *
	 * @return \WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface
	 */
	public function get_error_handler(): McpErrorHandlerInterface {
		return $this->error_handler;
	}

	/**
	 * Get the encoder that turns adapter arrays into wire arrays.
	 *
	 * Direct handler callers have no request-scoped revision, so this compatibility
	 * seam remains explicitly legacy. Transports call
	 * {@see self::get_wire_encoder_for_revision()} after resolving the request era.
	 *
	 * @since n.e.x.t
	 *
	 * @return \WP\MCP\Infrastructure\Protocol\WireEncoder
	 */
	public function get_wire_encoder(): WireEncoder {
		$encoder = $this->get_wire_encoder_for_revision( McpVersionNegotiator::LEGACY_PROTOCOL_VERSION );
		if ( ! $encoder instanceof WireEncoder ) {
			throw new \LogicException( 'Legacy MCP encoder selection returned the wrong implementation.' );
		}

		return $encoder;
	}

	/**
	 * Get the encoder for one already-resolved protocol revision.
	 *
	 * @since n.e.x.t
	 *
	 * @param string $revision Exact supported protocol revision.
	 *
	 * @throws \LogicException When the revision has no Adapter encoder.
	 */
	public function get_wire_encoder_for_revision( string $revision ): WireEncoderInterface {
		if ( isset( $this->wire_encoders[ $revision ] ) ) {
			return $this->wire_encoders[ $revision ];
		}

		$context = McpProtocolContext::for_revision( $revision );

		if ( McpVersionNegotiator::LEGACY_PROTOCOL_VERSION === $revision ) {
			$encoder = new WireEncoder( $context, $this->error_handler );
		} elseif ( McpVersionNegotiator::MODERN_PROTOCOL_VERSION === $revision ) {
			$encoder = new V20260728WireEncoder(
				$context,
				$this->error_handler,
				array(
					'name'    => $this->server_name,
					'version' => $this->server_version,
				)
			);
		} else {
			throw new \LogicException( 'No MCP wire encoder for revision ' . $revision );
		}

		$this->wire_encoders[ $revision ] = $encoder;

		return $encoder;
	}

	/**
	 * Get all tools registered to this server.
	 *
	 * @since n.e.x.t Returns revision-neutral arrays instead of DTOs.
	 *
	 * @return array<string, array<string, mixed>> Tool data arrays keyed by tool name.
	 */
	public function get_tools(): array {
		return $this->component_registry->get_tools();
	}

	/**
	 * Get all resources registered to this server.
	 *
	 * @since n.e.x.t Returns revision-neutral arrays instead of DTOs.
	 *
	 * @return array<string, array<string, mixed>> Resource data arrays keyed by resource URI.
	 */
	public function get_resources(): array {
		return $this->component_registry->get_resources();
	}

	/**
	 * Get all prompts registered to this server.
	 *
	 * @since n.e.x.t Returns revision-neutral arrays instead of DTOs.
	 *
	 * @return array<string, array<string, mixed>> Prompt data arrays keyed by prompt name.
	 */
	public function get_prompts(): array {
		return $this->component_registry->get_prompts();
	}

	/**
	 * Get a specific McpTool by name.
	 *
	 * @param string $tool_name Tool name.
	 *
	 * @return \WP\MCP\Domain\Tools\McpTool|null
	 * @internal
	 * @since 0.3.0
	 *
	 */
	public function get_mcp_tool( string $tool_name ): ?McpTool {
		return $this->component_registry->get_mcp_tool( $tool_name );
	}

	/**
	 * Get a specific McpResource by URI.
	 *
	 * @param string $resource_uri Resource URI.
	 *
	 * @return \WP\MCP\Domain\Resources\McpResource|null
	 * @internal
	 * @since 0.3.0
	 *
	 */
	public function get_mcp_resource( string $resource_uri ): ?McpResource {
		return $this->component_registry->get_mcp_resource( $resource_uri );
	}

	/**
	 * Get a specific prompt by name.
	 *
	 * @since n.e.x.t Returns a revision-neutral array instead of a DTO.
	 *
	 * @param string $prompt_name Prompt name.
	 *
	 * @return array<string, mixed>|null Prompt data in wire shape, or null when not registered.
	 */
	public function get_prompt( string $prompt_name ): ?array {
		$mcp_prompt = $this->component_registry->get_mcp_prompt( $prompt_name );

		return $mcp_prompt ? $mcp_prompt->get_protocol_dto() : null;
	}

	/**
	 * Get an McpPrompt by name.
	 *
	 * @param string $prompt_name Prompt name.
	 *
	 * @return \WP\MCP\Domain\Prompts\McpPrompt|null
	 * @internal
	 * @since 0.3.0
	 *
	 */
	public function get_mcp_prompt( string $prompt_name ): ?McpPrompt {
		return $this->component_registry->get_mcp_prompt( $prompt_name );
	}

	/**
	 * Get a prompt builder instance by prompt name (builder-based prompts).
	 *
	 * @param string $prompt_name Prompt name.
	 *
	 * @return \WP\MCP\Domain\Prompts\Contracts\McpPromptBuilderInterface|null
	 */
	public function get_prompt_builder( string $prompt_name ): ?McpPromptBuilderInterface {
		return $this->component_registry->get_prompt_builder( $prompt_name );
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
}
