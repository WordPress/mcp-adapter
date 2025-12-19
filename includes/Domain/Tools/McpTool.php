<?php
/**
 * Unified MCP Tool wrapper with fluent API.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Domain\Tools;

use WP\MCP\Domain\Contracts\McpComponentInterface;
use WP\MCP\Domain\Utils\McpValidator;
use WP\McpSchema\Common\AbstractDataTransferObject;
use WP\McpSchema\Server\Tools\Tool;
use WP\McpSchema\Server\Tools\ToolAnnotations;

/**
 * Tool wrapper providing unified execution and permission checks.
 *
 * This class provides multiple flexible ways to create MCP tools:
 *
 * 1. Fluent API (preferred for direct tools):
 * ```php
 * $tool = McpTool::create('uppercase-text')
 *     ->title('Uppercase Text')
 *     ->description('Converts text to uppercase')
 *     ->inputSchema([
 *         'type' => 'object',
 *         'properties' => ['text' => ['type' => 'string']],
 *         'required' => ['text'],
 *     ])
 *     ->handler(fn($args) => ['result' => strtoupper($args['text'])])
 *     ->permission(fn() => current_user_can('read'))
 *     ->readOnly();
 * ```
 *
 * 2. Array configuration:
 * ```php
 * $tool = McpTool::fromArray([
 *     'name'        => 'uppercase-text',
 *     'title'       => 'Uppercase Text',
 *     'description' => 'Converts text to uppercase',
 *     'inputSchema' => ['type' => 'object', 'properties' => [...]],
 *     'handler'     => fn($args) => ['result' => strtoupper($args['text'])],
 *     'permission'  => fn() => true,
 *     'annotations' => ['readOnlyHint' => true],
 * ]);
 * ```
 *
 * 3. From WordPress Ability (ability-backed):
 * ```php
 * $tool = McpTool::fromAbility($ability);
 * ```
 *
 * @since n.e.x.t
 */
final class McpTool implements McpComponentInterface {

	// =========================================================================
	// Fluent Builder Properties
	// =========================================================================

	/**
	 * The tool name (unique identifier).
	 *
	 * @var string|null
	 */
	private ?string $name = null;

	/**
	 * The tool title (human-readable display name).
	 *
	 * @var string|null
	 */
	private ?string $title_value = null;

	/**
	 * The tool description.
	 *
	 * @var string|null
	 */
	private ?string $description_value = null;

	/**
	 * The tool input schema (JSON Schema).
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $input_schema_value = null;

	/**
	 * The tool output schema (JSON Schema).
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $output_schema_value = null;

	/**
	 * The tool icons for UI display.
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
	 * Tool annotations.
	 *
	 * @var array{title?: string, readOnlyHint?: bool, destructiveHint?: bool, idempotentHint?: bool, openWorldHint?: bool}
	 */
	private array $annotations_value = array();

	/**
	 * Whether the tool was built using fluent API.
	 *
	 * @var bool
	 */
	private bool $is_fluent = false;

	// =========================================================================
	// Runtime Properties (existing functionality)
	// =========================================================================

	/**
	 * Clean Tool DTO (protocol-only).
	 *
	 * @var \WP\McpSchema\Server\Tools\Tool|null
	 */
	private ?Tool $tool = null;

	/**
	 * Ability used for execution/permission checks (ability-backed tools).
	 *
	 * @var \WP_Ability|null
	 */
	private ?\WP_Ability $ability = null;

	/**
	 * Direct execution handler (callable-backed tools).
	 *
	 * @var callable|null
	 */
	private $handler = null;

	/**
	 * Direct permission callback (callable-backed tools).
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
	 * Create a new tool definition with fluent API.
	 *
	 * @param string $name The unique tool name.
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
	 * Create a tool definition from an array configuration.
	 *
	 * @param array{
	 *     name: string,
	 *     title?: string,
	 *     description?: string,
	 *     inputSchema?: array<string, mixed>,
	 *     outputSchema?: array<string, mixed>,
	 *     icons?: array<int, array{src: string, mimeType?: string, sizes?: array<string>, theme?: string}>,
	 *     meta?: array<string, mixed>,
	 *     annotations?: array{title?: string, readOnlyHint?: bool, destructiveHint?: bool, idempotentHint?: bool, openWorldHint?: bool},
	 *     handler: callable(array<string, mixed>): array<string, mixed>,
	 *     permission?: callable(array<string, mixed>): bool
	 * } $config The tool configuration array.
	 *
	 * @return self
	 *
	 * @throws \InvalidArgumentException If required fields are missing.
	 */
	public static function fromArray( array $config ): self {
		if ( empty( $config['name'] ) ) {
			throw new \InvalidArgumentException( 'Tool configuration must include a "name" field.' );
		}

		if ( ! isset( $config['handler'] ) || ! is_callable( $config['handler'] ) ) {
			throw new \InvalidArgumentException( 'Tool configuration must include a callable "handler" field.' );
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

		if ( isset( $config['inputSchema'] ) && is_array( $config['inputSchema'] ) ) {
			$instance->input_schema_value = $config['inputSchema'];
		}

		if ( isset( $config['outputSchema'] ) && is_array( $config['outputSchema'] ) ) {
			$instance->output_schema_value = $config['outputSchema'];
		}

		if ( isset( $config['icons'] ) && is_array( $config['icons'] ) ) {
			$instance->icons_value = $config['icons'];
		}

		if ( isset( $config['meta'] ) && is_array( $config['meta'] ) ) {
			$instance->meta_value = $config['meta'];
		}

		if ( isset( $config['annotations'] ) && is_array( $config['annotations'] ) ) {
			$instance->annotations_value = $config['annotations'];
		}

		$instance->handler = $config['handler'];

		if ( isset( $config['permission'] ) && is_callable( $config['permission'] ) ) {
			$instance->permission_callback = $config['permission'];
		}

		return $instance;
	}

	/**
	 * Create an ability-backed tool wrapper.
	 *
	 * @param \WP_Ability $ability WordPress ability.
	 *
	 * @return self|\WP_Error
	 */
	public static function fromAbility( \WP_Ability $ability ) {
		$tool_data = RegisterAbilityAsMcpTool::build( $ability );
		if ( $tool_data instanceof \WP_Error ) {
			return $tool_data;
		}

		$instance               = new self();
		$instance->tool         = $tool_data['tool'];
		$instance->adapter_meta = $tool_data['adapter_meta'];
		$instance->ability      = $ability;

		$instance->observability_context = array(
			'component_type' => 'tool',
			'tool_name'      => $instance->tool->getName(),
			'ability_name'   => $ability->get_name(),
			'source'         => 'ability',
		);

		return $instance;
	}

	// =========================================================================
	// Fluent Setters
	// =========================================================================

	/**
	 * Set the tool title.
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
	 * Set the tool description.
	 *
	 * @param string $description The tool description.
	 *
	 * @return self
	 */
	public function description( string $description ): self {
		$this->description_value = $description;
		return $this;
	}

	/**
	 * Set the input schema (JSON Schema format).
	 *
	 * @param array<string, mixed> $schema The input schema.
	 *
	 * @return self
	 */
	public function inputSchema( array $schema ): self {
		$this->input_schema_value = $schema;
		return $this;
	}

	/**
	 * Set the output schema (JSON Schema format).
	 *
	 * @param array<string, mixed> $schema The output schema.
	 *
	 * @return self
	 */
	public function outputSchema( array $schema ): self {
		$this->output_schema_value = $schema;
		return $this;
	}

	/**
	 * Set the tool icons for UI display.
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
	 * Set the handler callable for tool execution.
	 *
	 * The handler receives the arguments array and should return an array result.
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
	 * The permission callback receives the arguments array and should return true
	 * if the current user has permission to execute the tool.
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
	// Annotation Helpers
	// =========================================================================

	/**
	 * Mark the tool as read-only (no side effects).
	 *
	 * @param bool $value Whether the tool is read-only.
	 *
	 * @return self
	 */
	public function readOnly( bool $value = true ): self {
		$this->annotations_value['readOnlyHint'] = $value;
		return $this;
	}

	/**
	 * Mark the tool as destructive (may cause data loss).
	 *
	 * @param bool $value Whether the tool is destructive.
	 *
	 * @return self
	 */
	public function destructive( bool $value = true ): self {
		$this->annotations_value['destructiveHint'] = $value;
		return $this;
	}

	/**
	 * Mark the tool as idempotent (safe to retry).
	 *
	 * @param bool $value Whether the tool is idempotent.
	 *
	 * @return self
	 */
	public function idempotent( bool $value = true ): self {
		$this->annotations_value['idempotentHint'] = $value;
		return $this;
	}

	/**
	 * Mark the tool as operating on an open world (may interact with external entities).
	 *
	 * @param bool $value Whether the tool operates on an open world.
	 *
	 * @return self
	 */
	public function openWorld( bool $value = true ): self {
		$this->annotations_value['openWorldHint'] = $value;
		return $this;
	}

	/**
	 * Set a custom annotation title.
	 *
	 * @param string $title The annotation title.
	 *
	 * @return self
	 */
	public function annotationTitle( string $title ): self {
		$this->annotations_value['title'] = $title;
		return $this;
	}

	/**
	 * Set multiple annotations at once.
	 *
	 * @param array{title?: string, readOnlyHint?: bool, destructiveHint?: bool, idempotentHint?: bool, openWorldHint?: bool} $annotations The annotations array.
	 *
	 * @return self
	 */
	public function annotations( array $annotations ): self {
		$this->annotations_value = array_merge( $this->annotations_value, $annotations );
		return $this;
	}

	// =========================================================================
	// McpComponentInterface Implementation
	// =========================================================================

	/**
	 * Get the clean protocol DTO for MCP responses.
	 *
	 * Builds the Tool DTO if using fluent API, otherwise returns the existing DTO.
	 *
	 * @return \WP\McpSchema\Common\AbstractDataTransferObject
	 */
	public function get_component(): AbstractDataTransferObject {
		return $this->get_tool();
	}

	/**
	 * Get the human-readable name for this tool.
	 *
	 * @return string
	 */
	public function get_name(): string {
		$tool  = $this->get_tool();
		$title = $tool->getTitle();

		return null !== $title && '' !== trim( $title ) ? $title : $tool->getName();
	}

	/**
	 * Execute the tool.
	 *
	 * @param mixed $arguments Tool arguments.
	 *
	 * @return mixed
	 */
	public function execute( $arguments ) {
		$this->get_tool();

		$args = $this->unwrap_input_if_needed( $arguments );

		if ( null !== $this->ability ) {
			$args = $this->normalize_ability_args( $args );

			try {
				$result = $this->ability->execute( $args );
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
			return new \WP_Error( 'mcp_tool_no_handler', 'No tool execution strategy configured.' );
		}

		if ( $result instanceof \WP_Error ) {
			return $result;
		}

		$result = $this->wrap_output_if_needed( $result );

		if ( ! is_array( $result ) ) {
			$result = array( 'result' => $result );
		}

		return $result;
	}

	/**
	 * Check whether the current request has permission to execute this tool.
	 *
	 * @param mixed $arguments Tool arguments.
	 *
	 * @return bool|\WP_Error
	 */
	public function check_permission( $arguments ) {
		$tool = $this->get_tool();

		$args = $this->unwrap_input_if_needed( $arguments );

		// Ability-backed tools delegate to the ability's permission system.
		if ( null !== $this->ability ) {
			$args = $this->normalize_ability_args( $args );

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

		// Callable-backed tools use their required permission callback.
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

		// Defensive fallback: should never reach here if factories are used correctly.
		return new \WP_Error(
			'mcp_permission_denied',
			'Access denied.',
			array(
				'failure_reason' => 'no_permission_strategy',
				'tool_name'      => $tool->getName(),
			)
		);
	}

	/**
	 * Get internal adapter metadata for this tool.
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
	// Builder Method
	// =========================================================================

	/**
	 * Build and cache the Tool DTO from fluent configuration.
	 *
	 * @return self
	 */
	private function build(): self {
		if ( null === $this->name ) {
			throw new \RuntimeException( 'Tool name is required. Use create() or fromArray() to set a name.' );
		}

		// Prepare input schema - ensure it's an object type for MCP compliance.
		$input_schema = $this->input_schema_value ?? array( 'type' => 'object' );
		if ( ! isset( $input_schema['type'] ) ) {
			$input_schema['type'] = 'object';
		}

		// Build tool data array.
		$tool_data = array(
			'name'        => $this->name,
			'inputSchema' => $input_schema,
		);

		// Optional fields.
		if ( null !== $this->title_value ) {
			$tool_data['title'] = $this->title_value;
		}

		if ( null !== $this->description_value ) {
			$tool_data['description'] = $this->description_value;
		}

		if ( null !== $this->output_schema_value ) {
			$tool_data['outputSchema'] = $this->output_schema_value;
		}

		// Process annotations.
		if ( ! empty( $this->annotations_value ) ) {
			$tool_data['annotations'] = ToolAnnotations::fromArray( $this->annotations_value );
		}

			// Validate and prepare icons if set.
		if ( ! empty( $this->icons_value ) ) {
			$icons_result = McpValidator::validate_icons_array( $this->icons_value );
			if ( ! empty( $icons_result['valid'] ) ) {
				$tool_data['icons'] = $icons_result['valid'];
			}
		}

			// Preserve user-provided _meta as-is.
		if ( ! empty( $this->meta_value ) ) {
			$tool_data['_meta'] = $this->meta_value;
		}

		// Create the Tool DTO.
		$this->tool = Tool::fromArray( $tool_data );

			// Set observability context.
			$this->observability_context = array(
				'component_type' => 'tool',
				'tool_name'      => $this->name,
				'source'         => 'fluent',
			);

			return $this;
	}

	// =========================================================================
	// Private Helper Methods
	// =========================================================================

	/**
	 * Get the Tool DTO, building it if needed.
	 *
	 * @return \WP\McpSchema\Server\Tools\Tool
	 */
	private function get_tool(): Tool {
		if ( null === $this->tool && $this->is_fluent ) {
			$this->build();
		}

		if ( null === $this->tool ) {
			throw new \RuntimeException( 'Tool DTO not initialized. Use create(), fromArray(), or fromAbility().' );
		}

		return $this->tool;
	}

	/**
	 * Invoke the permission callback, supporting both 0-arg and 1-arg callables.
	 *
	 * @param mixed $args Tool arguments after unwrapping.
	 *
	 * @return bool|\WP_Error
	 */
	private function invoke_permission_callback( $args ) {
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

	/**
	 * Normalize arguments for ability callbacks.
	 *
	 * When an ability has no input schema, pass null instead of an empty array to maintain compatibility.
	 *
	 * @param mixed $args Arguments after unwrapping.
	 *
	 * @return mixed
	 */
	private function normalize_ability_args( $args ) {
		if ( null === $this->ability ) {
			return $args;
		}

		$ability_input_schema = $this->ability->get_input_schema();

		if ( empty( $ability_input_schema ) && empty( $args ) ) {
			return null;
		}

		return $args;
	}

	/**
	 * Unwrap tool input arguments when the input schema was transformed (flattened → object wrapper).
	 *
	 * @param mixed $arguments Raw tool arguments.
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
	 * Wrap tool results when the output schema was transformed (flattened → object wrapper).
	 *
	 * @param mixed $result Raw result.
	 *
	 * @return mixed
	 */
	private function wrap_output_if_needed( $result ) {
		$is_transformed = true === ( $this->adapter_meta['output_schema_transformed'] ?? false );

		if ( ! $is_transformed ) {
			return $result;
		}

		$wrapper = $this->adapter_meta['output_schema_wrapper'] ?? 'result';
		$wrapper = is_string( $wrapper ) && '' !== trim( $wrapper ) ? $wrapper : 'result';

		return array( $wrapper => $result );
	}
}
