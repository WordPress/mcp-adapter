<?php
/**
 * Unified MCP Resource wrapper with fluent API.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Domain\Resources;

use WP\MCP\Domain\Contracts\McpComponentInterface;
use WP\MCP\Domain\Utils\McpValidator;
use WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface;
use WP\McpSchema\Common\AbstractDataTransferObject;
use WP\McpSchema\Common\Protocol\Annotations;
use WP\McpSchema\Server\Resources\Resource;

/**
 * Resource wrapper providing unified execution and permission checks.
 *
 * This class supports multiple ways to register resources:
 *
 * 1. Fluent API (direct callable):
 * ```php
 * $resource = McpResource::create('WordPress://local/readme')
 *     ->title('README')
 *     ->mimeType('text/plain')
 *     ->handler(fn() => 'Hello')
 *     ->permission(fn() => current_user_can('read'));
 * ```
 *
 * 2. Array configuration:
 * ```php
 * $resource = McpResource::fromArray([
 *     'uri'         => 'WordPress://local/readme',
 *     'title'       => 'README',
 *     'description' => 'Example resource',
 *     'handler'     => fn() => 'Hello',
 *     'permission'  => fn() => true,
 * ]);
 * ```
 *
 * 3. From WordPress Ability (ability-backed):
 * ```php
 * $resource = McpResource::fromAbility($ability);
 * ```
 *
 * @since n.e.x.t
 */
final class McpResource implements McpComponentInterface {

	// =========================================================================
	// Fluent Builder Properties
	// =========================================================================

	/**
	 * The resource URI (unique identifier).
	 *
	 * @var string|null
	 */
	private ?string $uri = null;

	/**
	 * The resource name (required by schema).
	 *
	 * @var string|null
	 */
	private ?string $name = null;

	/**
	 * The resource title (human-readable display name).
	 *
	 * @var string|null
	 */
	private ?string $title_value = null;

	/**
	 * The resource description.
	 *
	 * @var string|null
	 */
	private ?string $description_value = null;

	/**
	 * The resource MIME type.
	 *
	 * @var string|null
	 */
	private ?string $mime_type_value = null;

	/**
	 * The resource size in bytes (raw content size).
	 *
	 * @var int|null
	 */
	private ?int $size_value = null;

	/**
	 * The resource annotations.
	 *
	 * @var array{audience?: array<int, string>, priority?: float, lastModified?: string}
	 */
	private array $annotations_value = array();

	/**
	 * The resource icons for UI display.
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
	 * Whether the resource was built using fluent API.
	 *
	 * @var bool
	 */
	private bool $is_fluent = false;

	// =========================================================================
	// Runtime Properties
	// =========================================================================

	/**
	 * Clean Resource DTO (protocol-only).
	 *
	 * @var \WP\McpSchema\Server\Resources\Resource|null
	 */
	private ?Resource $resource = null;

	/**
	 * Ability used for execution/permission checks (ability-backed resources).
	 *
	 * @var \WP_Ability|null
	 */
	private ?\WP_Ability $ability = null;

	/**
	 * Direct execution handler (callable-backed resources).
	 *
	 * @var callable|null
	 */
	private $handler = null;

	/**
	 * Direct permission callback (callable-backed resources).
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
	 * Create a new resource definition with fluent API.
	 *
	 * The Resource DTO requires a `name`. When using the fluent builder, the default name is
	 * set to the URI unless overridden via `->name()`.
	 *
	 * @param string $uri The resource URI (RFC 3986 with scheme).
	 *
	 * @return self
	 */
	public static function create( string $uri ): self {
		$instance            = new self();
		$instance->uri       = $uri;
		$instance->name      = $uri;
		$instance->is_fluent = true;

		return $instance;
	}

	/**
	 * Create a resource definition from an array configuration.
	 *
	 * @param array{
	 *     uri: string,
	 *     name?: string,
	 *     title?: string,
	 *     description?: string,
	 *     mimeType?: string,
	 *     size?: int,
	 *     annotations?: array{audience?: array<int, string>, priority?: float, lastModified?: string},
	 *     icons?: array<int, array{src: string, mimeType?: string, sizes?: array<string>, theme?: string}>,
	 *     meta?: array<string, mixed>,
	 *     handler: callable(mixed): mixed,
	 *     permission?: callable(mixed): bool
	 * } $config The resource configuration array.
	 *
	 * @return self
	 */
	public static function fromArray( array $config ): self {
		if ( empty( $config['uri'] ) ) {
			throw new \InvalidArgumentException( 'Resource configuration must include a "uri" field.' );
		}

		if ( ! isset( $config['handler'] ) || ! is_callable( $config['handler'] ) ) {
			throw new \InvalidArgumentException( 'Resource configuration must include a callable "handler" field.' );
		}

		$instance            = new self();
		$instance->uri       = $config['uri'];
		$instance->name      = $config['name'] ?? $config['uri'];
		$instance->is_fluent = true;

		if ( isset( $config['title'] ) ) {
			$instance->title_value = $config['title'];
		}

		if ( isset( $config['description'] ) ) {
			$instance->description_value = $config['description'];
		}

		if ( isset( $config['mimeType'] ) ) {
			$instance->mime_type_value = $config['mimeType'];
		}

		if ( isset( $config['size'] ) ) {
			$instance->size_value = $config['size'];
		}

		if ( isset( $config['annotations'] ) && is_array( $config['annotations'] ) ) {
			$instance->annotations_value = $config['annotations'];
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
	 * Create an ability-backed resource wrapper.
	 *
	 * @param \WP_Ability                                                              $ability WordPress ability.
	 * @param \WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface|null $error_handler Optional error handler.
	 *
	 * @return self|\WP_Error
	 */
	public static function fromAbility( \WP_Ability $ability, ?McpErrorHandlerInterface $error_handler = null ) {
		$resource_data = RegisterAbilityAsMcpResource::build( $ability, $error_handler );
		if ( $resource_data instanceof \WP_Error ) {
			return $resource_data;
		}

		$instance               = new self();
		$instance->resource     = $resource_data['resource'];
		$instance->adapter_meta = $resource_data['adapter_meta'];
		$instance->ability      = $ability;

		$instance->observability_context = array(
			'component_type' => 'resource',
			'resource_uri'   => $instance->resource->getUri(),
			'ability_name'   => $ability->get_name(),
			'source'         => 'ability',
		);

		return $instance;
	}

	// =========================================================================
	// Fluent Setters
	// =========================================================================

	/**
	 * Set the resource name (required by schema).
	 *
	 * @param string $name The resource name.
	 *
	 * @return self
	 */
	public function name( string $name ): self {
		$this->name = $name;
		return $this;
	}

	/**
	 * Set the resource title.
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
	 * Set the resource description.
	 *
	 * @param string $description The resource description.
	 *
	 * @return self
	 */
	public function description( string $description ): self {
		$this->description_value = $description;
		return $this;
	}

	/**
	 * Set the resource MIME type.
	 *
	 * @param string $mime_type The MIME type.
	 *
	 * @return self
	 */
	public function mimeType( string $mime_type ): self {
		$this->mime_type_value = $mime_type;
		return $this;
	}

	/**
	 * Set the resource size.
	 *
	 * @param int $size The raw content size in bytes.
	 *
	 * @return self
	 */
	public function size( int $size ): self {
		$this->size_value = $size;
		return $this;
	}

	/**
	 * Set resource annotations.
	 *
	 * @param array{audience?: array<int, string>, priority?: float, lastModified?: string} $annotations Annotations.
	 *
	 * @return self
	 */
	public function annotations( array $annotations ): self {
		$this->annotations_value = $annotations;
		return $this;
	}

	/**
	 * Set the resource icons for UI display.
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
	 * Set the handler callable for resource reading.
	 *
	 * @param callable(mixed): mixed $handler The handler callable.
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
	 * @param callable(mixed): bool $callback The permission callback.
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
		return $this->get_resource();
	}

	/**
	 * Get the human-readable name for this resource.
	 *
	 * @return string
	 */
	public function get_name(): string {
		$resource = $this->get_resource();
		$title    = $resource->getTitle();

		return null !== $title && '' !== trim( $title ) ? $title : $resource->getName();
	}

	/**
	 * Execute the resource read.
	 *
	 * @param mixed $arguments Read arguments (may be empty).
	 *
	 * @return mixed
	 */
	public function execute( $arguments ) {
		$this->get_resource();

		// Ability-backed resources match existing behavior: no args passed to abilities.
		if ( null !== $this->ability ) {
			try {
				return $this->ability->execute();
			} catch ( \Throwable $throwable ) {
				return new \WP_Error(
					'mcp_execution_failed',
					$throwable->getMessage(),
					array( 'error_type' => get_class( $throwable ) )
				);
			}
		}

		if ( null !== $this->handler ) {
			try {
				return call_user_func( $this->handler, $arguments );
			} catch ( \Throwable $throwable ) {
				return new \WP_Error(
					'mcp_execution_failed',
					$throwable->getMessage(),
					array( 'error_type' => get_class( $throwable ) )
				);
			}
		}

		return new \WP_Error( 'mcp_resource_no_handler', 'No resource execution strategy configured.' );
	}

	/**
	 * Check whether the current request has permission to read this resource.
	 *
	 * @param mixed $arguments Read arguments (may be empty).
	 *
	 * @return bool|\WP_Error
	 */
	public function check_permission( $arguments ) {
		$this->get_resource();

		// Ability-backed resources match existing behavior: no args passed to abilities.
		if ( null !== $this->ability ) {
			try {
				return $this->ability->check_permissions();
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
				return $this->invoke_permission_callback( $arguments );
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
	 * Get internal adapter metadata for this resource.
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
	 * Build and cache the Resource DTO from fluent configuration.
	 *
	 * @return self
	 */
	private function build(): self {
		if ( null === $this->uri || '' === trim( $this->uri ) ) {
			throw new \RuntimeException( 'Resource uri is required. Use create() or fromArray() to set a uri.' );
		}

		$uri = trim( $this->uri );

		if ( ! McpValidator::validate_resource_uri( $uri ) ) {
			throw new \InvalidArgumentException( 'Resource "uri" must be a valid RFC 3986 URI with a scheme.' );
		}

		$name = $this->name;
		$name = is_string( $name ) ? trim( $name ) : '';
		if ( '' === $name ) {
			throw new \RuntimeException( 'Resource name is required. Use ->name() to set it.' );
		}

		$resource_data = array(
			'name' => $name,
			'uri'  => $uri,
		);

		if ( null !== $this->title_value ) {
			$resource_data['title'] = $this->title_value;
		}

		if ( null !== $this->description_value ) {
			$resource_data['description'] = $this->description_value;
		}

		// Include mimeType only when valid.
		if ( null !== $this->mime_type_value ) {
			$mime_type = trim( $this->mime_type_value );
			if ( '' !== $mime_type && McpValidator::validate_mime_type( $mime_type ) ) {
				$resource_data['mimeType'] = $mime_type;
			}
		}

		// Include size only when > 0.
		if ( null !== $this->size_value && $this->size_value > 0 ) {
			$resource_data['size'] = $this->size_value;
		}

		// Include annotations only when meaningful.
		if ( ! empty( $this->annotations_value ) ) {
			$resource_data['annotations'] = Annotations::fromArray( $this->annotations_value );
		}

		// Validate and include icons if set.
		if ( ! empty( $this->icons_value ) ) {
			$icons_result = McpValidator::validate_icons_array( $this->icons_value );
			if ( ! empty( $icons_result['valid'] ) ) {
				$resource_data['icons'] = $icons_result['valid'];
			}
		}

			$_meta = $this->meta_value;
		if ( ! empty( $_meta ) ) {
			$resource_data['_meta'] = $_meta;
		}

		$this->resource = Resource::fromArray( $resource_data );

		$this->observability_context = array(
			'component_type' => 'resource',
			'resource_uri'   => $uri,
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
	 * Get the Resource DTO, building it if needed.
	 *
	 * @return \WP\McpSchema\Server\Resources\Resource
	 */
	private function get_resource(): Resource {
		if ( null === $this->resource && $this->is_fluent ) {
			$this->build();
		}

		if ( null === $this->resource ) {
			throw new \RuntimeException( 'Resource DTO not initialized. Use create(), fromArray(), or fromAbility().' );
		}

		return $this->resource;
	}

	/**
	 * Invoke the permission callback, supporting both 0-arg and 1-arg callables.
	 *
	 * @param mixed $args Read arguments.
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
}
