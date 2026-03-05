<?php

/**
 * MCP Component Registry for managing tools, resources, and prompts.
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
use WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface;
use WP\MCP\Infrastructure\Observability\FailureReason;
use WP\McpSchema\Server\Prompts\DTO\Prompt as PromptDto;
use WP\McpSchema\Server\Resources\DTO\Resource as ResourceDto;
use WP\McpSchema\Server\Tools\DTO\Tool as ToolDto;
use WP_Error;

/**
 * Registry for managing MCP server components (tools, resources, prompts).
 */
class McpComponentRegistry {

	/**
	 * MCP tools keyed by tool name.
	 *
	 * @var array<string, \WP\MCP\Domain\Tools\McpTool>
	 */
	private array $mcp_tools = array();

	/**
	 * MCP resources keyed by resource URI.
	 *
	 * @var array<string, \WP\MCP\Domain\Resources\McpResource>
	 */
	private array $mcp_resources = array();

	/**
	 * MCP prompts keyed by prompt name.
	 *
	 * @var array<string, \WP\MCP\Domain\Prompts\McpPrompt>
	 */
	private array $mcp_prompts = array();

	/**
	 * MCP Server instance.
	 *
	 * @var \WP\MCP\Core\McpServer
	 */
	private McpServer $mcp_server;

	/**
	 * Error handler instance.
	 *
	 * @var \WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface
	 */
	private McpErrorHandlerInterface $error_handler;

	/**
	 * Observability handler instance.
	 *
	 * @var \WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface
	 */
	private McpObservabilityHandlerInterface $observability_handler;

	/**
	 * Whether to record component registration.
	 *
	 * @var bool
	 */
	private bool $should_record_component_registration;

	/**
	 * Constructor.
	 *
	 * @param \WP\MCP\Core\McpServer $mcp_server MCP server instance.
	 * @param \WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface $error_handler Error handler instance.
	 * @param \WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface $observability_handler Observability handler instance.
	 */
	public function __construct(
		McpServer $mcp_server,
		McpErrorHandlerInterface $error_handler,
		McpObservabilityHandlerInterface $observability_handler
	) {
		$this->mcp_server            = $mcp_server;
		$this->error_handler         = $error_handler;
		$this->observability_handler = $observability_handler;

		/**
		 * Filters whether component registration events should be recorded for observability.
		 *
		 * Default is false to avoid polluting observability logs during startup.
		 * Enable this filter to track tool, resource, and prompt registrations
		 * for debugging or monitoring purposes.
		 *
		 * @since 0.3.0
		 *
		 * @param bool      $should_record Whether to record component registration events. Default false.
		 * @param string    $server_id     The server ID for which components are being registered.
		 * @param \WP\MCP\Core\McpServer $server        The McpServer instance owning the registry.
		 */
		$this->should_record_component_registration = apply_filters(
			'mcp_adapter_observability_record_component_registration',
			false,
			$this->mcp_server->get_server_id(),
			$this->mcp_server
		);
	}

	/**
	 * Register tools to the server.
	 *
	 * @param list<string|\WP\MCP\Domain\Tools\McpTool> $tools Array of ability names (strings) or McpTool instances.
	 *
	 * @return void
	 */
	public function register_tools( array $tools ): void {
		foreach ( $tools as $tool_item ) {
			$this->register_single_tool( $tool_item );
		}
	}

	/**
	 * Register a single tool to the server.
	 *
	 * @param string|\WP\MCP\Domain\Tools\McpTool $tool_item The tool to register.
	 *
	 * @return void
	 */
	private function register_single_tool( $tool_item ): void {
		// Case 0: McpTool instance.
		if ( $tool_item instanceof McpTool ) {
			$this->add_mcp_tool( $tool_item );

			/** @var \WP\McpSchema\Server\Tools\DTO\Tool $tool_dto */
			$tool_dto = $tool_item->get_protocol_dto();
			$this->track_registration( 'tool', $tool_dto->getName(), 'success' );

			return;
		}

		// Case 1: String - treat as ability name.
		if ( is_string( $tool_item ) ) {
			$this->register_ability_tool( $tool_item );

			return;
		}

		$this->error_handler->log(
			sprintf(
				'Invalid tool registration item: expected McpTool instance or string ability name, got %s.',
				is_object( $tool_item ) ? get_class( $tool_item ) : gettype( $tool_item )
			),
			array( 'McpComponentRegistry::register_single_tool' ),
			'warning'
		);
	}

	/**
	 * Register an McpTool directly.
	 *
	 * @param \WP\MCP\Domain\Tools\McpTool $mcp_tool McpTool instance.
	 *
	 * @return void
	 * @since 0.3.0
	 *
	 */
	private function add_mcp_tool( McpTool $mcp_tool ): void {
		/** @var \WP\McpSchema\Server\Tools\DTO\Tool $tool_dto */
		$tool_dto  = $mcp_tool->get_protocol_dto();
		$tool_name = $tool_dto->getName();

		if ( isset( $this->mcp_tools[ $tool_name ] ) ) {
			$this->error_handler->log(
				"Tool with name '{$tool_name}' already registered, skipping duplicate.",
				array( 'McpComponentRegistry::add_mcp_tool' ),
				'warning'
			);

			return;
		}

		$this->mcp_tools[ $tool_name ] = $mcp_tool;
	}

	/**
	 * Record a component registration event.
	 *
	 * @param string $type Component type.
	 * @param string $name Component name.
	 * @param string $status Registration status ('success' or 'failed').
	 * @param array<string, mixed> $extra Extra event data.
	 *
	 * @return void
	 */
	private function track_registration( string $type, string $name, string $status, array $extra = array() ): void {
		if ( ! $this->should_record_component_registration ) {
			return;
		}

		$event_data = array_merge(
			array(
				'status'         => $status,
				'component_type' => $type,
				'component_name' => $name,
				'server_id'      => $this->mcp_server->get_server_id(),
			),
			$extra
		);

		$this->observability_handler->record_event( 'mcp.component.registration', $event_data );
	}

	/**
	 * Register a tool from an ability name.
	 *
	 * @param string $ability_name Ability name.
	 *
	 * @return void
	 */
	private function register_ability_tool( string $ability_name ): void {
		$ability = \wp_get_ability( $ability_name );

		if ( ! $ability ) {
			$this->error_handler->log( "WordPress ability '{$ability_name}' does not exist.", array( "RegisterAbilityAsMcpTool::{$ability_name}" ) );
			$this->track_registration( 'ability_tool', $ability_name, 'failed', array( 'failure_reason' => FailureReason::ABILITY_NOT_FOUND ) );

			return;
		}

		$mcp_tool = McpTool::fromAbility( $ability );

		if ( is_wp_error( $mcp_tool ) ) {
			$this->error_handler->log( $mcp_tool->get_error_message(), array( "McpTool::fromAbility::{$ability_name}" ) );
			$this->track_registration(
				'ability_tool',
				$ability_name,
				'failed',
				array( 'error_code' => $mcp_tool->get_error_code() )
			);

			return;
		}

		$this->add_mcp_tool( $mcp_tool );
		$this->track_registration( 'ability_tool', $ability_name, 'success' );
	}

	/**
	 * Register resources to the server.
	 *
	 * @param list<string|\WP\MCP\Domain\Resources\McpResource> $resources Array of ability names or McpResource instances.
	 *
	 * @return void
	 */
	public function register_resources( array $resources ): void {
		foreach ( $resources as $resource_item ) {
			$this->register_single_resource( $resource_item );
		}
	}

	/**
	 * Register a single resource to the server.
	 *
	 * @param string|\WP\MCP\Domain\Resources\McpResource $resource_item The resource to register.
	 *
	 * @return void
	 */
	private function register_single_resource( $resource_item ): void {
		// Case 0: McpResource instance.
		if ( $resource_item instanceof McpResource ) {
			$this->add_mcp_resource( $resource_item );

			/** @var \WP\McpSchema\Server\Resources\DTO\Resource $resource_dto */
			$resource_dto = $resource_item->get_protocol_dto();
			$this->track_registration( 'resource', $resource_dto->getUri(), 'success' );

			return;
		}

		// Case 1: String - treat as ability name.
		if ( is_string( $resource_item ) ) {
			$this->register_ability_resource( $resource_item );

			return;
		}

		// Case 2: Invalid type.
		$this->error_handler->log(
			sprintf(
				'Invalid resource registration item: expected McpResource instance or string ability name, got %s.',
				is_object( $resource_item ) ? get_class( $resource_item ) : gettype( $resource_item )
			),
			array( 'McpComponentRegistry::register_single_resource' ),
			'warning'
		);
	}

	/**
	 * Register an McpResource directly.
	 *
	 * @param \WP\MCP\Domain\Resources\McpResource $mcp_resource McpResource instance.
	 *
	 * @return bool True if the resource was added, false if it was a duplicate.
	 * @since 0.3.0
	 *
	 */
	private function add_mcp_resource( McpResource $mcp_resource ): bool {
		/** @var \WP\McpSchema\Server\Resources\DTO\Resource $resource_dto */
		$resource_dto = $mcp_resource->get_protocol_dto();
		$uri          = $resource_dto->getUri();

		if ( isset( $this->mcp_resources[ $uri ] ) ) {
			$this->error_handler->log(
				"Resource with URI '{$uri}' already registered, skipping duplicate.",
				array( 'McpComponentRegistry::add_mcp_resource' ),
				'warning'
			);

			return false;
		}

		$this->mcp_resources[ $uri ] = $mcp_resource;

		return true;
	}

	/**
	 * Register an ability-backed resource by ability name.
	 *
	 * @param string $ability_name Ability name.
	 *
	 * @return void
	 */
	private function register_ability_resource( string $ability_name ): void {
		$ability = \wp_get_ability( $ability_name );

		if ( ! $ability ) {
			$this->error_handler->log( "WordPress ability '{$ability_name}' does not exist.", array( "RegisterAbilityAsMcpResource::{$ability_name}" ) );

			$this->track_registration( 'resource', $ability_name, 'failed', array( 'failure_reason' => FailureReason::ABILITY_NOT_FOUND ) );

			return;
		}

		$mcp_resource = McpResource::fromAbility( $ability, $this->error_handler );

		// Check if resource creation returned an error.
		if ( is_wp_error( $mcp_resource ) ) {
			$this->error_handler->log( $mcp_resource->get_error_message(), array( "McpResource::fromAbility::{$ability_name}" ) );

			$this->track_registration(
				'resource',
				$ability_name,
				'failed',
				array( 'error_code' => $mcp_resource->get_error_code() )
			);

			return;
		}

		$added = $this->add_mcp_resource( $mcp_resource );

		if ( $added ) {
			$this->track_registration( 'resource', $ability_name, 'success' );
		} else {
			/** @var \WP\McpSchema\Server\Resources\DTO\Resource $resource_dto */
			$resource_dto = $mcp_resource->get_protocol_dto();
			$this->track_registration(
				'resource',
				$ability_name,
				'failed',
				array(
					'failure_reason' => FailureReason::DUPLICATE_URI,
					'duplicate_uri'  => $resource_dto->getUri(),
				)
			);
		}
	}

	/**
	 * Register prompts to the server.
	 *
	 * Accepts multiple formats:
	 * - McpPrompt instances
	 * - Class name string implementing McpPromptBuilderInterface (instantiated automatically)
	 * - Ability name string (converted via RegisterAbilityAsMcpPrompt)
	 * - McpPromptBuilderInterface instance (fluent API or custom builders)
	 * - Array configuration (converted via McpPrompt::fromArray())
	 *
	 * @param list<string|\WP\MCP\Domain\Prompts\McpPrompt|\WP\MCP\Domain\Prompts\Contracts\McpPromptBuilderInterface> $prompts Array of prompts to register.
	 *
	 * @return void
	 */
	public function register_prompts( array $prompts ): void {
		foreach ( $prompts as $prompt_item ) {
			$this->register_single_prompt( $prompt_item );
		}
	}

	/**
	 * Register a single prompt to the server.
	 *
	 * @param string|\WP\MCP\Domain\Prompts\McpPrompt|\WP\MCP\Domain\Prompts\Contracts\McpPromptBuilderInterface $prompt_item The prompt to register.
	 *
	 * @return void
	 */
	private function register_single_prompt( $prompt_item ): void {
		// Case 0: McpPrompt instance.
		if ( $prompt_item instanceof McpPrompt ) {
			$this->add_mcp_prompt( $prompt_item );

			/** @var \WP\McpSchema\Server\Prompts\DTO\Prompt $prompt_dto */
			$prompt_dto = $prompt_item->get_protocol_dto();
			$this->track_registration( 'prompt', $prompt_dto->getName(), 'success' );

			return;
		}

		// Case 1: McpPromptBuilderInterface instance (fluent API or custom builder).
		if ( $prompt_item instanceof McpPromptBuilderInterface ) {
			$this->register_builder_instance( $prompt_item );

			return;
		}

		// Case 2: String - either a class name or ability name.
		if ( is_string( $prompt_item ) ) {
			// Check if it's a class that implements McpPromptBuilderInterface.
			if ( class_exists( $prompt_item ) && in_array( McpPromptBuilderInterface::class, class_implements( $prompt_item ) ?: array(), true ) ) {
				$this->register_builder_class( $prompt_item );

				return;
			}

			// Treat as ability name.
			$this->register_ability_prompt( $prompt_item );

			return;
		}

		// Case 3: Invalid type.
		$this->error_handler->log(
			sprintf(
				'Invalid prompt registration item: expected McpPrompt, McpPromptBuilderInterface, or string, got %s.',
				is_object( $prompt_item ) ? get_class( $prompt_item ) : gettype( $prompt_item )
			),
			array( 'McpComponentRegistry::register_single_prompt' ),
			'warning'
		);
	}

	/**
	 * Add an McpPrompt to the registry.
	 *
	 * @param \WP\MCP\Domain\Prompts\McpPrompt $mcp_prompt McpPrompt instance.
	 *
	 * @return void
	 * @since 0.3.0
	 *
	 */
	private function add_mcp_prompt( McpPrompt $mcp_prompt ): void {
		/** @var \WP\McpSchema\Server\Prompts\DTO\Prompt $prompt */
		$prompt      = $mcp_prompt->get_protocol_dto();
		$prompt_name = $prompt->getName();

		if ( isset( $this->mcp_prompts[ $prompt_name ] ) ) {
			$this->error_handler->log(
				"Prompt with name '{$prompt_name}' already registered, skipping duplicate.",
				array( 'McpComponentRegistry::add_mcp_prompt' ),
				'warning'
			);

			return;
		}

		$this->mcp_prompts[ $prompt_name ] = $mcp_prompt;
	}

	/**
	 * Register a McpPromptBuilderInterface instance.
	 *
	 * @param \WP\MCP\Domain\Prompts\Contracts\McpPromptBuilderInterface $builder The builder instance.
	 *
	 * @return void
	 */
	private function register_builder_instance( McpPromptBuilderInterface $builder ): void {
		$prompt_name = $builder->get_name();

		$mcp_prompt = McpPrompt::fromBuilder( $builder );
		if ( $mcp_prompt instanceof WP_Error ) {
			$this->error_handler->log( $mcp_prompt->get_error_message(), array( "McpPrompt::fromBuilder::{$prompt_name}" ) );

			$this->track_registration(
				'prompt',
				$prompt_name,
				'failed',
				array( 'error_code' => $mcp_prompt->get_error_code() )
			);

			return;
		}

		$this->add_mcp_prompt( $mcp_prompt );

		$this->track_registration( 'prompt', $prompt_name, 'success' );
	}

	/**
	 * Register a prompt from a builder class name.
	 *
	 * @param string $class_name The fully-qualified class name.
	 *
	 * @return void
	 */
	private function register_builder_class( string $class_name ): void {
		try {
			/** @var \WP\MCP\Domain\Prompts\Contracts\McpPromptBuilderInterface $builder */
			$builder = new $class_name();
			$this->register_builder_instance( $builder );
		} catch ( \Throwable $e ) {
			$this->error_handler->log( "Failed to build prompt from class '{$class_name}': {$e->getMessage()}", array( "McpPromptBuilder::{$class_name}" ) );

			$this->track_registration( 'prompt', $class_name, 'failed', array( 'failure_reason' => FailureReason::BUILDER_EXCEPTION ) );
		}
	}

	/**
	 * Register a prompt from an ability name.
	 *
	 * @param string $ability_name The ability name.
	 *
	 * @return void
	 */
	private function register_ability_prompt( string $ability_name ): void {
		$ability = \wp_get_ability( $ability_name );

		if ( ! $ability ) {
			$this->error_handler->log( "WordPress ability '{$ability_name}' does not exist.", array( "RegisterAbilityAsMcpPrompt::{$ability_name}" ) );

			$this->track_registration( 'prompt', $ability_name, 'failed', array( 'failure_reason' => FailureReason::ABILITY_NOT_FOUND ) );

			return;
		}

		$mcp_prompt = McpPrompt::fromAbility( $ability );

		if ( is_wp_error( $mcp_prompt ) ) {
			$this->error_handler->log( $mcp_prompt->get_error_message(), array( "McpPrompt::fromAbility::{$ability_name}" ) );

			$this->track_registration(
				'prompt',
				$ability_name,
				'failed',
				array( 'error_code' => $mcp_prompt->get_error_code() )
			);

			return;
		}

		$this->add_mcp_prompt( $mcp_prompt );

		$this->track_registration( 'prompt', $ability_name, 'success' );
	}

	/**
	 * Get all tools registered to the server.
	 *
	 * @return array<string, \WP\McpSchema\Server\Tools\DTO\Tool>
	 */
	public function get_tools(): array {
		return array_map(
			static fn( McpTool $mcp_tool ): ToolDto => $mcp_tool->get_protocol_dto(),
			$this->mcp_tools
		);
	}

	/**
	 * Get all resources registered to the server.
	 *
	 * @return array<string, \WP\McpSchema\Server\Resources\DTO\Resource>
	 */
	public function get_resources(): array {
		return array_map(
			static fn( McpResource $mcp_resource ): ResourceDto => $mcp_resource->get_protocol_dto(),
			$this->mcp_resources
		);
	}

	/**
	 * Get all prompts registered to the server.
	 *
	 * @return array<string, \WP\McpSchema\Server\Prompts\DTO\Prompt>
	 */
	public function get_prompts(): array {
		return array_map(
			static fn( McpPrompt $mcp_prompt ): PromptDto => $mcp_prompt->get_protocol_dto(),
			$this->mcp_prompts
		);
	}

	/**
	 * Get a specific McpTool by tool name.
	 *
	 * @param string $tool_name Tool name.
	 *
	 * @return \WP\MCP\Domain\Tools\McpTool|null
	 * @since 0.3.0
	 *
	 */
	public function get_mcp_tool( string $tool_name ): ?McpTool {
		return $this->mcp_tools[ $tool_name ] ?? null;
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
		return $this->mcp_resources[ $resource_uri ] ?? null;
	}

	/**
	 * Get an McpPrompt by prompt name.
	 *
	 * @param string $prompt_name Prompt name.
	 *
	 * @return \WP\MCP\Domain\Prompts\McpPrompt|null
	 * @internal
	 * @since 0.3.0
	 *
	 */
	public function get_mcp_prompt( string $prompt_name ): ?McpPrompt {
		return $this->mcp_prompts[ $prompt_name ] ?? null;
	}

	/**
	 * Get a prompt builder instance by prompt name (builder-based prompts).
	 *
	 * @param string $prompt_name Prompt name.
	 *
	 * @return \WP\MCP\Domain\Prompts\Contracts\McpPromptBuilderInterface|null
	 */
	public function get_prompt_builder( string $prompt_name ): ?McpPromptBuilderInterface {
		$mcp_prompt = $this->mcp_prompts[ $prompt_name ] ?? null;

		return $mcp_prompt ? $mcp_prompt->get_builder() : null;
	}
}
