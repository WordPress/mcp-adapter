<?php

/**
 * RegisterAbilityAsMcpResource class for converting WordPress abilities to MCP resources.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Domain\Resources;

use WP\MCP\Domain\Utils\McpAnnotationMapper;
use WP\MCP\Domain\Utils\McpValidator;
use WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface;
use WP\McpSchema\Server\Resources\DTO\Resource as ResourceDto;
use WP_Error;

/**
 * Converts WordPress abilities to MCP Resource metadata.
 *
 * This class builds Resource DTOs for resources/list responses.
 * It extracts metadata only (uri, name, title, description, mimeType, size, icons, annotations).
 * Resource content (text/blob) is resolved separately at resources/read time.
 *
 * All MCP-specific ability meta should be under 'mcp' key:
 *
 * Required ability meta:
 * - 'mcp.uri' (string): The resource URI (RFC 3986 format)
 *
 * Optional ability meta:
 * - 'mcp.mimeType' (string): MIME type of the resource content
 * - 'mcp.size' (int): Size of resource content in bytes
 * - 'mcp.annotations' (array): MCP annotations (audience, priority, lastModified)
 * - 'mcp.icons' (array): Array of icon objects for UI display
 * - 'mcp._meta' (array): User-provided metadata to pass through
 *
 * Note: Top-level meta keys 'uri', 'mimeType', 'annotations' are deprecated as of n.e.x.t.
 * They still work for backward compatibility but will trigger a `_doing_it_wrong` notice.
 * Use 'mcp.uri', 'mcp.mimeType', 'mcp.annotations' instead.
 *
 * @since n.e.x.t
 */
class RegisterAbilityAsMcpResource {

	/**
	 * The WordPress ability instance.
	 *
	 * @var \WP_Ability
	 */
	private \WP_Ability $ability;

	/**
	 * Optional error handler for logging deprecation notices.
	 *
	 * @var \WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface|null
	 */
	private ?McpErrorHandlerInterface $error_handler;

	/**
	 * Constructor.
	 *
	 * @param \WP_Ability $ability The ability.
	 * @param \WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface|null $error_handler Optional error handler.
	 */
	private function __construct( \WP_Ability $ability, ?McpErrorHandlerInterface $error_handler = null ) {
		$this->ability       = $ability;
		$this->error_handler = $error_handler;
	}

	/**
	 * Make a new instance of the class.
	 *
	 * @param \WP_Ability $ability The ability.
	 * @param \WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface|null $error_handler Optional error handler for logging.
	 *
	 * @return \WP\McpSchema\Server\Resources\DTO\Resource|\WP_Error Returns Resource DTO or WP_Error if validation fails.
	 */
	public static function make( \WP_Ability $ability, ?McpErrorHandlerInterface $error_handler = null ) {
		$resource = new self( $ability, $error_handler );

		return $resource->get_resource();
	}

	/**
	 * Get the MCP resource instance.
	 *
	 * Resource schema validity is enforced by the php-mcp-schema DTO constructor.
	 *
	 * @return \WP\McpSchema\Server\Resources\DTO\Resource|\WP_Error Returns the Resource DTO or WP_Error if validation fails.
	 */
	private function get_resource() {
		$data = $this->get_data();
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		try {
			return ResourceDto::fromArray( $data );
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'mcp_resource_schema_invalid',
				$e->getMessage()
			);
		}
	}

	/**
	 * Get the MCP resource data array.
	 *
	 * Builds metadata-only Resource data. Content (text/blob) is NOT included here;
	 * content is resolved at resources/read time by ResourcesHandler.
	 *
	 * @return array<string,mixed>|\WP_Error Resource data array or WP_Error if validation fails.
	 */
	private function get_data() {
		$built = $this->build_resource_data();
		if ( is_wp_error( $built ) ) {
			return $built;
		}

		return $built['resource_data'];
	}

	/**
	 * Build Resource DTO data and adapter metadata.
	 *
	 * @return array{resource_data: array<string, mixed>, adapter_meta: array<string, mixed>}|\WP_Error
	 * @since n.e.x.t
	 *
	 */
	private function build_resource_data() {
		$uri = $this->get_uri();
		if ( is_wp_error( $uri ) ) {
			return $uri;
		}

		$ability_meta = $this->ability->get_meta();
		$mcp_meta     = $ability_meta['mcp'] ?? array();

		// Required fields.
		$resource_data = array(
			'name' => $this->resolve_resource_name(),
			'uri'  => $uri,
		);

		// Optional: title from ability label (human-readable display name).
		$label = trim( $this->ability->get_label() );
		if ( '' !== $label ) {
			$resource_data['title'] = $label;
		}

		// Optional: description.
		$description = trim( $this->ability->get_description() );
		if ( '' !== $description ) {
			$resource_data['description'] = $description;
		}

		// Optional: mimeType from ability meta (with validation).
		$mime_type = $this->get_mcp_meta( 'mimeType', 'string' );
		if ( null !== $mime_type ) {
			$mime_type = trim( $mime_type );
			if ( McpValidator::validate_mime_type( $mime_type ) ) {
				$resource_data['mimeType'] = $mime_type;
			}
		}

		// Optional: size from ability meta (bytes count for UI display).
		$size = $this->get_mcp_meta( 'size', 'int' );
		if ( null !== $size && $size > 0 ) {
			$resource_data['size'] = $size;
		}

		// Optional: annotations from ability meta (standardized location: mcp.annotations).
		$annotations = $this->get_mcp_meta( 'annotations', 'array' );
		if ( null !== $annotations ) {
			$mcp_annotations = McpAnnotationMapper::map( $annotations, 'resource' );
			if ( ! empty( $mcp_annotations ) ) {
				// Validate annotation values per MCP specification.
				$validation_errors = McpValidator::get_annotation_validation_errors( $mcp_annotations );
				if ( ! empty( $validation_errors ) ) {
					// Log the issue but don't fail registration - drop invalid annotations.
					$this->log_deprecation(
						self::class . '::get_data',
						sprintf(
						/* translators: 1: ability name, 2: validation errors */
							__( 'Invalid annotations for resource ability "%1$s" will be dropped: %2$s', 'mcp-adapter' ),
							$this->ability->get_name(),
							implode( '; ', $validation_errors )
						),
						array( 'validation_errors' => $validation_errors )
					);
				} else {
					$resource_data['annotations'] = $mcp_annotations;
				}
			}
		}

		// Optional: icons from mcp.icons (already in correct location).
		if ( ! empty( $mcp_meta['icons'] ) && is_array( $mcp_meta['icons'] ) ) {
			$icons_result = McpValidator::validate_icons_array( $mcp_meta['icons'] );
			if ( ! empty( $icons_result['valid'] ) ) {
				$resource_data['icons'] = $icons_result['valid'];
			}
		}

		// Build Resource `_meta`:
		// - Preserve user-provided `_meta` from ability.meta.mcp._meta.
		// - Adapter metadata is NEVER included in protocol DTO meta; it is returned separately in adapter_meta.
		$resource_meta = array();
		if ( ! empty( $mcp_meta['_meta'] ) && is_array( $mcp_meta['_meta'] ) ) {
			$resource_meta = $mcp_meta['_meta'];
		}
		if ( ! empty( $resource_meta ) ) {
			$resource_data['_meta'] = $resource_meta;
		}

		$adapter_meta = array(
			'ability' => $this->ability->get_name(),
		);

		return array(
			'resource_data' => $resource_data,
			'adapter_meta'  => $adapter_meta,
		);
	}

	/**
	 * Get the resource URI with validation.
	 *
	 * @return string|\WP_Error URI string or WP_Error if not found or invalid.
	 */
	private function get_uri() {
		$uri = $this->get_mcp_meta( 'uri', 'string' );

		if ( null === $uri ) {
			return new WP_Error(
				'resource_uri_not_found',
				sprintf(
				/* translators: %s: ability name */
					__( "Resource URI not found in ability meta for '%s'. URI must be provided at 'mcp.uri'.", 'mcp-adapter' ),
					$this->ability->get_name()
				)
			);
		}

		$uri = trim( $uri );

		// Validate URI format (RFC 3986).
		if ( ! McpValidator::validate_resource_uri( $uri ) ) {
			return new WP_Error(
				'resource_uri_invalid',
				sprintf(
				/* translators: 1: ability name, 2: invalid URI */
					__( "Invalid resource URI '%2\$s' for ability '%1\$s'. URI must be RFC 3986 compliant with a scheme.", 'mcp-adapter' ),
					$this->ability->get_name(),
					$uri
				)
			);
		}

		/**
		 * Filters the MCP resource URI derived from an ability.
		 *
		 * @since n.e.x.t
		 *
		 * @param string $uri The validated resource URI.
		 * @param \WP_Ability $ability The source ability instance.
		 */
		$filtered_uri = apply_filters( 'mcp_adapter_resource_uri', $uri, $this->ability );

		// Validate post-filter.
		if ( ! is_string( $filtered_uri ) || ! McpValidator::validate_resource_uri( $filtered_uri ) ) {
			return new WP_Error(
				'mcp_resource_uri_filter_invalid',
				sprintf(
				/* translators: %s: invalid URI returned by filter */
					__( 'Filter returned invalid MCP resource URI: %s', 'mcp-adapter' ),
					is_string( $filtered_uri ) ? $filtered_uri : gettype( $filtered_uri )
				)
			);
		}

		return $filtered_uri;
	}

	/**
	 * Get a value from ability meta with standardized lookup.
	 *
	 * Looks in 'mcp' namespace first (preferred), then falls back to top-level (deprecated).
	 * Logs deprecation notice when using top-level location.
	 *
	 * @param string $key The key to look up.
	 * @param string $type Expected type: 'string', 'int', 'array'.
	 * @param mixed $default_value Default value if not found.
	 *
	 * @return mixed The value or default.
	 */
	private function get_mcp_meta( string $key, string $type = 'string', $default_value = null ) {
		$ability_meta = $this->ability->get_meta();
		$mcp_meta     = $ability_meta['mcp'] ?? array();

		// Preferred: Check mcp.{key} first.
		if ( isset( $mcp_meta[ $key ] ) ) {
			$value = $mcp_meta[ $key ];
			if ( $this->validate_type( $value, $type ) ) {
				return $value;
			}
		}

		// Deprecated fallback: Check top-level meta.{key}.
		if ( isset( $ability_meta[ $key ] ) ) {
			$value = $ability_meta[ $key ];
			if ( $this->validate_type( $value, $type ) ) {
				// Log deprecation notice.
				$this->log_deprecation(
					__METHOD__,
					sprintf(
					/* translators: 1: deprecated meta key, 2: new meta key path */
						__( 'Ability meta key "%1$s" is deprecated. Use "mcp.%1$s" instead.', 'mcp-adapter' ),
						$key
					),
					array( 'deprecated_key' => $key )
				);

				return $value;
			}
		}

		return $default_value;
	}

	/**
	 * Validate a value against expected type.
	 *
	 * @param mixed $value The value to validate.
	 * @param string $type Expected type.
	 *
	 * @return bool True if valid.
	 */
	private function validate_type( $value, string $type ): bool {
		switch ( $type ) {
			case 'string':
				return is_string( $value ) && '' !== trim( $value );
			case 'int':
				return is_int( $value ) && $value >= 0;
			case 'array':
				// Array must be non-empty AND have at least one non-null, non-empty value.
				// This prevents false positives when WordPress adds default empty annotations.
				if ( ! is_array( $value ) || empty( $value ) ) {
					return false;
				}
				// Check if any value in the array is actually meaningful (non-null, non-empty string).
				foreach ( $value as $item ) {
					if ( null !== $item && '' !== $item && array() !== $item ) {
						return true;
					}
				}

				return false;
			default:
				return false;
		}
	}

	/**
	 * Log a deprecation notice via both WordPress _doing_it_wrong and McpErrorHandler.
	 *
	 * This ensures deprecation notices are visible both as HTTP headers (WordPress REST API)
	 * and in debug.log (McpErrorHandler).
	 *
	 * @param string $method The method name where deprecation occurred.
	 * @param string $message The deprecation message.
	 * @param array $context Additional context for error handler.
	 *
	 * @return void
	 */
	private function log_deprecation( string $method, string $message, array $context = array() ): void {
		// WordPress standard deprecation notice (appears as X-WP-DoingItWrong header in REST API).
		_doing_it_wrong( esc_html( $method ), esc_html( $message ), 'n.e.x.t' );

		// Also log via McpErrorHandler for debug.log visibility.
		if ( ! $this->error_handler ) {
			return;
		}

		$this->error_handler->log(
			$message,
			array_merge(
				array( 'ability' => $this->ability->get_name() ),
				$context
			),
			'warning'
		);
	}

	/**
	 * Resolve the MCP resource name from ability.
	 *
	 * Resource names have no charset restrictions (unlike Tool names).
	 *
	 * @return string The resolved resource name.
	 */
	private function resolve_resource_name(): string {
		$name = $this->ability->get_name();

		/**
		 * Filters the MCP resource name derived from an ability.
		 *
		 * Unlike tools, resource names have no charset restrictions.
		 *
		 * @since n.e.x.t
		 *
		 * @param string $name The resource name.
		 * @param \WP_Ability $ability The source ability instance.
		 */
		$filtered_name = apply_filters( 'mcp_adapter_resource_name', $name, $this->ability );

		// Resource names have no charset restrictions, so just ensure it's a non-empty string.
		if ( is_string( $filtered_name ) && '' !== trim( $filtered_name ) ) {
			return $filtered_name;
		}

		// Fall back to original name if filter returns invalid value.
		return $name;
	}

	/**
	 * Build a clean Resource DTO and adapter metadata for internal wiring.
	 *
	 * This method returns a protocol-only Resource DTO and provides the adapter metadata
	 * separately. This keeps the DTO stable across MCP spec changes and avoids coupling internal execution
	 * wiring to protocol surfaces.
	 *
	 * @param \WP_Ability $ability The ability.
	 * @param \WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface|null $error_handler Optional error handler.
	 *
	 * @return array{resource: \WP\McpSchema\Server\Resources\DTO\Resource, adapter_meta: array<string, mixed>}|\WP_Error
	 * @since n.e.x.t
	 *
	 */
	public static function build( \WP_Ability $ability, ?McpErrorHandlerInterface $error_handler = null ) {
		$resource = new self( $ability, $error_handler );
		$data     = $resource->build_resource_data();

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		try {
			$resource_dto = ResourceDto::fromArray( $data['resource_data'] );
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'mcp_resource_dto_creation_failed',
				sprintf(
				/* translators: %s: error message */
					__( 'Failed to create Resource DTO for ability %1$s: %2$s', 'mcp-adapter' ),
					$ability->get_name(),
					$e->getMessage()
				),
				array( 'exception' => $e )
			);
		}

		// Optional deep validation if enabled.
		$mcp_validation_enabled = apply_filters( 'mcp_adapter_validation_enabled', false );
		if ( $mcp_validation_enabled ) {
			$validation_result = McpResourceValidator::validate_resource_dto( $resource_dto );
			if ( is_wp_error( $validation_result ) ) {
				return $validation_result;
			}
		}

		return array(
			'resource'     => $resource_dto,
			'adapter_meta' => $data['adapter_meta'],
		);
	}
}
