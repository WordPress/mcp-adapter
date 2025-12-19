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
use WP\McpSchema\Server\Prompts\Prompt;
use WP\McpSchema\Server\Resources\Resource;
use WP\McpSchema\Server\Tools\Tool;

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

		// Allow filtering whether component registration events should be recorded.
		// Default is false to avoid polluting observability logs during startup.
		$this->should_record_component_registration = apply_filters( 'mcp_adapter_observability_record_component_registration', false );
	}

	/**
	 * Register tools to the server.
	 *
	 * @param array<int, string|\WP\MCP\Domain\Tools\McpTool> $tools Array of ability names (strings) or McpTool instances.
	 *
	 * @return void
	 */
	public function register_tools( array $tools ): void {
		foreach ( $tools as $tool_item ) {
			if ( $tool_item instanceof McpTool ) {
				$this->add_mcp_tool( $tool_item );
				continue;
			}

			if ( ! is_string( $tool_item ) ) {
				continue;
			}

			// Treat as ability name
			$ability = \wp_get_ability( $tool_item );

			if ( ! $ability ) {
				$this->error_handler->log( "WordPress ability '{$tool_item}' does not exist.", array( "RegisterAbilityAsMcpTool::{$tool_item}" ) );

				// Track ability tool registration failure.
				if ( $this->should_record_component_registration ) {
					$this->observability_handler->record_event(
						'mcp.component.registration',
						array(
							'status'         => 'failed',
							'component_type' => 'ability_tool',
							'component_name' => $tool_item,
							'failure_reason' => 'ability_not_found',
							'server_id'      => $this->mcp_server->get_server_id(),
						)
					);
				}
				continue;
			}

			$mcp_tool = McpTool::fromAbility( $ability );

			// Check if tool creation returned an error.
			if ( is_wp_error( $mcp_tool ) ) {
				$this->error_handler->log( $mcp_tool->get_error_message(), array( "McpTool::fromAbility::{$tool_item}" ) );

				// Track ability tool registration failure.
				if ( $this->should_record_component_registration ) {
					$this->observability_handler->record_event(
						'mcp.component.registration',
						array(
							'status'         => 'failed',
							'component_type' => 'ability_tool',
							'component_name' => $tool_item,
							'error_code'     => $mcp_tool->get_error_code(),
							'server_id'      => $this->mcp_server->get_server_id(),
						)
					);
				}
				continue;
			}

			// Add the processed McpTool to this server.
			/** @var \WP\McpSchema\Server\Tools\Tool $tool */
			$tool                                = $mcp_tool->get_component();
			$this->mcp_tools[ $tool->getName() ] = $mcp_tool;

			// Track successful ability tool registration.
			if ( ! $this->should_record_component_registration ) {
				continue;
			}

			$this->observability_handler->record_event(
				'mcp.component.registration',
				array(
					'status'         => 'success',
					'component_type' => 'ability_tool',
					'component_name' => $tool_item,
					'server_id'      => $this->mcp_server->get_server_id(),
				)
			);
		}
	}

	/**
	 * Register an McpTool directly.
	 *
	 * @since n.e.x.t
	 *
	 * @param \WP\MCP\Domain\Tools\McpTool $mcp_tool McpTool instance.
	 *
	 * @return void
	 */
	public function add_mcp_tool( McpTool $mcp_tool ): void {
		/** @var \WP\McpSchema\Server\Tools\Tool $tool_dto */
		$tool_dto = $mcp_tool->get_component();

		$this->mcp_tools[ $tool_dto->getName() ] = $mcp_tool;
	}

		/**
		 * Register resources to the server.
		 *
		 * @param array<int, string|\WP\MCP\Domain\Resources\McpResource> $resources Array of ability names or McpResource instances.
		 *
		 * @return void
		 */
	public function register_resources( array $resources ): void {
		foreach ( $resources as $resource_item ) {
			// Case 0: McpResource instance.
			if ( $resource_item instanceof McpResource ) {
				$this->add_mcp_resource( $resource_item );
				continue;
			}

			// Case 1: Ability name string (legacy behavior).
			if ( ! is_string( $resource_item ) ) {
				continue;
			}

			$this->register_ability_resource( $resource_item );
		}
	}

	/**
	 * Register an McpResource directly.
	 *
	 * @since n.e.x.t
	 *
	 * @param \WP\MCP\Domain\Resources\McpResource $mcp_resource McpResource instance.
	 *
	 * @return void
	 */
	public function add_mcp_resource( McpResource $mcp_resource ): void {
		/** @var \WP\McpSchema\Server\Resources\Resource $resource_dto */
		$resource_dto = $mcp_resource->get_component();
		$uri          = $resource_dto->getUri();

		if ( isset( $this->mcp_resources[ $uri ] ) ) {
			$this->error_handler->log(
				"Resource with URI '{$uri}' already registered, skipping duplicate.",
				array( 'McpComponentRegistry::add_mcp_resource' ),
				'warning'
			);
			return;
		}

		$this->mcp_resources[ $uri ] = $mcp_resource;
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

			// Track resource registration failure.
			if ( $this->should_record_component_registration ) {
				$this->observability_handler->record_event(
					'mcp.component.registration',
					array(
						'status'         => 'failed',
						'component_type' => 'resource',
						'component_name' => $ability_name,
						'failure_reason' => 'ability_not_found',
						'server_id'      => $this->mcp_server->get_server_id(),
					)
				);
			}
			return;
		}

		$mcp_resource = McpResource::fromAbility( $ability, $this->error_handler );

		// Check if resource creation returned an error.
		if ( is_wp_error( $mcp_resource ) ) {
			$this->error_handler->log( $mcp_resource->get_error_message(), array( "McpResource::fromAbility::{$ability_name}" ) );

			// Track resource registration failure.
			if ( $this->should_record_component_registration ) {
				$this->observability_handler->record_event(
					'mcp.component.registration',
					array(
						'status'         => 'failed',
						'component_type' => 'resource',
						'component_name' => $ability_name,
						'error_code'     => $mcp_resource->get_error_code(),
						'server_id'      => $this->mcp_server->get_server_id(),
					)
				);
			}
			return;
		}

		/** @var \WP\McpSchema\Server\Resources\Resource $resource */
		$resource = $mcp_resource->get_component();

		$resource_uri = $resource->getUri();
		if ( isset( $this->mcp_resources[ $resource_uri ] ) ) {
			$existing_mcp_resource = $this->mcp_resources[ $resource_uri ];
			$existing_ability_name = '(unknown)';

			if ( $existing_mcp_resource instanceof McpResource ) {
				$existing_meta = $existing_mcp_resource->get_adapter_meta();
				$ability_key   = $existing_meta['ability'] ?? null;
				if ( is_string( $ability_key ) && '' !== trim( $ability_key ) ) {
					$existing_ability_name = $ability_key;
				}
			}

			$this->error_handler->log(
				sprintf(
					"Duplicate resource URI '%s': ability '%s' conflicts with already-registered ability '%s'. First registration wins.",
					$resource_uri,
					$ability_name,
					$existing_ability_name
				),
				array( "RegisterAbilityAsMcpResource::{$ability_name}" )
			);

			if ( $this->should_record_component_registration ) {
				$this->observability_handler->record_event(
					'mcp.component.registration',
					array(
						'status'                => 'failed',
						'component_type'        => 'resource',
						'component_name'        => $ability_name,
						'failure_reason'        => 'duplicate_uri',
						'duplicate_uri'         => $resource_uri,
						'existing_ability_name' => $existing_ability_name,
						'server_id'             => $this->mcp_server->get_server_id(),
					)
				);
			}

			return;
		}

		$this->mcp_resources[ $resource_uri ] = $mcp_resource;

		if ( ! $this->should_record_component_registration ) {
			return;
		}

		$this->observability_handler->record_event(
			'mcp.component.registration',
			array(
				'status'         => 'success',
				'component_type' => 'resource',
				'component_name' => $ability_name,
				'server_id'      => $this->mcp_server->get_server_id(),
			)
		);
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
		 * @param array<int, string|\WP\MCP\Domain\Prompts\McpPrompt|\WP\MCP\Domain\Prompts\Contracts\McpPromptBuilderInterface|array<string, mixed>> $prompts Array of prompts to register.
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
		 * @param string|\WP\MCP\Domain\Prompts\McpPrompt|\WP\MCP\Domain\Prompts\Contracts\McpPromptBuilderInterface|array<string, mixed> $prompt_item The prompt to register.
		 *
		 * @return void
		 */
	private function register_single_prompt( $prompt_item ): void {
		// Case 0: McpPrompt instance.
		if ( $prompt_item instanceof McpPrompt ) {
			$this->add_mcp_prompt( $prompt_item );
			return;
		}

		// Case 1: McpPromptBuilderInterface instance (fluent API or custom builder).
		if ( $prompt_item instanceof McpPromptBuilderInterface ) {
			$this->register_builder_instance( $prompt_item );
			return;
		}

		// Case 2: Array configuration - convert to McpPrompt.
		if ( is_array( $prompt_item ) ) {
			$this->register_array_config( $prompt_item );
			return;
		}

		// Case 3: String - either a class name or ability name.
		if ( ! is_string( $prompt_item ) ) {
			return;
		}

		// Check if it's a class that implements McpPromptBuilderInterface.
		if ( class_exists( $prompt_item ) && in_array( McpPromptBuilderInterface::class, class_implements( $prompt_item ) ?: array(), true ) ) {
			$this->register_builder_class( $prompt_item );
			return;
		}

		// Treat as ability name (legacy behavior).
		$this->register_ability_prompt( $prompt_item );
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
		if ( $mcp_prompt instanceof \WP_Error ) {
			$this->error_handler->log( $mcp_prompt->get_error_message(), array( "McpPrompt::fromBuilder::{$prompt_name}" ) );

			if ( $this->should_record_component_registration ) {
				$this->observability_handler->record_event(
					'mcp.component.registration',
					array(
						'status'         => 'failed',
						'component_type' => 'prompt',
						'component_name' => $prompt_name,
						'error_code'     => $mcp_prompt->get_error_code(),
						'server_id'      => $this->mcp_server->get_server_id(),
					)
				);
			}
			return;
		}

		$this->add_mcp_prompt( $mcp_prompt );

		if ( ! $this->should_record_component_registration ) {
			return;
		}

		$this->observability_handler->record_event(
			'mcp.component.registration',
			array(
				'status'         => 'success',
				'component_type' => 'prompt',
				'component_name' => $prompt_name,
				'server_id'      => $this->mcp_server->get_server_id(),
			)
		);
	}

	/**
	 * Register a prompt from array configuration.
	 *
	 * @param array<string, mixed> $config The prompt configuration array.
	 *
	 * @return void
	 */
	private function register_array_config( array $config ): void {
		$prompt_name = $config['name'] ?? 'unknown';

		try {
			/** @var array{name: string, title?: string, description?: string, arguments?: array<int, array{name: string, title?: string, description?: string, required?: bool}>, icons?: array<int, array{src: string, mimeType?: string, sizes?: array<string>, theme?: string}>, meta?: array<string, mixed>, handler: callable(array<string, mixed>): array<string, mixed>, permission?: callable(array<string, mixed>): bool} $config */
			$mcp_prompt = McpPrompt::fromArray( $config );
			$this->add_mcp_prompt( $mcp_prompt );

			if ( $this->should_record_component_registration ) {
				$this->observability_handler->record_event(
					'mcp.component.registration',
					array(
						'status'         => 'success',
						'component_type' => 'prompt',
						'component_name' => $prompt_name,
						'server_id'      => $this->mcp_server->get_server_id(),
					)
				);
			}
		} catch ( \Throwable $e ) {
			$this->error_handler->log( "Failed to create prompt from array config '{$prompt_name}': {$e->getMessage()}", array( "McpPrompt::fromArray::{$prompt_name}" ) );

			if ( $this->should_record_component_registration ) {
				$this->observability_handler->record_event(
					'mcp.component.registration',
					array(
						'status'         => 'failed',
						'component_type' => 'prompt',
						'component_name' => $prompt_name,
						'failure_reason' => 'array_config_exception',
						'server_id'      => $this->mcp_server->get_server_id(),
					)
				);
			}
		}
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

			if ( $this->should_record_component_registration ) {
				$this->observability_handler->record_event(
					'mcp.component.registration',
					array(
						'status'         => 'failed',
						'component_type' => 'prompt',
						'component_name' => $class_name,
						'failure_reason' => 'builder_exception',
						'server_id'      => $this->mcp_server->get_server_id(),
					)
				);
			}
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

			if ( $this->should_record_component_registration ) {
				$this->observability_handler->record_event(
					'mcp.component.registration',
					array(
						'status'         => 'failed',
						'component_type' => 'prompt',
						'component_name' => $ability_name,
						'failure_reason' => 'ability_not_found',
						'server_id'      => $this->mcp_server->get_server_id(),
					)
				);
			}
			return;
		}

		$mcp_prompt = McpPrompt::fromAbility( $ability );

		if ( is_wp_error( $mcp_prompt ) ) {
			$this->error_handler->log( $mcp_prompt->get_error_message(), array( "McpPrompt::fromAbility::{$ability_name}" ) );

			if ( $this->should_record_component_registration ) {
				$this->observability_handler->record_event(
					'mcp.component.registration',
					array(
						'status'         => 'failed',
						'component_type' => 'prompt',
						'component_name' => $ability_name,
						'error_code'     => $mcp_prompt->get_error_code(),
						'server_id'      => $this->mcp_server->get_server_id(),
					)
				);
			}
			return;
		}

		$this->add_mcp_prompt( $mcp_prompt );

		if ( ! $this->should_record_component_registration ) {
			return;
		}

		$this->observability_handler->record_event(
			'mcp.component.registration',
			array(
				'status'         => 'success',
				'component_type' => 'prompt',
				'component_name' => $ability_name,
				'server_id'      => $this->mcp_server->get_server_id(),
			)
		);
	}

	/**
	 * Get all tools registered to the server.
	 *
	 * @return array<string, \WP\McpSchema\Server\Tools\Tool>
	 */
	public function get_tools(): array {
		return array_map(
			static fn( McpTool $mcp_tool ): Tool => $mcp_tool->get_component(),
			$this->mcp_tools
		);
	}

	/**
	 * Get all resources registered to the server.
	 *
	 * @return array<string, \WP\McpSchema\Server\Resources\Resource>
	 */
	public function get_resources(): array {
		return array_map(
			static fn( McpResource $mcp_resource ): Resource => $mcp_resource->get_component(),
			$this->mcp_resources
		);
	}

	/**
	 * Get all prompts registered to the server.
	 *
	 * @return array<string, \WP\McpSchema\Server\Prompts\Prompt>
	 */
	public function get_prompts(): array {
		return array_map(
			static fn( McpPrompt $mcp_prompt ): Prompt => $mcp_prompt->get_component(),
			$this->mcp_prompts
		);
	}

	/**
	 * Get a specific tool by name.
	 *
	 * @param string $tool_name Tool name.
	 *
	 * @return \WP\McpSchema\Server\Tools\Tool|null
	 */
	public function get_tool( string $tool_name ): ?Tool {
		$mcp_tool = $this->mcp_tools[ $tool_name ] ?? null;
		return $mcp_tool ? $mcp_tool->get_component() : null;
	}

	/**
	 * Get a specific McpTool by tool name.
	 *
	 * @since n.e.x.t
	 *
	 * @param string $tool_name Tool name.
	 *
	 * @return \WP\MCP\Domain\Tools\McpTool|null
	 */
	public function get_mcp_tool( string $tool_name ): ?McpTool {
		return $this->mcp_tools[ $tool_name ] ?? null;
	}

	/**
	 * Get all MCP tools.
	 *
	 * @since n.e.x.t
	 *
	 * @return array<string, \WP\MCP\Domain\Tools\McpTool>
	 */
	public function get_mcp_tools(): array {
		return $this->mcp_tools;
	}

	/**
	 * Get a specific resource by URI.
	 *
	 * @param string $resource_uri Resource URI.
	 *
	 * @return \WP\McpSchema\Server\Resources\Resource|null
	 */
	public function get_resource( string $resource_uri ): ?Resource {
		$mcp_resource = $this->mcp_resources[ $resource_uri ] ?? null;
		return $mcp_resource ? $mcp_resource->get_component() : null;
	}

	/**
	 * Get a specific McpResource by URI.
	 *
	 * @internal
	 * @since n.e.x.t
	 *
	 * @param string $resource_uri Resource URI.
	 *
	 * @return \WP\MCP\Domain\Resources\McpResource|null
	 */
	public function get_mcp_resource( string $resource_uri ): ?McpResource {
		return $this->mcp_resources[ $resource_uri ] ?? null;
	}

	/**
	 * Get all MCP resources.
	 *
	 * @internal
	 * @since n.e.x.t
	 *
	 * @return array<string, \WP\MCP\Domain\Resources\McpResource>
	 */
	public function get_mcp_resources(): array {
		return $this->mcp_resources;
	}

	/**
	 * Get a specific prompt by name.
	 *
	 * @param string $prompt_name Prompt name.
	 *
	 * @return \WP\McpSchema\Server\Prompts\Prompt|null
	 */
	public function get_prompt( string $prompt_name ): ?Prompt {
		$mcp_prompt = $this->mcp_prompts[ $prompt_name ] ?? null;
		return $mcp_prompt ? $mcp_prompt->get_component() : null;
	}

	/**
	 * Get an McpPrompt by prompt name.
	 *
	 * @internal
	 * @since n.e.x.t
	 *
	 * @param string $prompt_name Prompt name.
	 *
	 * @return \WP\MCP\Domain\Prompts\McpPrompt|null
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

	/**
	 * Add an McpPrompt to the registry.
	 *
	 * @since n.e.x.t
	 *
	 * @param \WP\MCP\Domain\Prompts\McpPrompt $mcp_prompt McpPrompt instance.
	 *
	 * @return void
	 */
	private function add_mcp_prompt( McpPrompt $mcp_prompt ): void {
		/** @var \WP\McpSchema\Server\Prompts\Prompt $prompt */
		$prompt = $mcp_prompt->get_component();

		$this->mcp_prompts[ $prompt->getName() ] = $mcp_prompt;
	}
}
