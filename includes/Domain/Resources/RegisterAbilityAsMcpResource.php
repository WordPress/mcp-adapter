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
use WP_Error;

/**
 * Converts WordPress abilities to MCP Resource metadata.
 *
	 * This class builds neutral Resource metadata for revision projection.
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
 * Values are carried as given; the schema decides whether they fit. A value that
 * does not fit fails projection, so the registry does not expose the resource.
 *
 * Note: Top-level meta keys 'uri', 'mimeType', 'size' are deprecated as of 0.5.0.
 * They still work for backward compatibility but will trigger a `_doing_it_wrong` notice.
 * Use 'mcp.uri', 'mcp.mimeType', 'mcp.size' instead. Top-level 'annotations' is the
 * location WordPress core defines, so it is read without a notice; 'mcp.annotations'
 * overrides it.
 *
 * @since 0.5.0
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
	 * @return array<string, mixed>|\WP_Error Returns Resource data or WP_Error if validation fails.
	 */
	public static function make( \WP_Ability $ability, ?McpErrorHandlerInterface $error_handler = null ) {
		$resource = new self( $ability, $error_handler );

		return $resource->get_resource();
	}

	/**
	 * Get the neutral MCP resource data.
	 *
	 * @return array<string, mixed>|\WP_Error Returns the Resource data or WP_Error.
	 */
	private function get_resource() {
		$data = $this->get_data();
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		return $data;
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
	 * Build Resource data and adapter metadata.
	 *
	 * @return array{resource_data: array<string, mixed>, adapter_meta: array<string, mixed>}|\WP_Error
	 * @since 0.5.0
	 *
	 */
	private function build_resource_data() {
		$uri = $this->get_uri();
		if ( is_wp_error( $uri ) ) {
			return $uri;
		}

		$ability_meta = $this->ability->get_meta();
		$mcp_meta     = $ability_meta['mcp'] ?? array();

		$name = $this->resolve_resource_name();
		if ( is_wp_error( $name ) ) {
			return $name;
		}

		// Required fields.
		$resource_data = array(
			'name' => $name,
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

		// Optional: mimeType and size from ability meta, carried as given.
		$mime_type = $this->get_mcp_meta( 'mimeType' );
		if ( null !== $mime_type ) {
			$resource_data['mimeType'] = $mime_type;
		}

		$size = $this->get_mcp_meta( 'size' );
		if ( null !== $size ) {
			$resource_data['size'] = $size;
		}

		// Optional: annotations. Core defines them at the top level of ability meta and
		// fills every ability with null defaults; mcp.annotations overrides that. The
		// mapper drops the nulls, and an empty mapped result omits the key. A value that
		// is not an array is carried as given for the schema to reject. The one adapter
		// check is lastModified, which the official client requires as an ISO timestamp
		// with a time zone. A failure rejects the resource.
		$annotations = $mcp_meta['annotations'] ?? $ability_meta['annotations'] ?? null;
		if ( ! is_array( $annotations ) ) {
			if ( null !== $annotations ) {
				$resource_data['annotations'] = $annotations;
			}
		} else {
			$mcp_annotations = McpAnnotationMapper::map( $annotations, 'resource' );
			if ( ! empty( $mcp_annotations ) ) {
				$validation_errors = McpValidator::get_annotation_validation_errors( $mcp_annotations );
				if ( ! empty( $validation_errors ) ) {
					return new WP_Error(
						'resource_annotations_invalid',
						sprintf(
						/* translators: 1: ability name, 2: validation errors */
							__( 'Invalid annotations for resource ability "%1$s": %2$s', 'mcp-adapter' ),
							$this->ability->get_name(),
							implode( '; ', $validation_errors )
						)
					);
				}

				$resource_data['annotations'] = $mcp_annotations;
			}
		}

		// Icons and `_meta` from ability.meta.mcp are carried as given; the schema
		// decides whether they fit. Adapter metadata is NEVER included in protocol
		// meta; it is returned separately in adapter_meta.
		if ( isset( $mcp_meta['icons'] ) ) {
			$resource_data['icons'] = $mcp_meta['icons'];
		}

		if ( isset( $mcp_meta['_meta'] ) ) {
			$resource_data['_meta'] = $mcp_meta['_meta'];
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
		$uri = $this->get_mcp_meta( 'uri' );

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

		// The URI is the registry key, so it is matched as given: no trimming.
		if ( ! is_string( $uri ) || ! McpValidator::validate_resource_uri( $uri ) ) {
			return new WP_Error(
				'resource_uri_invalid',
				sprintf(
				/* translators: 1: ability name, 2: invalid URI */
					__( "Invalid resource URI '%2\$s' for ability '%1\$s'. URI must be RFC 3986 compliant with a scheme.", 'mcp-adapter' ),
					$this->ability->get_name(),
					is_string( $uri ) ? $uri : gettype( $uri )
				)
			);
		}

		/**
		 * Filters the MCP resource URI derived from an ability.
		 *
		 * @since 0.5.0
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
	 * Logs deprecation notice when using top-level location. The value is returned as
	 * set, whatever its type; the schema decides whether it fits. An explicit null
	 * counts as not set.
	 *
	 * @param string $key The key to look up.
	 *
	 * @return mixed The value, or null when the key is not set in either location.
	 */
	private function get_mcp_meta( string $key ) {
		$ability_meta = $this->ability->get_meta();
		$mcp_meta     = $ability_meta['mcp'] ?? array();

		// Preferred: Check mcp.{key} first.
		if ( isset( $mcp_meta[ $key ] ) ) {
			return $mcp_meta[ $key ];
		}

		// Deprecated fallback: Check top-level meta.{key}.
		if ( isset( $ability_meta[ $key ] ) ) {
			$this->log_deprecation(
				__METHOD__,
				sprintf(
				/* translators: 1: deprecated meta key, 2: new meta key path */
					__( 'Ability meta key "%1$s" is deprecated. Use "mcp.%1$s" instead.', 'mcp-adapter' ),
					$key
				),
				array( 'deprecated_key' => $key )
			);

			return $ability_meta[ $key ];
		}

		return null;
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
		_doing_it_wrong( esc_html( $method ), esc_html( $message ), '0.5.0' );

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
	 * Resource names have no charset restrictions (unlike Tool names). A filter
	 * that returns anything but a non-empty string rejects the resource, the same
	 * way the tool and prompt name filters do.
	 *
	 * @return string|\WP_Error The resolved resource name, or WP_Error when the filter broke it.
	 */
	private function resolve_resource_name() {
		$name = $this->ability->get_name();

		/**
		 * Filters the MCP resource name derived from an ability.
		 *
		 * Unlike tools, resource names have no charset restrictions.
		 *
		 * @since 0.5.0
		 *
		 * @param string $name The resource name.
		 * @param \WP_Ability $ability The source ability instance.
		 */
		$filtered_name = apply_filters( 'mcp_adapter_resource_name', $name, $this->ability );

		if ( ! is_string( $filtered_name ) || '' === trim( $filtered_name ) ) {
			return new WP_Error(
				'mcp_resource_name_filter_invalid',
				sprintf(
				/* translators: %s: invalid resource name returned by filter */
					__( 'Filter returned invalid MCP resource name: %s', 'mcp-adapter' ),
					is_string( $filtered_name ) ? $filtered_name : gettype( $filtered_name )
				)
			);
		}

		return $filtered_name;
	}

	/**
	 * Build clean neutral Resource data and adapter metadata for internal wiring.
	 *
	 * This method returns protocol-only data and provides the adapter metadata
	 * separately. Exact validation happens independently for each schema projection.
	 * wiring to protocol surfaces.
	 *
	 * @param \WP_Ability $ability The ability.
	 * @param \WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface|null $error_handler Optional error handler.
	 *
	 * @return array{resource_data: array<string, mixed>, adapter_meta: array<string, mixed>}|\WP_Error
	 * @since 0.5.0
	 *
	 */
	public static function build( \WP_Ability $ability, ?McpErrorHandlerInterface $error_handler = null ) {
		$resource = new self( $ability, $error_handler );
		$data     = $resource->build_resource_data();

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		return array(
			'resource_data' => $data['resource_data'],
			'adapter_meta'  => $data['adapter_meta'],
		);
	}
}
