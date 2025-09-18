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
use WP\MCP\Domain\Resources\McpResource;
use WP\MCP\Domain\Resources\RegisterAbilityAsMcpResource;
use WP\MCP\Domain\Tools\McpTool;
use WP\MCP\Domain\Tools\RegisterAbilityAsMcpTool;
use WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface;

/**
 * Registry for managing MCP server components (tools, resources, prompts).
 */
class McpComponentRegistry {
	/**
	 * Tools registered to the server.
	 *
	 * @var array
	 */
	private array $tools = array();

	/**
	 * Resources registered to the server.
	 *
	 * @var array
	 */
	private array $resources = array();

	/**
	 * Prompts registered to the server.
	 *
	 * @var array
	 */
	private array $prompts = array();

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
	 * Observability handler class name.
	 *
	 * @var class-string<\WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface>
	 */
	private string $observability_handler;

	/**
	 * Whether MCP validation is enabled.
	 *
	 * @var bool
	 */
	private bool $mcp_validation_enabled;

	/**
	 * Constructor.
	 *
	 * @param \WP\MCP\Core\McpServer $mcp_server MCP server instance.
	 * @param \WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface $error_handler Error handler instance.
	 * @param class-string<\WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface> $observability_handler Observability handler class name.
	 * @param bool                                                                       $mcp_validation_enabled Whether MCP validation is enabled.
	 */
	public function __construct(
		McpServer $mcp_server,
		McpErrorHandlerInterface $error_handler,
		string $observability_handler,
		bool $mcp_validation_enabled
	) {
		$this->mcp_server    = $mcp_server;
		$this->error_handler = $error_handler;
		/** @var class-string<\WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface> $observability_handler */
		$this->observability_handler  = $observability_handler;
		$this->mcp_validation_enabled = $mcp_validation_enabled;
	}

	/**
	 * Register tools to the server.
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
					throw new \InvalidArgumentException( "WordPress ability '{$ability_name}' does not exist." );
				}

				$tool = RegisterAbilityAsMcpTool::make( $ability, $this->mcp_server );
				// Add the processed tools to this server.
				$this->tools[ $tool->get_name() ] = $tool;

				// Track successful tool registration.
				$this->observability_handler::record_event(
					'mcp.component.registered',
					array(
						'component_type' => 'tool',
						'component_name' => $ability_name,
						'server_id'      => $this->mcp_server->get_server_id(),
					)
				);
			} catch ( \InvalidArgumentException $e ) {
				$this->error_handler->log( $e->getMessage(), array( "RegisterAbilityAsMcpTool::{$ability_name}" ) );

				// Track tool registration failure.
				$this->observability_handler::record_event(
					'mcp.component.registration_failed',
					array(
						'component_type' => 'tool',
						'component_name' => $ability_name,
						'error_type'     => get_class( $e ),
						'server_id'      => $this->mcp_server->get_server_id(),
					)
				);
			}
		}
	}

	/**
	 * Register a McpTool instance directly to the server.
	 *
	 * @param \WP\MCP\Domain\Tools\McpTool $tool The tool instance to register.
	 *
	 * @return void
	 */
	public function add_tool( McpTool $tool ): void {
		try {
			// Validate if validation is enabled
			if ( $this->mcp_validation_enabled ) {
				$tool->validate( "McpComponentRegistry::add_tool::{$tool->get_name()}" );
			}

			// Set the MCP server
			$tool->set_mcp_server( $this->mcp_server );

			// Add the tool to this server
			$this->tools[ $tool->get_name() ] = $tool;

			// Track successful tool registration
			$this->observability_handler::record_event(
				'mcp.component.registered',
				array(
					'component_type' => 'tool',
					'component_name' => $tool->get_name(),
					'server_id'      => $this->mcp_server->get_server_id(),
				)
			);
		} catch ( \InvalidArgumentException $e ) {
			$this->error_handler->log( $e->getMessage(), array( "McpComponentRegistry::add_tool::{$tool->get_name()}" ) );

			// Track tool registration failure
			$this->observability_handler::record_event(
				'mcp.component.registration_failed',
				array(
					'component_type' => 'tool',
					'component_name' => $tool->get_name(),
					'error_type'     => get_class( $e ),
					'server_id'      => $this->mcp_server->get_server_id(),
				)
			);
		}
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

			try {
				$ability = wp_get_ability( $ability_name );

				if ( ! $ability ) {
					throw new \InvalidArgumentException( esc_html( "WordPress ability '{$ability_name}' does not exist." ) );
				}

				$resource = RegisterAbilityAsMcpResource::make( $ability, $this->mcp_server );
				// Add the processed resources to this server.
				$this->resources[ $resource->get_uri() ] = $resource;

				// Track successful resource registration.
				$this->observability_handler::record_event(
					'mcp.component.registered',
					array(
						'component_type' => 'resource',
						'component_name' => $ability_name,
						'server_id'      => $this->mcp_server->get_server_id(),
					)
				);
			} catch ( \InvalidArgumentException $e ) {
				$this->error_handler->log( $e->getMessage(), array( "RegisterAbilityAsMcpResource::{$ability_name}" ) );

				// Track resource registration failure.
				$this->observability_handler::record_event(
					'mcp.component.registration_failed',
					array(
						'component_type' => 'resource',
						'component_name' => $ability_name,
						'error_type'     => get_class( $e ),
						'server_id'      => $this->mcp_server->get_server_id(),
					)
				);
			}
		}
	}

	/**
	 * Register prompts to the server.
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
					/** @var \WP\MCP\Domain\Prompts\Contracts\McpPromptBuilderInterface $builder */
					$builder = new $prompt_item();
					$prompt  = $builder->build();

					// Set the MCP server after building
					$prompt->set_mcp_server( $this->mcp_server );

					// Validate if validation is enabled
					if ( $this->mcp_validation_enabled ) {
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
							'server_id'      => $this->mcp_server->get_server_id(),
						)
					);
				} catch ( \InvalidArgumentException $e ) {
					$this->error_handler->log( $e->getMessage(), array( "McpPromptBuilder::{$prompt_item}" ) );

					// Track prompt registration failure
					$this->observability_handler::record_event(
						'mcp.component.registration_failed',
						array(
							'component_type' => 'prompt',
							'component_name' => $prompt_item,
							'error_type'     => get_class( $e ),
							'server_id'      => $this->mcp_server->get_server_id(),
						)
					);
				}
			} else {
				// Treat as ability name (legacy behavior)
				try {
					$ability = wp_get_ability( $prompt_item );

					if ( ! $ability ) {
						throw new \InvalidArgumentException( "WordPress ability '{$prompt_item}' does not exist." );
					}

					// Use RegisterMcpPrompt to handle all validation and processing.
					$prompt = RegisterAbilityAsMcpPrompt::make( $ability, $this->mcp_server );

					// Add the processed prompts to this server.
					$this->prompts[ $prompt->get_name() ] = $prompt;

					// Track successful prompt registration.
					$this->observability_handler::record_event(
						'mcp.component.registered',
						array(
							'component_type' => 'prompt',
							'component_name' => $prompt_item,
							'server_id'      => $this->mcp_server->get_server_id(),
						)
					);
				} catch ( \InvalidArgumentException $e ) {
					$this->error_handler->log( $e->getMessage(), array( "RegisterAbilityAsMcpPrompt::{$prompt_item}" ) );

					// Track prompt registration failure.
					$this->observability_handler::record_event(
						'mcp.component.registration_failed',
						array(
							'component_type' => 'prompt',
							'component_name' => $prompt_item,
							'error_type'     => get_class( $e ),
							'server_id'      => $this->mcp_server->get_server_id(),
						)
					);
				}
			}
		}
	}

	/**
	 * Get all tools registered to the server.
	 *
	 * @return \WP\MCP\Domain\Tools\McpTool[]
	 */
	public function get_tools(): array {
		return $this->tools;
	}

	/**
	 * Get all resources registered to the server.
	 *
	 * @return \WP\MCP\Domain\Resources\McpResource[]
	 */
	public function get_resources(): array {
		return $this->resources;
	}

	/**
	 * Get all prompts registered to the server.
	 *
	 * @return \WP\MCP\Domain\Prompts\McpPrompt[]
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
	 * Remove a tool from the server.
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
	 * Remove a resource from the server.
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
	 * Remove a prompt from the server.
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
}
