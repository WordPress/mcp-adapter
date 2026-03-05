<?php

/**
 * MCP Prompt component.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Domain\Prompts;

use WP\MCP\Domain\Contracts\McpComponentInterface;
use WP\MCP\Domain\Prompts\Contracts\McpPromptBuilderInterface;
use WP\MCP\Domain\Utils\AbilityArgumentNormalizer;
use WP\MCP\Domain\Utils\McpValidator;
use WP\MCP\Infrastructure\Observability\FailureReason;
use WP\McpSchema\Server\Prompts\DTO\Prompt as PromptDto;
use WP\McpSchema\Server\Prompts\DTO\PromptArgument;
use WP_Error;

/**
 * Prompt component providing unified execution and permission checks.
 *
 * This class supports multiple ways to register prompts:
 *
 * 1. Array configuration:
 * ```php
 * $prompt = McpPrompt::fromArray([
 *     'name'        => 'code-review',
 *     'title'       => 'Code Review',
 *     'description' => 'Generate a comprehensive code review',
 *     'arguments'   => [
 *         ['name' => 'code', 'description' => 'The code to review', 'required' => true],
 *     ],
 *     'handler'     => fn($args) => ['messages' => [...]],
 *     'permission'  => fn() => true,
 * ]);
 * ```
 *
 * 2. From WordPress Ability (ability-backed):
 * ```php
 * $prompt = McpPrompt::fromAbility($ability);
 * ```
 *
 * 3. From prompt builder (builder-backed compatibility):
 * ```php
 * $prompt = McpPrompt::fromBuilder($builder);
 * ```
 *
 * McpPrompt wraps a protocol-only PromptDto for MCP serialization. Internal
 * adapter metadata and execution wiring live on this class and are never
 * exposed to MCP clients. Use get_protocol_dto() for protocol responses.
 *
 * @since n.e.x.t
 */
final class McpPrompt implements McpComponentInterface {


	// =========================================================================
	// Runtime Properties
	// =========================================================================

	/**
	 * Clean Prompt DTO (protocol-only).
	 *
	 * @var \WP\McpSchema\Server\Prompts\DTO\Prompt
	 */
	private PromptDto $prompt;

	/**
	 * Ability used for execution/permission checks (ability-backed prompts).
	 *
	 * @var \WP_Ability|null
	 */
	private ?\WP_Ability $ability = null;

	/**
	 * Builder instance (builder-backed prompts).
	 *
	 * @var \WP\MCP\Domain\Prompts\Contracts\McpPromptBuilderInterface|null
	 */
	private ?McpPromptBuilderInterface $builder = null;

	/**
	 * Direct execution handler (callable-backed prompts).
	 *
	 * @var callable|null
	 */
	private $handler = null;

	/**
	 * Direct permission callback (callable-backed prompts).
	 *
	 * @var callable|null
	 */
	private $permission_callback = null;

	/**
	 * Internal adapter metadata (never exposed to clients).
	 *
	 * @var array<string, mixed>
	 */
	private array $adapter_meta = array();

	/**
	 * Observability context tags for logging/metrics.
	 *
	 * @var array<string, mixed>
	 */
	private array $observability_context = array();

	// =========================================================================
	// Constructor
	// =========================================================================

	/**
	 * Private constructor - use factory methods.
	 *
	 * @param \WP\McpSchema\Server\Prompts\DTO\Prompt $prompt The Prompt DTO.
	 */
	private function __construct( PromptDto $prompt ) {
		$this->prompt = $prompt;
	}

	// =========================================================================
	// Factory Methods
	// =========================================================================

	/**
	 * Create a prompt definition from an array configuration.
	 *
	 * @param array $config The prompt configuration array.
	 *
	 * @return self|\WP_Error
	 */
	public static function fromArray( array $config ) {
		if ( empty( $config['name'] ) ) {
			return new WP_Error( 'mcp_prompt_missing_name', 'Prompt configuration must include a "name" field.' );
		}

		if ( ! isset( $config['handler'] ) || ! is_callable( $config['handler'] ) ) {
			return new WP_Error( 'mcp_prompt_missing_handler', 'Prompt configuration must include a callable "handler" field.' );
		}

		// Validate and prepare icons if set.
		$valid_icons = null;
		if ( isset( $config['icons'] ) && is_array( $config['icons'] ) && ! empty( $config['icons'] ) ) {
			$icons_result = McpValidator::validate_icons_array( $config['icons'] );
			if ( ! empty( $icons_result['valid'] ) ) {
				$valid_icons = $icons_result['valid'];
			}
		}

		$prompt_data = array(
			'name'        => $config['name'],
			'description' => $config['description'] ?? null,
		);

		if ( isset( $config['title'] ) ) {
			$prompt_data['title'] = $config['title'];
		}

		if ( isset( $config['meta'] ) && is_array( $config['meta'] ) && ! empty( $config['meta'] ) ) {
			$prompt_data['_meta'] = $config['meta'];
		}

		if ( null !== $valid_icons ) {
			$prompt_data['icons'] = $valid_icons;
		}

		// Create the Prompt DTO - wrap in try-catch since PromptArgument::fromArray() and PromptDto::fromArray() can throw.
		try {
			// Process arguments inside try-catch since PromptArgument::fromArray() can throw.
			if ( isset( $config['arguments'] ) && is_array( $config['arguments'] ) && ! empty( $config['arguments'] ) ) {
				$prompt_data['arguments'] = array_map(
					static function ( array $arg ): PromptArgument {
						return PromptArgument::fromArray(
							array(
								'name'        => $arg['name'],
								'title'       => $arg['title'] ?? null,
								'description' => $arg['description'] ?? null,
								'required'    => $arg['required'] ?? null,
							)
						);
					},
					$config['arguments']
				);
			}

			$prompt = PromptDto::fromArray( $prompt_data );
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'mcp_prompt_dto_creation_failed',
				sprintf(
				/* translators: %s: error message */
					__( 'Failed to create Prompt DTO: %s', 'mcp-adapter' ),
					$e->getMessage()
				),
				array( 'exception' => $e )
			);
		}

		// Optional deep validation if enabled.
		$mcp_validation_enabled = apply_filters( 'mcp_adapter_validation_enabled', false );
		if ( $mcp_validation_enabled ) {
			$validation_result = McpPromptValidator::validate_prompt_dto( $prompt );
			if ( is_wp_error( $validation_result ) ) {
				return $validation_result;
			}
		}

		$instance          = new self( $prompt );
		$instance->handler = $config['handler'];

		if ( isset( $config['permission'] ) && is_callable( $config['permission'] ) ) {
			$instance->permission_callback = $config['permission'];
		}

		$instance->observability_context = array(
			'component_type' => 'prompt',
			'prompt_name'    => $config['name'],
			'source'         => 'array',
		);

		return $instance;
	}

	/**
	 * Create an ability-backed MCP prompt.
	 *
	 * @param \WP_Ability $ability WordPress ability.
	 *
	 * @return self|\WP_Error
	 */
	public static function fromAbility( \WP_Ability $ability ) {
		$prompt_data = RegisterAbilityAsMcpPrompt::build( $ability );
		if ( $prompt_data instanceof WP_Error ) {
			return $prompt_data;
		}

		$instance               = new self( $prompt_data['prompt'] );
		$instance->adapter_meta = $prompt_data['adapter_meta'];
		$instance->ability      = $ability;

		$instance->observability_context = array(
			'component_type' => 'prompt',
			'prompt_name'    => $prompt_data['prompt']->getName(),
			'ability_name'   => $ability->get_name(),
			'source'         => 'ability',
		);

		return $instance;
	}

	/**
	 * Create a builder-backed MCP prompt.
	 *
	 * @param \WP\MCP\Domain\Prompts\Contracts\McpPromptBuilderInterface $builder Builder instance.
	 *
	 * @return self|\WP_Error
	 */
	public static function fromBuilder( McpPromptBuilderInterface $builder ) {
		try {
			$prompt = $builder->build();
		} catch ( \Throwable $throwable ) {
			return new WP_Error(
				'mcp_prompt_builder_failed',
				$throwable->getMessage(),
				array( 'error_type' => get_class( $throwable ) )
			);
		}

		// Optional deep validation if enabled.
		$mcp_validation_enabled = apply_filters( 'mcp_adapter_validation_enabled', false );
		if ( $mcp_validation_enabled ) {
			$validation_result = McpPromptValidator::validate_prompt_dto( $prompt );
			if ( is_wp_error( $validation_result ) ) {
				return $validation_result;
			}
		}

		$instance          = new self( $prompt );
		$instance->builder = $builder;

		$instance->adapter_meta = array(
			'source'        => 'builder',
			'builder_class' => get_class( $builder ),
		);

		$instance->observability_context = array(
			'component_type' => 'prompt',
			'prompt_name'    => $prompt->getName(),
			'source'         => 'builder',
		);

		return $instance;
	}

	// =========================================================================
	// McpComponentInterface Implementation
	// =========================================================================

	/**
	 * Get the clean protocol DTO for MCP responses.
	 *
	 * @return \WP\McpSchema\Server\Prompts\DTO\Prompt
	 */
	public function get_protocol_dto(): PromptDto {
		return $this->prompt;
	}

	/**
	 * Execute the prompt.
	 *
	 * @param mixed $arguments Prompt arguments.
	 *
	 * @return mixed
	 */
	public function execute( $arguments ) {
		$args = $this->unwrap_input_if_needed( $arguments );
		$args = is_array( $args ) ? $args : array();

		if ( null !== $this->ability ) {
			$args = AbilityArgumentNormalizer::normalize( $this->ability, $args );

			try {
				$result = $this->ability->execute( $args );
			} catch ( \Throwable $throwable ) {
				return new WP_Error(
					'mcp_execution_failed',
					$throwable->getMessage(),
					array( 'error_type' => get_class( $throwable ) )
				);
			}
		} elseif ( null !== $this->builder ) {
			try {
				$result = $this->builder->handle( $args );
			} catch ( \Throwable $throwable ) {
				return new WP_Error(
					'mcp_execution_failed',
					$throwable->getMessage(),
					array( 'error_type' => get_class( $throwable ) )
				);
			}
		} elseif ( null !== $this->handler ) {
			try {
				$result = call_user_func( $this->handler, $args );
			} catch ( \Throwable $throwable ) {
				return new WP_Error(
					'mcp_execution_failed',
					$throwable->getMessage(),
					array( 'error_type' => get_class( $throwable ) )
				);
			}
		} else {
			return new WP_Error( 'mcp_prompt_no_handler', 'No prompt execution strategy configured.' );
		}

		if ( $result instanceof WP_Error ) {
			return $result;
		}

		if ( ! is_array( $result ) ) {
			$result = array( 'result' => $result );
		}

		return $result;
	}

	/**
	 * Unwrap prompt input arguments when the input schema was transformed (flattened → object wrapper).
	 *
	 * @param mixed $arguments Raw prompt arguments.
	 *
	 * @return mixed
	 */
	private function unwrap_input_if_needed( $arguments ) {
		$is_transformed = true === ( $this->adapter_meta['input_schema_transformed'] ?? false );

		if ( ! $is_transformed ) {
			return $arguments;
		}

		$wrapper = $this->adapter_meta['input_schema_wrapper'] ?? 'input';
		$wrapper = is_string( $wrapper ) && '' !== trim( $wrapper ) ? $wrapper : 'input';

		return is_array( $arguments ) ? ( $arguments[ $wrapper ] ?? null ) : null;
	}

	/**
	 * Check whether the current request has permission to execute this prompt.
	 *
	 * @param mixed $arguments Prompt arguments.
	 *
	 * @return bool|\WP_Error
	 */
	public function check_permission( $arguments ) {
		$args = $this->unwrap_input_if_needed( $arguments );
		$args = is_array( $args ) ? $args : array();

		if ( null !== $this->ability ) {
			$args = AbilityArgumentNormalizer::normalize( $this->ability, $args );

			try {
				return $this->ability->check_permissions( $args );
			} catch ( \Throwable $throwable ) {
				return new WP_Error(
					'mcp_permission_check_failed',
					$throwable->getMessage(),
					array( 'error_type' => get_class( $throwable ) )
				);
			}
		}

		if ( null !== $this->builder ) {
			try {
				return $this->builder->has_permission( $args );
			} catch ( \Throwable $throwable ) {
				return new WP_Error(
					'mcp_permission_check_failed',
					$throwable->getMessage(),
					array( 'error_type' => get_class( $throwable ) )
				);
			}
		}

		if ( null !== $this->permission_callback ) {
			try {
				$result = call_user_func( $this->permission_callback, $args );

				return $result instanceof WP_Error ? $result : (bool) $result;
			} catch ( \Throwable $throwable ) {
				return new WP_Error(
					'mcp_permission_check_failed',
					$throwable->getMessage(),
					array( 'error_type' => get_class( $throwable ) )
				);
			}
		}

		return new WP_Error(
			'mcp_permission_denied',
			'Access denied.',
			array( 'failure_reason' => FailureReason::NO_PERMISSION_STRATEGY )
		);
	}

	/**
	 * Get internal adapter metadata for this prompt.
	 *
	 * @return array<string, mixed>
	 */
	public function get_adapter_meta(): array {
		return $this->adapter_meta;
	}

	/**
	 * Get observability context tags for logging/metrics.
	 *
	 * @return array<string, mixed>
	 */
	public function get_observability_context(): array {
		return $this->observability_context;
	}

	// =========================================================================
	// Private Helper Methods
	// =========================================================================

	/**
	 * Get the underlying builder instance, when builder-backed.
	 *
	 * @return \WP\MCP\Domain\Prompts\Contracts\McpPromptBuilderInterface|null
	 */
	public function get_builder(): ?McpPromptBuilderInterface {
		return $this->builder;
	}
}
