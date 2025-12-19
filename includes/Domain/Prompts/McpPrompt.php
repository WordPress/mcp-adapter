<?php
/**
 * Unified MCP Prompt wrapper with fluent API.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Domain\Prompts;

use WP\MCP\Domain\Contracts\McpComponentInterface;
use WP\MCP\Domain\Prompts\Contracts\McpPromptBuilderInterface;
use WP\MCP\Domain\Utils\AbilityArgumentNormalizer;
use WP\MCP\Domain\Utils\McpValidator;
use WP\McpSchema\Common\AbstractDataTransferObject;
use WP\McpSchema\Server\Prompts\Prompt;
use WP\McpSchema\Server\Prompts\PromptArgument;

/**
 * Prompt wrapper providing unified execution and permission checks.
 *
 * This class supports multiple ways to register prompts:
 *
 * 1. Fluent API (direct callable):
 * ```php
 * $prompt = McpPrompt::create('code-review')
 *     ->title('Code Review')
 *     ->description('Generate a comprehensive code review')
 *     ->argument('code', 'The code to review', true)
 *     ->handler(fn($args) => ['messages' => [...]] )
 *     ->permission(fn() => current_user_can('read'));
 * ```
 *
 * 2. Array configuration:
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
 * 3. From WordPress Ability (ability-backed):
 * ```php
 * $prompt = McpPrompt::fromAbility($ability);
 * ```
 *
 * 4. From prompt builder (builder-backed compatibility):
 * ```php
 * $prompt = McpPrompt::fromBuilder($builder);
 * ```
 *
 * @since n.e.x.t
 */
final class McpPrompt implements McpComponentInterface {

	// =========================================================================
	// Fluent Builder Properties
	// =========================================================================

	/**
	 * The prompt name (unique identifier).
	 *
	 * @var string|null
	 */
	private ?string $name = null;

	/**
	 * The prompt title (human-readable display name).
	 *
	 * @var string|null
	 */
	private ?string $title_value = null;

	/**
	 * The prompt description.
	 *
	 * @var string|null
	 */
	private ?string $description_value = null;

	/**
	 * The prompt arguments.
	 *
	 * @var array<int, array{name: string, title?: string, description?: string, required?: bool}>
	 */
	private array $arguments_value = array();

	/**
	 * The prompt icons for UI display.
	 *
	 * @var array<int, array{src: string, mimeType?: string, sizes?: array<string>, theme?: string}>|null
	 */
	private ?array $icons_value = null;

	/**
	 * Additional metadata passed through to MCP clients.
	 *
	 * @var array<string, mixed>
	 */
	private array $meta_value = array();

	/**
	 * Whether the prompt was built using fluent API.
	 *
	 * @var bool
	 */
	private bool $is_fluent = false;

	// =========================================================================
	// Runtime Properties
	// =========================================================================

	/**
	 * Clean Prompt DTO (protocol-only).
	 *
	 * @var \WP\McpSchema\Server\Prompts\Prompt|null
	 */
	private ?Prompt $prompt = null;

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
	 */
	private function __construct() {
		// Properties remain null - explicit configuration required.
		// Null handler/permission triggers proper error responses.
	}

	// =========================================================================
	// Factory Methods
	// =========================================================================

	/**
	 * Create a new prompt definition with fluent API.
	 *
	 * @param string $name The unique prompt name.
	 *
	 * @return self
	 */
	public static function create( string $name ): self {
		$instance            = new self();
		$instance->name      = $name;
		$instance->is_fluent = true;

		return $instance;
	}

	/**
	 * Create a prompt definition from an array configuration.
	 *
	 * @param array{
	 *     name: string,
	 *     title?: string,
	 *     description?: string,
	 *     arguments?: array<int, array{name: string, title?: string, description?: string, required?: bool}>,
	 *     icons?: array<int, array{src: string, mimeType?: string, sizes?: array<string>, theme?: string}>,
	 *     meta?: array<string, mixed>,
	 *     handler: callable(array<string, mixed>): array<string, mixed>,
	 *     permission?: callable(array<string, mixed>): bool
	 * } $config The prompt configuration array.
	 *
	 * @return self
	 */
	public static function fromArray( array $config ): self {
		if ( empty( $config['name'] ) ) {
			throw new \InvalidArgumentException( 'Prompt configuration must include a "name" field.' );
		}

		if ( ! isset( $config['handler'] ) || ! is_callable( $config['handler'] ) ) {
			throw new \InvalidArgumentException( 'Prompt configuration must include a callable "handler" field.' );
		}

		$instance            = new self();
		$instance->name      = $config['name'];
		$instance->is_fluent = true;

		if ( isset( $config['title'] ) ) {
			$instance->title_value = $config['title'];
		}

		if ( isset( $config['description'] ) ) {
			$instance->description_value = $config['description'];
		}

		if ( isset( $config['arguments'] ) && is_array( $config['arguments'] ) ) {
			$instance->arguments_value = $config['arguments'];
		}

		if ( isset( $config['icons'] ) && is_array( $config['icons'] ) ) {
			$instance->icons_value = $config['icons'];
		}

		if ( isset( $config['meta'] ) && is_array( $config['meta'] ) ) {
			$instance->meta_value = $config['meta'];
		}

		$instance->handler = $config['handler'];

		if ( isset( $config['permission'] ) && is_callable( $config['permission'] ) ) {
			$instance->permission_callback = $config['permission'];
		}

		return $instance;
	}

	/**
	 * Create an ability-backed prompt wrapper.
	 *
	 * @param \WP_Ability $ability WordPress ability.
	 *
	 * @return self|\WP_Error
	 */
	public static function fromAbility( \WP_Ability $ability ) {
		$prompt_data = RegisterAbilityAsMcpPrompt::build( $ability );
		if ( $prompt_data instanceof \WP_Error ) {
			return $prompt_data;
		}

		$instance               = new self();
		$instance->prompt       = $prompt_data['prompt'];
		$instance->adapter_meta = $prompt_data['adapter_meta'];
		$instance->ability      = $ability;

		$instance->observability_context = array(
			'component_type' => 'prompt',
			'prompt_name'    => $instance->prompt->getName(),
			'ability_name'   => $ability->get_name(),
			'source'         => 'ability',
		);

		return $instance;
	}

	/**
	 * Create a builder-backed prompt wrapper.
	 *
	 * @param \WP\MCP\Domain\Prompts\Contracts\McpPromptBuilderInterface $builder Builder instance.
	 *
	 * @return self|\WP_Error
	 */
	public static function fromBuilder( McpPromptBuilderInterface $builder ) {
		try {
			$prompt = $builder->build();
		} catch ( \Throwable $throwable ) {
			return new \WP_Error(
				'mcp_prompt_builder_failed',
				$throwable->getMessage(),
				array( 'error_type' => get_class( $throwable ) )
			);
		}

		$instance          = new self();
		$instance->builder = $builder;
		$instance->prompt  = $prompt;

		$instance->adapter_meta = array(
			'source'        => 'builder',
			'builder_class' => get_class( $builder ),
		);

		$instance->observability_context = array(
			'component_type' => 'prompt',
			'prompt_name'    => $instance->prompt->getName(),
			'source'         => 'builder',
		);

		return $instance;
	}

	// =========================================================================
	// Fluent Setters
	// =========================================================================

	/**
	 * Set the prompt title.
	 *
	 * @param string $title The human-readable title.
	 *
	 * @return self
	 */
	public function title( string $title ): self {
		$this->title_value = $title;
		return $this;
	}

	/**
	 * Set the prompt description.
	 *
	 * @param string $description The prompt description.
	 *
	 * @return self
	 */
	public function description( string $description ): self {
		$this->description_value = $description;
		return $this;
	}

	/**
	 * Add an argument to the prompt.
	 *
	 * @param string      $name        The argument name.
	 * @param string|null $description Optional argument description.
	 * @param bool        $required    Whether the argument is required.
	 *
	 * @return self
	 */
	public function argument( string $name, ?string $description = null, bool $required = false ): self {
		$arg = array( 'name' => $name );

		if ( null !== $description ) {
			$arg['description'] = $description;
		}

		if ( $required ) {
			$arg['required'] = true;
		}

		$this->arguments_value[] = $arg;

		return $this;
	}

	/**
	 * Set multiple arguments at once.
	 *
	 * @param array<int, array{name: string, title?: string, description?: string, required?: bool}> $arguments The arguments array.
	 *
	 * @return self
	 */
	public function arguments( array $arguments ): self {
		$this->arguments_value = $arguments;
		return $this;
	}

	/**
	 * Set the prompt icons for UI display.
	 *
	 * @param array<int, array{src: string, mimeType?: string, sizes?: array<string>, theme?: string}> $icons Array of icon definitions.
	 *
	 * @return self
	 */
	public function icons( array $icons ): self {
		$this->icons_value = $icons;
		return $this;
	}

	/**
	 * Set additional metadata.
	 *
	 * @param array<string, mixed> $meta Additional metadata key-value pairs.
	 *
	 * @return self
	 */
	public function meta( array $meta ): self {
		$this->meta_value = $meta;
		return $this;
	}

	/**
	 * Set the handler callable for prompt execution.
	 *
	 * @param callable(array<string, mixed>): array<string, mixed> $handler The handler callable.
	 *
	 * @return self
	 */
	public function handler( callable $handler ): self {
		$this->handler = $handler;
		return $this;
	}

	/**
	 * Set the permission check callable.
	 *
	 * @param callable(array<string, mixed>): bool $callback The permission callback.
	 *
	 * @return self
	 */
	public function permission( callable $callback ): self {
		$this->permission_callback = $callback;
		return $this;
	}

	// =========================================================================
	// McpComponentInterface Implementation
	// =========================================================================

	/**
	 * Get the clean protocol DTO for MCP responses.
	 *
	 * @return \WP\McpSchema\Common\AbstractDataTransferObject
	 */
	public function get_component(): AbstractDataTransferObject {
		return $this->get_prompt();
	}

	/**
	 * Get the human-readable name for this prompt.
	 *
	 * @return string
	 */
	public function get_name(): string {
		$prompt = $this->get_prompt();
		$title  = $prompt->getTitle();

		return null !== $title && '' !== trim( $title ) ? $title : $prompt->getName();
	}

	/**
	 * Execute the prompt.
	 *
	 * @param mixed $arguments Prompt arguments.
	 *
	 * @return mixed
	 */
	public function execute( $arguments ) {
		$prompt = $this->get_prompt();
		unset( $prompt );

		$args = $this->unwrap_input_if_needed( $arguments );
		$args = is_array( $args ) ? $args : array();

		if ( null !== $this->ability ) {
			$args = AbilityArgumentNormalizer::normalize( $this->ability, $args );

			try {
				$result = $this->ability->execute( $args );
			} catch ( \Throwable $throwable ) {
				return new \WP_Error(
					'mcp_execution_failed',
					$throwable->getMessage(),
					array( 'error_type' => get_class( $throwable ) )
				);
			}
		} elseif ( null !== $this->builder ) {
			try {
				$result = $this->builder->handle( $args );
			} catch ( \Throwable $throwable ) {
				return new \WP_Error(
					'mcp_execution_failed',
					$throwable->getMessage(),
					array( 'error_type' => get_class( $throwable ) )
				);
			}
		} elseif ( null !== $this->handler ) {
			try {
				$result = call_user_func( $this->handler, $args );
			} catch ( \Throwable $throwable ) {
				return new \WP_Error(
					'mcp_execution_failed',
					$throwable->getMessage(),
					array( 'error_type' => get_class( $throwable ) )
				);
			}
		} else {
			return new \WP_Error( 'mcp_prompt_no_handler', 'No prompt execution strategy configured.' );
		}

		if ( $result instanceof \WP_Error ) {
			return $result;
		}

		if ( ! is_array( $result ) ) {
			$result = array( 'result' => $result );
		}

		return $result;
	}

	/**
	 * Check whether the current request has permission to execute this prompt.
	 *
	 * @param mixed $arguments Prompt arguments.
	 *
	 * @return bool|\WP_Error
	 */
	public function check_permission( $arguments ) {
		$prompt = $this->get_prompt();
		unset( $prompt );

		$args = $this->unwrap_input_if_needed( $arguments );
		$args = is_array( $args ) ? $args : array();

		if ( null !== $this->ability ) {
			$args = AbilityArgumentNormalizer::normalize( $this->ability, $args );

			try {
				return $this->ability->check_permissions( $args );
			} catch ( \Throwable $throwable ) {
				return new \WP_Error(
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
				return new \WP_Error(
					'mcp_permission_check_failed',
					$throwable->getMessage(),
					array( 'error_type' => get_class( $throwable ) )
				);
			}
		}

		if ( null !== $this->permission_callback ) {
			try {
				return $this->invoke_permission_callback( $args );
			} catch ( \Throwable $throwable ) {
				return new \WP_Error(
					'mcp_permission_check_failed',
					$throwable->getMessage(),
					array( 'error_type' => get_class( $throwable ) )
				);
			}
		}

		return new \WP_Error(
			'mcp_permission_denied',
			'Access denied.',
			array( 'failure_reason' => 'no_permission_strategy' )
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

	/**
	 * Get the underlying builder instance, when builder-backed.
	 *
	 * @return \WP\MCP\Domain\Prompts\Contracts\McpPromptBuilderInterface|null
	 */
	public function get_builder(): ?McpPromptBuilderInterface {
		return $this->builder;
	}

	// =========================================================================
	// Builder Method
	// =========================================================================

	/**
	 * Build and cache the Prompt DTO from fluent configuration.
	 *
	 * @return self
	 */
	private function build(): self {
		if ( null === $this->name ) {
			throw new \RuntimeException( 'Prompt name is required. Use create() or fromArray() to set a name.' );
		}

		$argument_dtos = null;
		if ( ! empty( $this->arguments_value ) ) {
			$argument_dtos = array_map(
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
				$this->arguments_value
			);
		}

		// Validate and prepare icons if set.
		$valid_icons = null;
		if ( ! empty( $this->icons_value ) ) {
			$icons_result = McpValidator::validate_icons_array( $this->icons_value );
			if ( ! empty( $icons_result['valid'] ) ) {
				$valid_icons = $icons_result['valid'];
			}
		}

		$prompt_data = array(
			'name'        => $this->name,
			'description' => $this->description_value,
			'arguments'   => $argument_dtos,
		);

		if ( null !== $this->title_value ) {
			$prompt_data['title'] = $this->title_value;
		}

			$_meta = $this->meta_value;
		if ( ! empty( $_meta ) ) {
			$prompt_data['_meta'] = $_meta;
		}

		if ( null !== $valid_icons ) {
			$prompt_data['icons'] = $valid_icons;
		}

		$this->prompt = Prompt::fromArray( $prompt_data );

		$this->observability_context = array(
			'component_type' => 'prompt',
			'prompt_name'    => $this->name,
			'source'         => 'fluent',
		);

		$this->adapter_meta = array(
			'fluent' => true,
		);

		return $this;
	}

	// =========================================================================
	// Private Helper Methods
	// =========================================================================

	/**
	 * Get the Prompt DTO, building it if needed.
	 *
	 * @return \WP\McpSchema\Server\Prompts\Prompt
	 */
	private function get_prompt(): Prompt {
		if ( null === $this->prompt && $this->is_fluent ) {
			$this->build();
		}

		if ( null === $this->prompt ) {
			throw new \RuntimeException( 'Prompt DTO not initialized. Use create(), fromArray(), fromAbility(), or fromBuilder().' );
		}

		return $this->prompt;
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
	 * Invoke the permission callback, supporting both 0-arg and 1-arg callables.
	 *
	 * @param array<string, mixed> $args Prompt arguments after unwrapping.
	 *
	 * @return bool|\WP_Error
	 */
	private function invoke_permission_callback( array $args ) {
		if ( null === $this->permission_callback ) {
			return false;
		}

		$reflection = $this->reflect_callable( $this->permission_callback );

		if (
			null !== $reflection
			&& ! $reflection->isVariadic()
			&& 0 === $reflection->getNumberOfParameters()
		) {
			$result = call_user_func( $this->permission_callback );
		} else {
			$result = call_user_func( $this->permission_callback, $args );
		}

		if ( $result instanceof \WP_Error ) {
			return $result;
		}

		return (bool) $result;
	}

	/**
	 * Reflect a callable into a function/method reflector when possible.
	 *
	 * @param callable $callback Callable to reflect.
	 *
	 * @return \ReflectionFunctionAbstract|null
	 */
	private function reflect_callable( $callback ): ?\ReflectionFunctionAbstract {
		try {
			if ( $callback instanceof \Closure ) {
				return new \ReflectionFunction( $callback );
			}

			if ( is_string( $callback ) ) {
				if ( false !== strpos( $callback, '::' ) ) {
					[ $class, $method ] = explode( '::', $callback, 2 );
					return new \ReflectionMethod( $class, $method );
				}

				return new \ReflectionFunction( $callback );
			}

			if ( is_array( $callback ) && 2 === count( $callback ) ) {
				return new \ReflectionMethod( $callback[0], $callback[1] );
			}

			if ( is_object( $callback ) && method_exists( $callback, '__invoke' ) ) {
				return new \ReflectionMethod( $callback, '__invoke' );
			}
		} catch ( \ReflectionException $reflection_exception ) {
			return null;
		}

		return null;
	}

	// Intentionally no DTO meta sanitization; `_meta` is passed through unchanged.
}
