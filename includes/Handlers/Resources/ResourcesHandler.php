<?php
/**
 * Resources method handlers for MCP requests.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Handlers\Resources;

use WP\MCP\Core\McpServer;
use WP\MCP\Domain\Continuation\McpContinuationContext;
use WP\MCP\Domain\Continuation\McpExecutionResult;
use WP\MCP\Domain\Utils\McpValidator;
use WP\MCP\Handlers\HandlerHelperTrait;
use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;

/**
 * Handles resources-related MCP methods.
 */
class ResourcesHandler {
	use HandlerHelperTrait;

	/**
	 * The WordPress MCP instance.
	 *
	 * @var \WP\MCP\Core\McpServer
	 */
	private McpServer $mcp;

	/**
	 * Constructor.
	 *
	 * @param \WP\MCP\Core\McpServer $mcp The WordPress MCP instance.
	 */
	public function __construct( McpServer $mcp ) {
		$this->mcp = $mcp;
	}


	/**
	 * Handles the resources/list request.
	 *
	 * Returns registered resource protocol data as-is; any `_meta` fields are
	 * passed through unchanged.
	 *
	 * @return array Response with resources list.
	 */
	public function list_resources(): array {
		$resources = array_values( $this->mcp->get_resources() );

		/**
		 * Filters the list of resources before returning to the client.
		 *
		 * Use this filter to filter resources by context, add dynamic resources,
		 * or reorder the resources list.
		 *
		 * @since 0.5.0
		 *
		 * @param array                  $resources Array of resource protocol data.
		 * @param \WP\MCP\Core\McpServer $server    The MCP server instance.
		 */
		$resources = $this->validate_filtered_list(
			apply_filters( 'mcp_adapter_resources_list', $resources, $this->mcp ),
			$resources,
			'mcp_adapter_resources_list',
			$this->mcp->get_error_handler()
		);

		return array(
			'resources' => $resources,
		);
	}

	/**
	 * Handles the resources/templates/list request.
	 *
	 * The adapter has no resource-template concept, so this always returns an empty
	 * list. The method still needs a handler: `resources/templates/list` is part of
	 * the base `resources` capability (no sub-flag gates it), which the server always
	 * advertises, so spec-compliant clients call it during resource discovery.
	 *
	 * @return array Empty resource-templates list.
	 */
	public function list_resource_templates(): array {
		return array(
			'resourceTemplates' => array(),
		);
	}

	/**
	 * Handles the resources/read request.
	 *
	 * Returns either stable resource result data (for success) or a JSON-RPC error envelope
	 * (for protocol errors like missing parameter or resource not found).
	 *
	 * Unlike tools, resources don't have a concept of "execution errors" that should be
	 * reported with isError=true. Resource reads either succeed or fail at the protocol level.
	 *
	 * @param array $params Request parameters.
	 * @param string|int|null $request_id Optional. The request ID for JSON-RPC. Default 0.
	 * @param \WP\MCP\Domain\Continuation\McpContinuationContext|null $continuation Optional stateless continuation input.
	 *
	 * @return array|\WP\MCP\Domain\Continuation\McpExecutionResult
	 */
	public function read_resource( array $params, $request_id = 0, ?McpContinuationContext $continuation = null ) {
		// Extract parameters using helper method.
		$request_params = $this->extract_params( $params );

		if ( ! isset( $request_params['uri'] ) ) {
			return McpErrorFactory::missing_parameter( $request_id, 'uri' );
		}

		$uri = $request_params['uri'];
		$uri = is_string( $uri ) ? trim( $uri ) : '';

		$mcp_resource = $this->mcp->get_mcp_resource( $uri );
		if ( ! $mcp_resource ) {
			return McpErrorFactory::resource_not_found( $request_id, $uri );
		}

		$resource = $mcp_resource->get_protocol_data();

		try {
			$has_permission = $mcp_resource->check_permission( $request_params );
			if ( true !== $has_permission ) {
				// Extract detailed error message if WP_Error was returned.
				$error_message = 'Access denied for resource: ' . ( $resource['name'] ?? $uri );

				if ( is_wp_error( $has_permission ) ) {
					$error_message = $has_permission->get_error_message();
				}

				return McpErrorFactory::permission_denied( $request_id, $error_message );
			}

			/**
			 * Filters resource parameters before execution, or short-circuits execution entirely.
			 *
			 * Return the (optionally modified) parameters array to proceed with execution,
			 * or return a WP_Error to block execution and return an error to the client.
			 *
			 * @since 0.5.0
			 *
			 * @param array                                $params       The request parameters.
			 * @param string                               $uri          The resource URI.
			 * @param \WP\MCP\Domain\Resources\McpResource $mcp_resource The MCP resource instance.
			 * @param \WP\MCP\Core\McpServer               $server       The MCP server instance.
			 */
			$request_params = apply_filters( 'mcp_adapter_pre_resource_read', $request_params, $uri, $mcp_resource, $this->mcp );

			// Allow pre-filter to short-circuit execution by returning WP_Error.
			if ( is_wp_error( $request_params ) ) {
				return McpErrorFactory::internal_error( $request_id, $request_params->get_error_message() );
			}

			$contents = $mcp_resource->execute( $request_params, $continuation );

			/**
			 * Filters the resource contents after execution.
			 *
			 * Use this filter for content transformation, caching storage,
			 * PII redaction, or audit logging.
			 *
			 * @since 0.5.0
			 *
			 * @param mixed|\WP_Error                      $contents     The raw resource contents (may be WP_Error).
			 * @param array                                $params       The request parameters used.
			 * @param string                               $uri          The resource URI.
			 * @param \WP\MCP\Domain\Resources\McpResource $mcp_resource The MCP resource instance.
			 * @param \WP\MCP\Core\McpServer               $server       The MCP server instance.
			 */
			$contents = apply_filters( 'mcp_adapter_resource_read_result', $contents, $request_params, $uri, $mcp_resource, $this->mcp );

			// Handle WP_Error objects returned by McpResource execution.
			if ( is_wp_error( $contents ) ) {
				$this->mcp->get_error_handler()->log(
					'Resource execution returned WP_Error object',
					array(
						'uri'           => $uri,
						'error_code'    => $contents->get_error_code(),
						'error_message' => $contents->get_error_message(),
					)
				);

				return McpErrorFactory::internal_error( $request_id, $contents->get_error_message() );
			}

			if ( $contents instanceof McpExecutionResult ) {
				return $contents;
			}

			// Successful execution - convert contents to validated protocol arrays.
			// Contents should be an array of resource content items.
			// If it's already an array of properly formatted items, normalize each item.
			// Otherwise, wrap the result as text content.
			//
			// Seed the fallback content URI with the advertised URI, not the client's
			// request URI, so contents[].uri matches resources/list even when the client
			// lowercased the scheme (RFC 3986 3.1). For an exact-case read the two are equal.
			$content_items = $this->convert_contents( $contents, $resource['uri'] );

			return array(
				'contents' => $content_items,
			);
		} catch ( \Throwable $exception ) {
			$this->mcp->get_error_handler()->log(
				'Error reading resource',
				array(
					'uri'       => $uri,
					'exception' => $exception->getMessage(),
				)
			);

			return McpErrorFactory::internal_error( $request_id, 'Failed to read resource' );
		}
	}

	/**
	 * Convert ability execution results to resource content arrays.
	 *
	 * The MCP spec expects contents to be text-resource or blob-resource arrays.
	 * This method handles various return formats from abilities and normalizes them.
	 *
	 * @param mixed $contents The contents returned by the ability.
	 * @param string $uri The resource URI.
	 *
	 * @return array
	 */
	private function convert_contents( $contents, string $uri ): array {
		// If contents is already an array of properly structured items, convert each.
		if ( is_array( $contents ) && ! empty( $contents ) ) {
			// Check if this is an array of content items (has 'uri', 'text', or 'blob' in first item).
			$first_item = reset( $contents );
			if ( is_array( $first_item ) && ( isset( $first_item['uri'] ) || isset( $first_item['text'] ) || isset( $first_item['blob'] ) ) ) {
				return array_map(
					function ( $item ) use ( $uri ) {
						return $this->create_content( $item, $uri );
					},
					$contents
				);
			}
		}

		// Fallback: wrap as a single text content item.
		if ( is_string( $contents ) ) {
			$text = $contents;
		} else {
			$text = wp_json_encode( $contents );
			if ( false === $text ) {
				$text = '{}';
			}
		}

		return array(
			array(
				'uri'  => $uri,
				'text' => $text,
			),
		);
	}

	/**
	 * Create a validated content array from an item.
	 *
	 * `_meta` is carried through from the item so metadata a handler attaches to its
	 * resource contents reaches the client. MCP Apps UI resources rely on this: they
	 * put CSP config and border hints under `_meta.ui` alongside the HTML body.
	 *
	 * A list-shaped `_meta` is omitted because MCP declares this field as a JSON object.
	 *
	 * Every key is optional and read defensively, because a handler returns whatever
	 * WordPress handed it: `blob` and `text` are cast to string, `mimeType` is kept
	 * only when it already is one, and an absent `uri` falls back to $default_uri.
	 *
	 * @param array{uri?: mixed, mimeType?: mixed, text?: mixed, blob?: mixed, _meta?: mixed} $item The content item array.
	 * @param string $default_uri The URI to use when the item names none.
	 *
	 * @return array
	 */
	private function create_content( array $item, string $default_uri ): array {
		$item_uri  = $item['uri'] ?? $default_uri;
		$mime_type = $item['mimeType'] ?? null;
		$meta      = McpValidator::normalize_meta( $item['_meta'] ?? null );

		// If there's blob data, create blob-resource content.
		if ( isset( $item['blob'] ) ) {
			return $this->with_optional_content_fields(
				array(
					'uri'  => $item_uri,
					'blob' => (string) $item['blob'],
				),
				$mime_type,
				$meta
			);
		}

		// Default to text-resource content.
		$text = $item['text'] ?? '';

		return $this->with_optional_content_fields(
			array(
				'uri'  => $item_uri,
				'text' => (string) $text,
			),
			$mime_type,
			$meta
		);
	}

	/**
	 * Add valid optional fields to resource content.
	 *
	 * @param array      $content   Required content fields.
	 * @param mixed      $mime_type Optional MIME type.
	 * @param array|null $meta      Optional normalized metadata.
	 *
	 * @return array
	 */
	private function with_optional_content_fields( array $content, $mime_type, ?array $meta ): array {
		if ( is_string( $mime_type ) ) {
			$content['mimeType'] = $mime_type;
		}

		if ( null !== $meta ) {
			$content['_meta'] = $meta;
		}

		return $content;
	}
}
