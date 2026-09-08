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
use WP\MCP\Domain\Utils\RevisionProjectionTrait;
use WP\MCP\Domain\Utils\ThrowableGuardTrait;
use WP\MCP\Infrastructure\Observability\FailureReason;
use WP\McpSchema\Record\Tool;
use WP\McpSchema\Schema;
use WP_Error;

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
 * McpTool stores revision-neutral configuration for MCP projection. Internal
 * adapter metadata and execution wiring live on this class and are never
 * exposed to MCP clients. Use get_protocol_record() for protocol responses.
 *
 * @since 0.5.0
 */
final class McpTool implements McpComponentInterface {
	use RevisionProjectionTrait;
	use ThrowableGuardTrait;

	// =========================================================================
	// Runtime Properties
	// =========================================================================

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

	/** @var array<int, array{name: string, path: list<string>}>|null */
	private ?array $header_annotations = null;

	// =========================================================================
	// Constructor
	// =========================================================================

	/**
	 * Private constructor - use factory methods.
	 *
	 * @param array<string, mixed> $tool_data Revision-neutral tool data.
	 */
	private function __construct( array $tool_data ) {
		$this->initialize_protocol_data( $tool_data );
	}

	// =========================================================================
	// Factory Methods
	// =========================================================================

	/**
	 * Create a tool definition from an array configuration.
	 *
	 * @param array $config The tool configuration array.
	 *
	 * @return self|\WP_Error
	 */
	public static function fromArray( array $config ) {
		if ( empty( $config['name'] ) ) {
			return new WP_Error( 'mcp_tool_missing_name', 'Tool configuration must include a "name" field.' );
		}

		if ( ! isset( $config['handler'] ) || ! is_callable( $config['handler'] ) ) {
			return new WP_Error( 'mcp_tool_missing_handler', 'Tool configuration must include a callable "handler" field.' );
		}

		// A tool without an input schema gets the empty object schema the official SDK
		// emits for that case. A schema that is set is carried as given; the MCP schema
		// decides whether it fits.
		$tool_data = array(
			'name'        => $config['name'],
			'inputSchema' => $config['inputSchema'] ?? array( 'type' => 'object' ),
		);

		// Optional fields.
		if ( isset( $config['title'] ) ) {
			$tool_data['title'] = $config['title'];
		}

		if ( isset( $config['description'] ) ) {
			$tool_data['description'] = $config['description'];
		}

		// outputSchema, icons, _meta, annotations, and execution are carried as given;
		// the schema decides whether they fit.
		if ( isset( $config['outputSchema'] ) ) {
			$tool_data['outputSchema'] = $config['outputSchema'];
		}

		if ( isset( $config['icons'] ) ) {
			$tool_data['icons'] = $config['icons'];
		}

		if ( isset( $config['meta'] ) ) {
			$tool_data['_meta'] = $config['meta'];
		}

		if ( isset( $config['annotations'] ) ) {
			$tool_data['annotations'] = $config['annotations'];
		}

		if ( isset( $config['execution'] ) ) {
			$tool_data['execution'] = $config['execution'];
		}

		$instance          = new self( $tool_data );
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
		if ( $tool_data instanceof WP_Error ) {
			return $tool_data;
		}

		$instance               = new self( $tool_data['tool_data'] );
		$instance->adapter_meta = $tool_data['adapter_meta'];
		$instance->ability      = $ability;

		$instance->observability_context = array(
			'component_type' => 'tool',
			'tool_name'      => $tool_data['tool_data']['name'],
			'ability_name'   => $ability->get_name(),
			'source'         => 'ability',
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
	public function get_protocol_record( Schema $schema ): Tool {
		$data = $this->protocol_data();
		if ( '2026-07-28' === $schema->version() ) {
			unset( $data['execution'] );
			$projection_error = $this->get_projection_error( $schema->version() );
			if ( $projection_error instanceof \Throwable ) {
				throw $projection_error;
			}
			if ( null === $this->header_annotations ) {
				try {
					$this->header_annotations = $this->collect_header_annotations( $data['inputSchema'] ?? array() );
				} catch ( \Throwable $throwable ) {
					$this->remember_projection_error( $schema->version(), $throwable );
					throw $throwable;
				}
			}
		}

		return $this->project_record( $schema, Tool::class, $data );
	}

	/**
	 * Get the neutral tool name.
	 *
	 * @since n.e.x.t
	 */
	public function get_name(): string {
		return (string) ( $this->protocol_data()['name'] ?? '' );
	}

	/**
	 * Return validated modern HTTP header annotations.
	 *
	 * @return array<int, array{name: string, path: list<string>}>
	 * @since n.e.x.t
	 */
	public function get_header_annotations( Schema $schema ): array {
		if ( '2026-07-28' !== $schema->version() ) {
			return array();
		}

		$this->get_protocol_record( $schema );

		return $this->header_annotations ?? array();
	}

	/**
	 * Execute the tool.
	 *
	 * @param mixed $arguments Tool arguments.
	 * @param \WP\MCP\Domain\Tools\McpToolCallContext|null $call_context Request context for direct callable handlers; not passed to Abilities.
	 *
	 * @return mixed
	 *
	 * @since n.e.x.t Added the `$call_context` parameter.
	 */
	public function execute( $arguments, ?McpToolCallContext $call_context = null ) {
		$args = $this->unwrap_input_if_needed( $arguments );

		if ( null !== $this->ability ) {
			$ability = $this->ability;
			$args    = AbilityArgumentNormalizer::normalize( $ability, $args );
			$result  = self::guard( 'mcp_execution_failed', static fn() => $ability->execute( $args ) );
		} elseif ( null !== $this->handler ) {
			$handler = $this->handler;
			$result  = self::guard( 'mcp_execution_failed', static fn() => call_user_func( $handler, $args, $call_context ) );
		} else {
			return new WP_Error( 'mcp_tool_no_handler', 'No tool execution strategy configured.' );
		}

		if ( $result instanceof WP_Error ) {
			return $result;
		}
		if ( $result instanceof McpInputRequired ) {
			return $result;
		}

		$result = $this->wrap_output_if_needed( $result );

		if ( ! is_array( $result ) ) {
			$result = array( 'result' => $result );
		}

		return $result;
	}

	/**
	 * Whether this tool delegates execution to a WordPress Ability.
	 *
	 * @since n.e.x.t
	 */
	public function is_ability_backed(): bool {
		return null !== $this->ability;
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
			$ability = $this->ability;
			$args    = AbilityArgumentNormalizer::normalize( $ability, $args );

			return self::guard( 'mcp_permission_check_failed', static fn() => $ability->check_permissions( $args ) );
		}

		// Callable-backed tools use their required permission callback.
		if ( null !== $this->permission_callback ) {
			$callback = $this->permission_callback;
			$result   = self::guard( 'mcp_permission_check_failed', static fn() => call_user_func( $callback, $args ) );

			return $result instanceof WP_Error ? $result : (bool) $result;
		}

		// Defensive fallback: should never reach here if factories are used correctly.
		return new WP_Error(
			'mcp_permission_denied',
			'Access denied.',
			array(
				'failure_reason' => FailureReason::NO_PERMISSION_STRATEGY,
				'tool_name'      => $this->get_name(),
			)
		);
	}

	// =========================================================================
	// Private Helper Methods
	// =========================================================================

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

	/**
	 * Collect and validate the 2026 x-mcp-header definitions.
	 *
	 * @param mixed $schema Tool input schema.
	 * @return array<int, array{name: string, path: list<string>}>
	 */
	private function collect_header_annotations( $schema ): array {
		if ( ! is_array( $schema ) ) {
			return array();
		}

		$names       = array();
		$annotations = array();
		$this->scan_header_annotations( $schema, false, true, array(), $names, $annotations );

		return $annotations;
	}

	/**
	 * Scan header annotations and fail a revision projection on invalid placement.
	 *
	 * @param mixed $node Schema node.
	 * @param bool $property_schema Whether this node is a property schema.
	 * @param bool $reachable Whether its path contains only properties keys.
	 * @param list<string> $path Property path.
	 * @param array<string, true> $names Case-insensitive header names.
	 * @param array<int, array{name: string, path: list<string>}> $annotations Valid annotations.
	 */
	private function scan_header_annotations( $node, bool $property_schema, bool $reachable, array $path, array &$names, array &$annotations ): void {
		if ( ! is_array( $node ) ) {
			return;
		}

		if ( array_key_exists( 'x-mcp-header', $node ) ) {
			$name = $node['x-mcp-header'];
			$type = $node['type'] ?? null;
			if ( ! $property_schema || ! $reachable || ! is_string( $name ) || ! preg_match( "/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/", $name ) ) {
				throw new \InvalidArgumentException( 'Invalid x-mcp-header annotation placement or name.' );
			}
			if ( ! in_array( $type, array( 'string', 'integer', 'boolean' ), true ) ) {
				throw new \InvalidArgumentException( 'x-mcp-header annotations require string, integer, or boolean properties.' );
			}

			$folded = strtolower( $name );
			if ( isset( $names[ $folded ] ) ) {
				throw new \InvalidArgumentException( 'x-mcp-header names must be case-insensitively unique.' );
			}
			$names[ $folded ] = true;
			$annotations[]    = array(
				'name' => $name,
				'path' => $path,
			);
		}

		foreach ( $node as $keyword => $value ) {
			if ( 'properties' === $keyword && is_array( $value ) ) {
				foreach ( $value as $property_name => $property ) {
					$property_path   = $path;
					$property_path[] = (string) $property_name;
					$this->scan_header_annotations( $property, true, $reachable, $property_path, $names, $annotations );
				}
				continue;
			}

			if ( ! is_array( $value ) ) {
				continue;
			}

			$this->scan_header_annotations( $value, false, false, $path, $names, $annotations );
		}
	}
}
