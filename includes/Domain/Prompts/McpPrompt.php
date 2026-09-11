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
use WP\MCP\Domain\Utils\RevisionProjectionTrait;
use WP\MCP\Domain\Utils\ThrowableGuardTrait;
use WP\MCP\Infrastructure\Observability\FailureReason;
use WP\McpSchema\Record\Prompt;
use WP\McpSchema\Schema;
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
 * McpPrompt stores revision-neutral configuration for MCP projection. Internal
 * adapter metadata and execution wiring live on this class and are never
 * exposed to MCP clients. Use get_protocol_record() for protocol responses.
 *
 * @since 0.5.0
 */
final class McpPrompt implements McpComponentInterface {
	use RevisionProjectionTrait;
	use ThrowableGuardTrait;

	// =========================================================================
	// Runtime Properties
	// =========================================================================

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
	 * @param array<string, mixed> $prompt_data Revision-neutral prompt data.
	 */
	private function __construct( array $prompt_data ) {
		$this->initialize_protocol_data( $prompt_data );
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

		$prompt_data = array( 'name' => $config['name'] );
		if ( isset( $config['description'] ) ) {
			$prompt_data['description'] = $config['description'];
		}

		if ( isset( $config['title'] ) ) {
			$prompt_data['title'] = $config['title'];
		}

		// Icons and _meta are carried as given; the schema decides whether they fit.
		if ( isset( $config['meta'] ) ) {
			$prompt_data['_meta'] = $config['meta'];
		}

		if ( isset( $config['icons'] ) ) {
			$prompt_data['icons'] = $config['icons'];
		}

		// Arguments are carried as given; a list is re-indexed so it serializes as a
		// JSON array. The schema decides whether the entries fit.
		if ( isset( $config['arguments'] ) ) {
			$prompt_data['arguments'] = is_array( $config['arguments'] )
				? array_values( $config['arguments'] )
				: $config['arguments'];
		}

		$instance          = new self( $prompt_data );
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

		$instance               = new self( $prompt_data['prompt_data'] );
		$instance->adapter_meta = $prompt_data['adapter_meta'];
		$instance->ability      = $ability;

		$instance->observability_context = array(
			'component_type' => 'prompt',
			'prompt_name'    => $prompt_data['prompt_data']['name'],
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
		$prompt = self::guard( 'mcp_prompt_builder_failed', static fn() => $builder->build() );
		if ( $prompt instanceof WP_Error ) {
			return $prompt;
		}

		$instance          = new self( $prompt );
		$instance->builder = $builder;

		$instance->adapter_meta = array(
			'source'        => 'builder',
			'builder_class' => get_class( $builder ),
		);

		$instance->observability_context = array(
			'component_type' => 'prompt',
			'prompt_name'    => (string) ( $prompt['name'] ?? '' ),
			'source'         => 'builder',
		);

		return $instance;
	}

	// =========================================================================
	// McpComponentInterface Implementation
	// =========================================================================

	/**
	 * Get the clean protocol record for one revision.
	 *
	 * @param \WP\McpSchema\Schema $schema Selected schema.
	 * @since n.e.x.t
	 */
	public function get_protocol_record( Schema $schema ): Prompt {
		return $this->project_record( $schema, Prompt::class, $this->protocol_data() );
	}

	/**
	 * Get the neutral prompt name.
	 *
	 * @since n.e.x.t
	 */
	public function get_name(): string {
		return (string) ( $this->protocol_data()['name'] ?? '' );
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
			$ability = $this->ability;
			$args    = AbilityArgumentNormalizer::normalize( $ability, $args );
			$result  = self::guard( 'mcp_execution_failed', static fn() => $ability->execute( $args ) );
		} elseif ( null !== $this->builder ) {
			$builder = $this->builder;
			$result  = self::guard( 'mcp_execution_failed', static fn() => $builder->handle( $args ) );
		} elseif ( null !== $this->handler ) {
			$handler = $this->handler;
			$result  = self::guard( 'mcp_execution_failed', static fn() => call_user_func( $handler, $args ) );
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
			$ability = $this->ability;
			$args    = AbilityArgumentNormalizer::normalize( $ability, $args );

			return self::guard( 'mcp_permission_check_failed', static fn() => $ability->check_permissions( $args ) );
		}

		if ( null !== $this->builder ) {
			$builder = $this->builder;

			return self::guard( 'mcp_permission_check_failed', static fn() => $builder->has_permission( $args ) );
		}

		if ( null !== $this->permission_callback ) {
			$callback = $this->permission_callback;
			$result   = self::guard( 'mcp_permission_check_failed', static fn() => call_user_func( $callback, $args ) );

			return $result instanceof WP_Error ? $result : (bool) $result;
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
