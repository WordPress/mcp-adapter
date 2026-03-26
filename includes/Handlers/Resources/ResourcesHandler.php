<?php
/**
 * Resources method handlers for MCP requests.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Handlers\Resources;

use WP\MCP\Core\McpServer;
use WP\MCP\Handlers\HandlerHelperTrait;
use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;
use WP\McpSchema\Common\Protocol\DTO\BlobResourceContents;
use WP\McpSchema\Common\Protocol\DTO\TextResourceContents;
use WP\McpSchema\Server\Resources\DTO\ListResourcesResult;
use WP\McpSchema\Server\Resources\DTO\ReadResourceResult;

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
	 * Returns a ListResourcesResult DTO containing all registered resources.
	 * Returns protocol DTOs as-is; any `_meta` fields are passed through unchanged.
	 *
	 * @return \WP\McpSchema\Server\Resources\DTO\ListResourcesResult Response with resources list.
	 */
	public function list_resources(): ListResourcesResult {
		$resources = array_values( $this->mcp->get_resources() );

		/**
		 * Filters the list of resources before returning to the client.
		 *
		 * Use this filter to filter resources by context, add dynamic resources,
		 * or reorder the resources list.
		 *
		 * @since n.e.x.t
		 *
		 * @param array<\WP\McpSchema\Server\Resources\DTO\Resource> $resources Array of Resource DTOs.
		 * @param \WP\MCP\Core\McpServer                             $server    The MCP server instance.
		 */
		$resources = $this->validate_filtered_list(
			apply_filters( 'mcp_adapter_resources_list', $resources, $this->mcp ),
			$resources,
			'mcp_adapter_resources_list',
			$this->mcp->error_handler
		);

		return ListResourcesResult::fromArray(
			array(
				'resources' => $resources,
			)
		);
	}

	/**
	 * Handles the resources/read request.
	 *
	 * Returns either a ReadResourceResult DTO (for success) or a JSONRPCErrorResponse DTO
	 * (for protocol errors like missing parameter or resource not found).
	 *
	 * Unlike tools, resources don't have a concept of "execution errors" that should be
	 * reported with isError=true. Resource reads either succeed or fail at the protocol level.
	 *
	 * @param array $params Request parameters.
	 * @param string|int|null $request_id Optional. The request ID for JSON-RPC. Default 0.
	 *
	 * @return \WP\McpSchema\Server\Resources\DTO\ReadResourceResult|\WP\McpSchema\Common\JsonRpc\DTO\JSONRPCErrorResponse
	 */
	public function read_resource( array $params, $request_id = 0 ) {
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

		/** @var \WP\McpSchema\Server\Resources\DTO\Resource $resource */
		$resource = $mcp_resource->get_protocol_dto();

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
			 * @since n.e.x.t
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
			 * @since n.e.x.t
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
				$this->mcp->error_handler->log(
					'Resource execution returned WP_Error object',
					array(
						'uri'           => $uri,
						'error_code'    => $contents->get_error_code(),
						'error_message' => $contents->get_error_message(),
					)
				);

				return McpErrorFactory::internal_error( $request_id, $contents->get_error_message() );
			}

			// Successful execution - convert contents to DTOs.
			// Contents should be an array of resource content items.
			// If it's already an array of properly formatted items, convert each to a DTO.
			// Otherwise, wrap the result as text content.
			$content_dtos = $this->convert_contents_to_dtos( $contents, $uri );

			return ReadResourceResult::fromArray(
				array(
					'contents' => $content_dtos,
				)
			);
		} catch ( \Throwable $exception ) {
			$this->mcp->error_handler->log(
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
	 * Convert ability execution results to resource content DTOs.
	 *
	 * The MCP spec expects contents to be an array of TextResourceContents or BlobResourceContents.
	 * This method handles various return formats from abilities and normalizes them.
	 *
	 * @param mixed $contents The contents returned by the ability.
	 * @param string $uri The resource URI.
	 *
	 * @return array<\WP\McpSchema\Common\Protocol\DTO\TextResourceContents|\WP\McpSchema\Common\Protocol\DTO\BlobResourceContents>
	 */
	private function convert_contents_to_dtos( $contents, string $uri ): array {
		// If contents is already an array of properly structured items, convert each.
		if ( is_array( $contents ) && ! empty( $contents ) ) {
			// Check if this is an array of content items (has 'uri' or 'text' keys in first item).
			$first_item = reset( $contents );
			if ( is_array( $first_item ) && ( isset( $first_item['uri'] ) || isset( $first_item['text'] ) ) ) {
				return array_map(
					function ( $item ) use ( $uri ) {
						return $this->create_content_dto( $item, $uri );
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
			TextResourceContents::fromArray(
				array(
					'uri'  => $uri,
					'text' => $text,
				)
			),
		);
	}

	/**
	 * Create a content DTO from an array item.
	 *
	 * @param array $item The content item array.
	 * @param string $default_uri The default URI to use if not specified.
	 *
	 * @return \WP\McpSchema\Common\Protocol\DTO\TextResourceContents|\WP\McpSchema\Common\Protocol\DTO\BlobResourceContents
	 */
	private function create_content_dto( array $item, string $default_uri ) {
		$item_uri  = $item['uri'] ?? $default_uri;
		$mime_type = $item['mimeType'] ?? null;

		// If there's blob data, create BlobResourceContents.
		if ( isset( $item['blob'] ) ) {
			return BlobResourceContents::fromArray(
				array(
					'uri'      => $item_uri,
					'blob'     => (string) $item['blob'],
					'mimeType' => is_string( $mime_type ) ? $mime_type : null,
				)
			);
		}

		// Default to TextResourceContents.
		$text = $item['text'] ?? '';

		return TextResourceContents::fromArray(
			array(
				'uri'      => $item_uri,
				'text'     => (string) $text,
				'mimeType' => is_string( $mime_type ) ? $mime_type : null,
			)
		);
	}
}
