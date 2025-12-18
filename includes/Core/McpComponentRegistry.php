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
use WP\MCP\Domain\Prompts\RegisterAbilityAsMcpPrompt;
use WP\MCP\Domain\Resources\RegisterAbilityAsMcpResource;
use WP\MCP\Domain\Resources\ResourceMetadataHelper;
use WP\MCP\Domain\Tools\RegisterAbilityAsMcpTool;
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
	 * Tools registered to the server.
	 *
	 * @var array<string, \WP\McpSchema\Server\Tools\Tool>
	 */
	private array $tools = array();

	/**
	 * Resources registered to the server.
	 *
	 * @var array<string, \WP\McpSchema\Server\Resources\Resource>
	 */
	private array $resources = array();

	/**
	 * Prompts registered to the server.
	 *
	 * @var array<string, \WP\McpSchema\Server\Prompts\Prompt>
	 */
	private array $prompts = array();

	/**
	 * Prompt builder instances keyed by prompt name (builder-based prompts).
	 *
	 * @var array<string, \WP\MCP\Domain\Prompts\Contracts\McpPromptBuilderInterface>
	 */
	private array $prompt_builders = array();

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
	 * @param array $tools Array of ability names (strings) to convert to MCP tools.
	 *
	 * @return void
	 */
	public function register_tools( array $tools ): void {
		foreach ( $tools as $tool_item ) {
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

			$tool = RegisterAbilityAsMcpTool::make( $ability );

			// Check if tool creation returned an error
			if ( is_wp_error( $tool ) ) {
				$this->error_handler->log( $tool->get_error_message(), array( "RegisterAbilityAsMcpTool::{$tool_item}" ) );

				// Track ability tool registration failure.
				if ( $this->should_record_component_registration ) {
					$this->observability_handler->record_event(
						'mcp.component.registration',
						array(
							'status'         => 'failed',
							'component_type' => 'ability_tool',
							'component_name' => $tool_item,
							'error_code'     => $tool->get_error_code(),
							'server_id'      => $this->mcp_server->get_server_id(),
						)
					);
				}
				continue;
			}

			// Add the processed tools to this server.
			$this->tools[ $tool->getName() ] = $tool;

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
	 * Register a Tool DTO instance directly to the server.
	 *
	 * @param \WP\McpSchema\Server\Tools\Tool $tool The tool DTO to register.
	 *
	 * @return void
	 */
	public function add_tool( Tool $tool ): void {
		// Add the tool to this server.
		$this->tools[ $tool->getName() ] = $tool;

		// Track successful tool registration
		if ( ! $this->should_record_component_registration ) {
			return;
		}

		$this->observability_handler->record_event(
			'mcp.component.registration',
			array(
				'status'         => 'success',
				'component_type' => 'tool',
				'component_name' => $tool->getName(),
				'server_id'      => $this->mcp_server->get_server_id(),
			)
		);
	}

	/**
	 * Register resources to the server.
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
				continue;
			}

			$resource = RegisterAbilityAsMcpResource::make( $ability, $this->error_handler );

			// Check if resource creation returned an error
			if ( is_wp_error( $resource ) ) {
				$this->error_handler->log( $resource->get_error_message(), array( "RegisterAbilityAsMcpResource::{$ability_name}" ) );

				// Track resource registration failure.
				if ( $this->should_record_component_registration ) {
					$this->observability_handler->record_event(
						'mcp.component.registration',
						array(
							'status'         => 'failed',
							'component_type' => 'resource',
							'component_name' => $ability_name,
							'error_code'     => $resource->get_error_code(),
							'server_id'      => $this->mcp_server->get_server_id(),
						)
					);
				}
				continue;
			}

			// Check for duplicate URI (first-wins policy).
			$resource_uri = $resource->getUri();
			if ( isset( $this->resources[ $resource_uri ] ) ) {
				// Get the ability name of the already-registered resource.
				$existing_resource     = $this->resources[ $resource_uri ];
				$existing_ability_name = ResourceMetadataHelper::get_ability_name( $existing_resource ) ?? '(unknown)';

				$this->error_handler->log(
					sprintf(
						"Duplicate resource URI '%s': ability '%s' conflicts with already-registered ability '%s'. First registration wins.",
						$resource_uri,
						$ability_name,
						$existing_ability_name
					),
					array( "RegisterAbilityAsMcpResource::{$ability_name}" )
				);

				// Track duplicate URI failure.
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
				continue;
			}

			// Add the processed resources to this server.
			$this->resources[ $resource_uri ] = $resource;

			// Track successful resource registration.
			if ( ! $this->should_record_component_registration ) {
				continue;
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
	}

	/**
	 * Register prompts to the server.
	 *
	 * Accepts multiple formats:
	 * - Class name string implementing McpPromptBuilderInterface (instantiated automatically)
	 * - Ability name string (converted via RegisterAbilityAsMcpPrompt)
	 * - McpPromptBuilderInterface instance (fluent API or custom builders)
	 * - Array configuration (converted via McpPrompt::fromArray())
	 *
	 * @param array<int, string|\WP\MCP\Domain\Prompts\Contracts\McpPromptBuilderInterface|array<string, mixed>> $prompts Array of prompts to register.
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
	 * @param string|\WP\MCP\Domain\Prompts\Contracts\McpPromptBuilderInterface|array<string, mixed> $prompt_item The prompt to register.
	 *
	 * @return void
	 */
	private function register_single_prompt( $prompt_item ): void {
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

		try {
			$prompt = $builder->build();
		} catch ( \Throwable $e ) {
			$this->error_handler->log( "Failed to build prompt '{$prompt_name}': {$e->getMessage()}", array( "McpPrompt::{$prompt_name}" ) );

			if ( $this->should_record_component_registration ) {
				$this->observability_handler->record_event(
					'mcp.component.registration',
					array(
						'status'         => 'failed',
						'component_type' => 'prompt',
						'component_name' => $prompt_name,
						'failure_reason' => 'builder_exception',
						'server_id'      => $this->mcp_server->get_server_id(),
					)
				);
			}
			return;
		}

		$this->prompts[ $prompt->getName() ]         = $prompt;
		$this->prompt_builders[ $prompt->getName() ] = $builder;

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
			$builder = McpPrompt::fromArray( $config );
			$this->register_builder_instance( $builder );
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

		$prompt = RegisterAbilityAsMcpPrompt::make( $ability );

		if ( is_wp_error( $prompt ) ) {
			$this->error_handler->log( $prompt->get_error_message(), array( "RegisterAbilityAsMcpPrompt::{$ability_name}" ) );

			if ( $this->should_record_component_registration ) {
				$this->observability_handler->record_event(
					'mcp.component.registration',
					array(
						'status'         => 'failed',
						'component_type' => 'prompt',
						'component_name' => $ability_name,
						'error_code'     => $prompt->get_error_code(),
						'server_id'      => $this->mcp_server->get_server_id(),
					)
				);
			}
			return;
		}

		$this->prompts[ $prompt->getName() ] = $prompt;

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
		return $this->tools;
	}

	/**
	 * Get all resources registered to the server.
	 *
	 * @return array<string, \WP\McpSchema\Server\Resources\Resource>
	 */
	public function get_resources(): array {
		return $this->resources;
	}

	/**
	 * Get all prompts registered to the server.
	 *
	 * @return array<string, \WP\McpSchema\Server\Prompts\Prompt>
	 */
	public function get_prompts(): array {
		return $this->prompts;
	}

	/**
	 * Get a specific tool by name.
	 *
	 * @param string $tool_name Tool name.
	 *
	 * @return \WP\McpSchema\Server\Tools\Tool|null
	 */
	public function get_tool( string $tool_name ): ?Tool {
		return $this->tools[ $tool_name ] ?? null;
	}

	/**
	 * Get a specific resource by URI.
	 *
	 * @param string $resource_uri Resource URI.
	 *
	 * @return \WP\McpSchema\Server\Resources\Resource|null
	 */
	public function get_resource( string $resource_uri ): ?Resource {
		return $this->resources[ $resource_uri ] ?? null;
	}

	/**
	 * Get a specific prompt by name.
	 *
	 * @param string $prompt_name Prompt name.
	 *
	 * @return \WP\McpSchema\Server\Prompts\Prompt|null
	 */
	public function get_prompt( string $prompt_name ): ?Prompt {
		return $this->prompts[ $prompt_name ] ?? null;
	}

	/**
	 * Get a prompt builder instance by prompt name (builder-based prompts).
	 *
	 * @param string $prompt_name Prompt name.
	 *
	 * @return \WP\MCP\Domain\Prompts\Contracts\McpPromptBuilderInterface|null
	 */
	public function get_prompt_builder( string $prompt_name ): ?McpPromptBuilderInterface {
		return $this->prompt_builders[ $prompt_name ] ?? null;
	}
}
