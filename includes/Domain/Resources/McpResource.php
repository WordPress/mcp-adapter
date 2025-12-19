<?php
/**
 * MCP Resource component.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Domain\Resources;

use WP\MCP\Domain\Contracts\McpComponentInterface;
use WP\MCP\Domain\Utils\McpValidator;
use WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface;
use WP\McpSchema\Common\Protocol\Annotations;
use WP\McpSchema\Server\Resources\Resource;

/**
 * Resource component providing unified execution and permission checks.
 *
 * This class supports multiple ways to register resources:
 *
 * 1. Array configuration:
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
 * 2. From WordPress Ability (ability-backed):
 * ```php
 * $resource = McpResource::fromAbility($ability);
 * ```
 *
 * @since n.e.x.t
 */
final class McpResource implements McpComponentInterface {

	// =========================================================================
	// Runtime Properties
	// =========================================================================

	/**
	 * Clean Resource DTO (protocol-only).
	 *
	 * @var \WP\McpSchema\Server\Resources\Resource
	 */
	private Resource $resource;

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
	 *
	 * @param \WP\McpSchema\Server\Resources\Resource $resource The Resource DTO.
	 */
	private function __construct( Resource $resource ) {
		$this->resource = $resource;
	}

	// =========================================================================
	// Factory Methods
	// =========================================================================

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
	 *
	 * @throws \InvalidArgumentException If required fields are missing or invalid.
	 */
	public static function fromArray( array $config ): self {
		if ( empty( $config['uri'] ) ) {
			throw new \InvalidArgumentException( 'Resource configuration must include a "uri" field.' );
		}

		if ( ! isset( $config['handler'] ) || ! is_callable( $config['handler'] ) ) {
			throw new \InvalidArgumentException( 'Resource configuration must include a callable "handler" field.' );
		}

		$uri = trim( $config['uri'] );

		if ( ! McpValidator::validate_resource_uri( $uri ) ) {
			throw new \InvalidArgumentException( 'Resource "uri" must be a valid RFC 3986 URI with a scheme.' );
		}

		$name = isset( $config['name'] ) ? trim( $config['name'] ) : $uri;
		if ( '' === $name ) {
			throw new \InvalidArgumentException( 'Resource "name" cannot be empty.' );
		}

		$resource_data = array(
			'name' => $name,
			'uri'  => $uri,
		);

		if ( isset( $config['title'] ) ) {
			$resource_data['title'] = $config['title'];
		}

		if ( isset( $config['description'] ) ) {
			$resource_data['description'] = $config['description'];
		}

		// Include mimeType only when valid.
		if ( isset( $config['mimeType'] ) ) {
			$mime_type = trim( $config['mimeType'] );
			if ( '' !== $mime_type && McpValidator::validate_mime_type( $mime_type ) ) {
				$resource_data['mimeType'] = $mime_type;
			}
		}

		// Include size only when > 0.
		if ( isset( $config['size'] ) && $config['size'] > 0 ) {
			$resource_data['size'] = $config['size'];
		}

		// Include annotations only when meaningful.
		if ( isset( $config['annotations'] ) && is_array( $config['annotations'] ) && ! empty( $config['annotations'] ) ) {
			$resource_data['annotations'] = Annotations::fromArray( $config['annotations'] );
		}

		// Validate and include icons if set.
		if ( isset( $config['icons'] ) && is_array( $config['icons'] ) && ! empty( $config['icons'] ) ) {
			$icons_result = McpValidator::validate_icons_array( $config['icons'] );
			if ( ! empty( $icons_result['valid'] ) ) {
				$resource_data['icons'] = $icons_result['valid'];
			}
		}

		if ( isset( $config['meta'] ) && is_array( $config['meta'] ) && ! empty( $config['meta'] ) ) {
			$resource_data['_meta'] = $config['meta'];
		}

		$resource = Resource::fromArray( $resource_data );

		$instance          = new self( $resource );
		$instance->handler = $config['handler'];

		if ( isset( $config['permission'] ) && is_callable( $config['permission'] ) ) {
			$instance->permission_callback = $config['permission'];
		}

		$instance->observability_context = array(
			'component_type' => 'resource',
			'resource_uri'   => $uri,
			'source'         => 'array',
		);

		return $instance;
	}

	/**
	 * Create an ability-backed MCP resource.
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

		$instance               = new self( $resource_data['resource'] );
		$instance->adapter_meta = $resource_data['adapter_meta'];
		$instance->ability      = $ability;

		$instance->observability_context = array(
			'component_type' => 'resource',
			'resource_uri'   => $resource_data['resource']->getUri(),
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
	 * @return \WP\McpSchema\Server\Resources\Resource
	 */
	public function get_component(): Resource {
		return $this->resource;
	}

	/**
	 * Get the human-readable name for this resource.
	 *
	 * @return string
	 */
	public function get_name(): string {
		$title = $this->resource->getTitle();

		return null !== $title && '' !== trim( $title ) ? $title : $this->resource->getName();
	}

	/**
	 * Execute the resource read.
	 *
	 * @param mixed $arguments Read arguments (may be empty).
	 *
	 * @return mixed
	 */
	public function execute( $arguments ) {
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
				$result = call_user_func( $this->permission_callback, $arguments );
				return $result instanceof \WP_Error ? $result : (bool) $result;
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
}
