<?php
/**
 * MCP Tool component.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Domain\Tools;

use WP\MCP\Domain\Contracts\McpComponentInterface;
use WP\MCP\Domain\Utils\AbilityArgumentNormalizer;
use WP\MCP\Domain\Utils\McpValidator;
use WP\McpSchema\Server\Tools\Tool;
use WP\McpSchema\Server\Tools\ToolAnnotations;

/**
 * Tool component providing unified execution and permission checks.
 *
 * This class provides multiple flexible ways to create MCP tools:
 *
 * 1. Array configuration:
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
 * 2. From WordPress Ability (ability-backed):
 * ```php
 * $tool = McpTool::fromAbility($ability);
 * ```
 *
 * @since n.e.x.t
 */
final class McpTool implements McpComponentInterface {

	// =========================================================================
	// Runtime Properties
	// =========================================================================

	/**
	 * Clean Tool DTO (protocol-only).
	 *
	 * @var \WP\McpSchema\Server\Tools\Tool
	 */
	private Tool $tool;

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
	 *
	 * @param \WP\McpSchema\Server\Tools\Tool $tool The Tool DTO.
	 */
	private function __construct( Tool $tool ) {
		$this->tool = $tool;
	}

	// =========================================================================
	// Factory Methods
	// =========================================================================

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

		// Prepare input schema - ensure it's an object type for MCP compliance.
		$input_schema = $config['inputSchema'] ?? array( 'type' => 'object' );
		if ( ! isset( $input_schema['type'] ) ) {
			$input_schema['type'] = 'object';
		}

		// Build tool data array.
		$tool_data = array(
			'name'        => $config['name'],
			'inputSchema' => $input_schema,
		);

		// Optional fields.
		if ( isset( $config['title'] ) ) {
			$tool_data['title'] = $config['title'];
		}

		if ( isset( $config['description'] ) ) {
			$tool_data['description'] = $config['description'];
		}

		if ( isset( $config['outputSchema'] ) && is_array( $config['outputSchema'] ) ) {
			$tool_data['outputSchema'] = $config['outputSchema'];
		}

		// Process annotations.
		if ( isset( $config['annotations'] ) && is_array( $config['annotations'] ) && ! empty( $config['annotations'] ) ) {
			$tool_data['annotations'] = ToolAnnotations::fromArray( $config['annotations'] );
		}

		// Validate and prepare icons if set.
		if ( isset( $config['icons'] ) && is_array( $config['icons'] ) && ! empty( $config['icons'] ) ) {
			$icons_result = McpValidator::validate_icons_array( $config['icons'] );
			if ( ! empty( $icons_result['valid'] ) ) {
				$tool_data['icons'] = $icons_result['valid'];
			}
		}

		// Preserve user-provided _meta as-is.
		if ( isset( $config['meta'] ) && is_array( $config['meta'] ) && ! empty( $config['meta'] ) ) {
			$tool_data['_meta'] = $config['meta'];
		}

		// Create the Tool DTO.
		$tool = Tool::fromArray( $tool_data );

		$instance          = new self( $tool );
		$instance->handler = $config['handler'];

		if ( isset( $config['permission'] ) && is_callable( $config['permission'] ) ) {
			$instance->permission_callback = $config['permission'];
		}

		$instance->observability_context = array(
			'component_type' => 'tool',
			'tool_name'      => $config['name'],
			'source'         => 'array',
		);

		return $instance;
	}

	/**
	 * Create an ability-backed MCP tool.
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

		$instance               = new self( $tool_data['tool'] );
		$instance->adapter_meta = $tool_data['adapter_meta'];
		$instance->ability      = $ability;

		$instance->observability_context = array(
			'component_type' => 'tool',
			'tool_name'      => $tool_data['tool']->getName(),
			'ability_name'   => $ability->get_name(),
			'source'         => 'ability',
		);

		return $instance;
	}

	// =========================================================================
	// McpComponentInterface Implementation
	// =========================================================================

	/**
	 * Get the clean protocol DTO for MCP responses.
	 *
	 * @return \WP\McpSchema\Server\Tools\Tool
	 */
	public function get_component(): Tool {
		return $this->tool;
	}

	/**
	 * Get the human-readable name for this tool.
	 *
	 * @return string
	 */
	public function get_name(): string {
		$title = $this->tool->getTitle();

		return null !== $title && '' !== trim( $title ) ? $title : $this->tool->getName();
	}

	/**
	 * Execute the tool.
	 *
	 * @param mixed $arguments Tool arguments.
	 *
	 * @return mixed
	 */
	public function execute( $arguments ) {
		$args = $this->unwrap_input_if_needed( $arguments );

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
		$args = $this->unwrap_input_if_needed( $arguments );

		// Ability-backed tools delegate to the ability's permission system.
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
				'tool_name'      => $this->tool->getName(),
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
	// Private Helper Methods
	// =========================================================================

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
