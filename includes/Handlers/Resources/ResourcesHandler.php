<?php
/**
 * Resources method handlers for MCP requests.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Handlers\Resources;

use WP\MCP\Core\McpRequestContext;
use WP\MCP\Core\McpServer;
use WP\MCP\Domain\Utils\McpValidator;
use WP\MCP\Handlers\HandlerHelperTrait;
use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;
use WP\McpSchema\Record\ListResourceTemplatesRequest;
use WP\McpSchema\Record\ListResourcesRequest;
use WP\McpSchema\Record\ReadResourceRequest;

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
	 * Returns a selected ListResourcesResult containing registered resources.
	 *
	 * @param \WP\McpSchema\Record\ListResourcesRequest $request Validated request.
	 * @param \WP\MCP\Core\McpRequestContext $request_context Exact request context.
	 * @return array<string, mixed> Logical resources-list result.
	 * @since n.e.x.t
	 */
	public function list_resources( ListResourcesRequest $request, McpRequestContext $request_context ): array {
		unset( $request );
		$schema    = $request_context->schema();
		$resources = array_values( $this->mcp->get_resources( $schema ) );

		/**
		 * Filters the list of resources before returning to the client.
		 *
		 * Use this filter to filter resources by context, add dynamic resources,
		 * or reorder the resources list.
		 *
		 * @since 0.5.0
		 *
		 * @param array<\WP\McpSchema\Record\Resource> $resources Array of Resource records.
		 * @param \WP\MCP\Core\McpServer                             $server    The MCP server instance.
		 * @param \WP\McpSchema\Schema                                $schema    Selected schema.
		 */
		$resources = $this->validate_filtered_list(
			apply_filters( 'mcp_adapter_resources_list', $resources, $this->mcp, $schema ),
			$resources,
			'mcp_adapter_resources_list',
			$this->mcp->get_error_handler()
		);

		return array( 'resources' => $resources );
	}

	/**
	 * Handles the resources/templates/list request.
	 *
	 * The adapter has no resource-template concept, so this always returns an empty
	 * list. The method still needs a handler: `resources/templates/list` is part of
	 * the base `resources` capability (no sub-flag gates it), which the server always
	 * advertises, so spec-compliant clients call it during resource discovery.
	 *
	 * @param \WP\McpSchema\Record\ListResourceTemplatesRequest $request Validated request.
	 * @param \WP\MCP\Core\McpRequestContext $request_context Exact request context.
	 * @return array<string, mixed> Logical empty resource-templates result.
	 * @since n.e.x.t
	 */
	public function list_resource_templates( ListResourceTemplatesRequest $request, McpRequestContext $request_context ): array {
		unset( $request, $request_context );
		return array( 'resourceTemplates' => array() );
	}

	/**
	 * Handles the resources/read request.
	 *
	 * Returns either a ReadResourceResult record or a protocol error array
	 * (for protocol errors like missing parameter or resource not found).
	 *
	 * Unlike tools, resources don't have a concept of "execution errors" that should be
	 * reported with isError=true. Resource reads either succeed or fail at the protocol level.
	 *
	 * @param \WP\McpSchema\Record\ReadResourceRequest $request Validated request.
	 * @param \WP\MCP\Core\McpRequestContext $request_context Exact request context.
	 *
	 * @return array<string, mixed>
	 * @since n.e.x.t
	 */
	public function read_resource( ReadResourceRequest $request, McpRequestContext $request_context ): array {
		$params         = $request->getParams();
		$request_id     = $request->getId();
		$uri            = trim( $params->getUri() );
		$request_params = array( 'uri' => $params->getUri() );
		$meta           = $params->getMeta();
		if ( null !== $meta ) {
			$request_params['_meta'] = $this->callback_value( $meta );
		}
		$input_responses = $params->getInputResponses();
		if ( null !== $input_responses ) {
			$request_params['inputResponses'] = $this->callback_value( $input_responses );
		}
		$request_state = $params->getRequestState();
		if ( null !== $request_state ) {
			$request_params['requestState'] = $request_state;
		}

		$mcp_resource = $this->mcp->get_mcp_resource( $uri );
		if ( ! $mcp_resource || ! $mcp_resource->is_available_for( $request_context->schema() ) ) {
			return McpErrorFactory::resource_not_found( $request_id, $uri, $request_context->revision() );
		}

		$resource = $mcp_resource->get_protocol_record( $request_context->schema() );

		try {
			$has_permission = $mcp_resource->check_permission( $request_params );
			if ( true !== $has_permission ) {
				// Extract detailed error message if WP_Error was returned.
				$error_message = 'Access denied for resource: ' . $resource->getName();

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

			$contents = $mcp_resource->execute( $request_params );

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

			// Successful execution - normalize contents for selected-schema construction.
			// Contents should be an array of resource content items.
			// If it is already an array of properly formatted items, normalize each item.
			// Otherwise, wrap the result as text content.
			//
			// Seed the fallback content URI with the advertised URI, not the client's
			// request URI, so contents[].uri matches resources/list even when the client
			// lowercased the scheme (RFC 3986 3.1). For an exact-case read the two are equal.
			$content_data = $this->convert_contents( $contents, $resource->getUri() );
			return array( 'contents' => $content_data );
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
	 * Convert ability execution results to resource content data.
	 *
	 * The MCP spec expects contents to be an array of TextResourceContents or BlobResourceContents.
	 * This method handles various return formats from abilities and normalizes them.
	 *
	 * @param mixed $contents The contents returned by the ability.
	 * @param string $uri The resource URI.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function convert_contents( $contents, string $uri ): array {
		// If contents is already an array of properly structured items, convert each.
		if ( is_array( $contents ) && ! empty( $contents ) ) {
			// Check if this is an array of content items (has 'uri', 'text', or 'blob' in first item).
			$first_item = reset( $contents );
			if ( is_array( $first_item ) && ( isset( $first_item['uri'] ) || isset( $first_item['text'] ) || isset( $first_item['blob'] ) ) ) {
				return array_map(
					function ( $item ) use ( $uri ) {
						return $this->create_content_data( $item, $uri );
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
	 * Create content data from an array item.
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
	 * @return array<string, mixed>
	 */
	private function create_content_data( array $item, string $default_uri ): array {
		$item_uri  = $item['uri'] ?? $default_uri;
		$mime_type = $item['mimeType'] ?? null;
		$meta      = McpValidator::normalize_meta( $item['_meta'] ?? null );

		// If there's blob data, create BlobResourceContents.
		if ( isset( $item['blob'] ) ) {
			return array_filter(
				array(
					'uri'      => $item_uri,
					'blob'     => (string) $item['blob'],
					'mimeType' => is_string( $mime_type ) ? $mime_type : null,
					'_meta'    => $meta,
				),
				static fn( $value ): bool => null !== $value
			);
		}

		// Default to TextResourceContents.
		$text = $item['text'] ?? '';

		return array_filter(
			array(
				'uri'      => $item_uri,
				'text'     => (string) $text,
				'mimeType' => is_string( $mime_type ) ? $mime_type : null,
				'_meta'    => $meta,
			),
			static fn( $value ): bool => null !== $value
		);
	}
}
