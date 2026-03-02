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
use WP\MCP\Infrastructure\Observability\FailureReason;
use WP\McpSchema\Common\Protocol\DTO\Annotations;
use WP\McpSchema\Server\Resources\DTO\Resource as ResourceDto;
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
 * McpResource wraps a protocol-only ResourceDto for MCP serialization. Internal
 * adapter metadata and execution wiring live on this class and are never
 * exposed to MCP clients. Use get_protocol_dto() for protocol responses.
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
	 * @var \WP\McpSchema\Server\Resources\DTO\Resource
	 */
	private ResourceDto $mcp_resource_dto;

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
	 * @param \WP\McpSchema\Server\Resources\DTO\Resource $resource_dto The Resource DTO.
	 */
	private function __construct( ResourceDto $resource_dto ) {
		$this->mcp_resource_dto = $resource_dto;
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
		if ( empty( $config['uri'] ) ) {
			return new WP_Error( 'mcp_resource_missing_uri', 'Resource configuration must include a "uri" field.' );
		}

		if ( ! isset( $config['handler'] ) || ! is_callable( $config['handler'] ) ) {
			return new WP_Error( 'mcp_resource_missing_handler', 'Resource configuration must include a callable "handler" field.' );
		}

		$uri = trim( $config['uri'] );

		if ( ! McpValidator::validate_resource_uri( $uri ) ) {
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

		// Create the Resource DTO - wrap in try-catch since Annotations::fromArray() and ResourceDto::fromArray() can throw.
		try {
			// Process annotations inside try-catch since Annotations::fromArray() can throw.
			if ( isset( $config['annotations'] ) && is_array( $config['annotations'] ) && ! empty( $config['annotations'] ) ) {
				$resource_data['annotations'] = Annotations::fromArray( $config['annotations'] );
			}

			$resource = ResourceDto::fromArray( $resource_data );
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'mcp_resource_dto_creation_failed',
				sprintf(
				/* translators: %s: error message */
					__( 'Failed to create Resource DTO: %s', 'mcp-adapter' ),
					$e->getMessage()
				),
				array( 'exception' => $e )
			);
		}

		// Optional deep validation if enabled.
		$mcp_validation_enabled = apply_filters( 'mcp_adapter_validation_enabled', false );
		if ( $mcp_validation_enabled ) {
			$validation_result = McpResourceValidator::validate_resource_dto( $resource );
			if ( is_wp_error( $validation_result ) ) {
				return $validation_result;
			}
		}

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
	 * @return \WP\McpSchema\Server\Resources\DTO\Resource
	 */
	public function get_protocol_dto(): ResourceDto {
		return $this->mcp_resource_dto;
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
				return new WP_Error(
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
				return new WP_Error(
					'mcp_execution_failed',
					$throwable->getMessage(),
					array( 'error_type' => get_class( $throwable ) )
				);
			}
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
			try {
				return $this->ability->check_permissions();
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
				$result = call_user_func( $this->permission_callback, $arguments );

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
