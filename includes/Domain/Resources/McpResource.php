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
use WP\MCP\Domain\Utils\RevisionProjectionTrait;
use WP\MCP\Domain\Utils\ThrowableGuardTrait;
use WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface;
use WP\MCP\Infrastructure\Observability\FailureReason;
use WP\McpSchema\Record\Resource;
use WP\McpSchema\Schema;
use WP_Error;

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
 * McpResource stores revision-neutral configuration for MCP projection. Internal
 * adapter metadata and execution wiring live on this class and are never
 * exposed to MCP clients. Use get_protocol_record() for protocol responses.
 *
 * @since 0.5.0
 */
final class McpResource implements McpComponentInterface {
	use RevisionProjectionTrait;
	use ThrowableGuardTrait;

	// =========================================================================
	// Runtime Properties
	// =========================================================================

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
	 * @param array<string, mixed> $resource_data Revision-neutral Resource data.
	 */
	private function __construct( array $resource_data ) {
		$this->initialize_protocol_data( $resource_data );
	}

	// =========================================================================
	// Factory Methods
	// =========================================================================

	/**
	 * @param array $config The resource configuration array.
	 *
	 * @return self|\WP_Error
	 */
	public static function fromArray( array $config ) {
		if ( ! isset( $config['uri'] ) ) {
			return new WP_Error( 'mcp_resource_missing_uri', 'Resource configuration must include a "uri" field.' );
		}

		if ( ! isset( $config['handler'] ) || ! is_callable( $config['handler'] ) ) {
			return new WP_Error( 'mcp_resource_missing_handler', 'Resource configuration must include a callable "handler" field.' );
		}

		// The URI is the registry key, so it is matched as given: no trimming.
		$uri = $config['uri'];
		if ( ! is_string( $uri ) || ! McpValidator::validate_resource_uri( $uri ) ) {
			return new WP_Error( 'mcp_resource_invalid_uri', 'Resource "uri" must be a valid RFC 3986 URI with a scheme.' );
		}

		$name = isset( $config['name'] ) ? trim( $config['name'] ) : $uri;
		if ( '' === $name ) {
			return new WP_Error( 'mcp_resource_missing_name', 'Resource "name" cannot be empty.' );
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

		// mimeType and size are carried as given; the schema decides whether they fit.
		if ( isset( $config['mimeType'] ) ) {
			$resource_data['mimeType'] = $config['mimeType'];
		}

		if ( isset( $config['size'] ) ) {
			$resource_data['size'] = $config['size'];
		}

		// Icons and _meta are carried as given; the schema decides whether they fit.
		if ( isset( $config['icons'] ) ) {
			$resource_data['icons'] = $config['icons'];
		}

		if ( isset( $config['meta'] ) ) {
			$resource_data['_meta'] = $config['meta'];
		}

		// Annotations are carried as given. The one adapter check is lastModified, which
		// the official client requires as an ISO timestamp with a time zone; it runs only
		// when the value is an array, and a failure rejects the resource.
		if ( isset( $config['annotations'] ) ) {
			$annotation_errors = is_array( $config['annotations'] )
				? McpValidator::get_annotation_validation_errors( $config['annotations'] )
				: array();
			if ( ! empty( $annotation_errors ) ) {
				return new WP_Error(
					'mcp_resource_invalid_annotations',
					sprintf( 'Resource "%s" has invalid annotations: %s', $uri, implode( '; ', $annotation_errors ) )
				);
			}

			$resource_data['annotations'] = $config['annotations'];
		}

		$instance          = new self( $resource_data );
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
	 * @param \WP_Ability $ability WordPress ability.
	 * @param \WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface|null $error_handler Optional error handler.
	 *
	 * @return self|\WP_Error
	 */
	public static function fromAbility( \WP_Ability $ability, ?McpErrorHandlerInterface $error_handler = null ) {
		$resource_data = RegisterAbilityAsMcpResource::build( $ability, $error_handler );
		if ( $resource_data instanceof WP_Error ) {
			return $resource_data;
		}

		$instance               = new self( $resource_data['resource_data'] );
		$instance->adapter_meta = $resource_data['adapter_meta'];
		$instance->ability      = $ability;

		$instance->observability_context = array(
			'component_type' => 'resource',
			'resource_uri'   => $resource_data['resource_data']['uri'],
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
	public function get_protocol_record( Schema $schema ): Resource {
		return $this->project_record( $schema, Resource::class, $this->protocol_data() );
	}

	/**
	 * Get the neutral resource URI.
	 *
	 * @since n.e.x.t
	 */
	public function get_uri(): string {
		return (string) ( $this->protocol_data()['uri'] ?? '' );
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
			$ability = $this->ability;

			return self::guard( 'mcp_execution_failed', static fn() => $ability->execute() );
		}

		if ( null !== $this->handler ) {
			$handler = $this->handler;

			return self::guard( 'mcp_execution_failed', static fn() => call_user_func( $handler, $arguments ) );
		}

		return new WP_Error( 'mcp_resource_no_handler', 'No resource execution strategy configured.' );
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
			$ability = $this->ability;

			return self::guard( 'mcp_permission_check_failed', static fn() => $ability->check_permissions() );
		}

		if ( null !== $this->permission_callback ) {
			$callback = $this->permission_callback;
			$result   = self::guard( 'mcp_permission_check_failed', static fn() => call_user_func( $callback, $arguments ) );

			return $result instanceof WP_Error ? $result : (bool) $result;
		}

		return new WP_Error(
			'mcp_permission_denied',
			'Access denied.',
			array( 'failure_reason' => FailureReason::NO_PERMISSION_STRATEGY )
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
