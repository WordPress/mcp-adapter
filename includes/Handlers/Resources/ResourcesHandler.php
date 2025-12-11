<?php
/**
 * Resources method handlers for MCP requests.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Handlers\Resources;

use WP\MCP\Core\McpServer;
use WP\MCP\Domain\Resources\McpResource;
use WP\MCP\Handlers\HandlerHelperTrait;
use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;
// phpcs:ignore SlevomatCodingStandard.Namespaces.UnusedUses.UnusedUse -- Used in @return PHPDoc
use WP\McpSchema\Common\JsonRpc\JSONRPCErrorResponse;
use WP\McpSchema\Common\Protocol\TextResourceContents;
use WP\McpSchema\Server\Resources\ListResourcesResult;
use WP\McpSchema\Server\Resources\ReadResourceResult;

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
	 * The internal _metadata is no longer included as it's stripped by the RequestRouter
	 * and DTOs handle MCP-spec _meta separately.
	 *
	 * @param int $request_id Optional. The request ID for JSON-RPC. Default 0.
	 *
	 * @return \WP\McpSchema\Server\Resources\ListResourcesResult Response with resources list.
	 */
	public function list_resources( int $request_id = 0 ): ListResourcesResult {
		$resources = $this->mcp->get_resources();

		// Convert each McpResource domain object to a php-mcp-schema Resource DTO.
		// Use array_values() to ensure numeric keys for MCP protocol compliance.
		// The internal resources array uses URIs as keys for fast lookup.
		$resource_dtos = array_values(
			array_map(
				static fn( McpResource $resource ) => $resource->to_schema_dto(),
				$resources
			)
		);

		return new ListResourcesResult( $resource_dtos );
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
	 * @param array $params     Request parameters.
	 * @param int   $request_id Optional. The request ID for JSON-RPC. Default 0.
	 *
	 * @return \WP\McpSchema\Server\Resources\ReadResourceResult|\WP\McpSchema\Common\JsonRpc\JSONRPCErrorResponse
	 */
	public function read_resource( array $params, int $request_id = 0 ) {
		// Extract parameters using helper method.
		$request_params = $this->extract_params( $params );

		if ( ! isset( $request_params['uri'] ) ) {
			return McpErrorFactory::missing_parameter( $request_id, 'uri' );
		}

		// Implement resource reading logic here.
		$uri      = $request_params['uri'];
		$resource = $this->mcp->get_resource( $uri );

		if ( ! $resource ) {
			return McpErrorFactory::resource_not_found( $request_id, $uri );
		}

		/**
		 * Get the ability
		 *
		 * @var \WP_Ability|\WP_Error $ability
		 */
		$ability = $resource->get_ability();

		// Check if getting the ability returned an error.
		if ( is_wp_error( $ability ) ) {
			$this->mcp->error_handler->log(
				'Failed to get ability for resource',
				array(
					'resource_uri'  => $uri,
					'error_message' => $ability->get_error_message(),
				)
			);

			return McpErrorFactory::internal_error( $request_id, $ability->get_error_message() );
		}

		try {
			$has_permission = $ability->check_permissions();
			if ( true !== $has_permission ) {
				// Extract detailed error message if WP_Error was returned.
				$error_message = 'Access denied for resource: ' . $resource->get_name();

				if ( is_wp_error( $has_permission ) ) {
					$error_message = $has_permission->get_error_message();
				}

				return McpErrorFactory::permission_denied( $request_id, $error_message );
			}

			$contents = $ability->execute();

			// Handle WP_Error objects that weren't converted by the ability.
			if ( is_wp_error( $contents ) ) {
				$this->mcp->error_handler->log(
					'Ability returned WP_Error object',
					array(
						'ability'       => $ability->get_name(),
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

			return new ReadResourceResult( $content_dtos );
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
	 * @param mixed  $contents The contents returned by the ability.
	 * @param string $uri      The resource URI.
	 *
	 * @return array<\WP\McpSchema\Common\Protocol\TextResourceContents|\WP\McpSchema\Common\Protocol\BlobResourceContents>
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
		$text = is_string( $contents ) ? $contents : wp_json_encode( $contents );

		return array( new TextResourceContents( $uri, (string) $text ) );
	}

	/**
	 * Create a content DTO from an array item.
	 *
	 * @param array  $item The content item array.
	 * @param string $default_uri The default URI to use if not specified.
	 *
	 * @return \WP\McpSchema\Common\Protocol\TextResourceContents|\WP\McpSchema\Common\Protocol\BlobResourceContents
	 */
	private function create_content_dto( array $item, string $default_uri ) {
		$item_uri  = $item['uri'] ?? $default_uri;
		$mime_type = $item['mimeType'] ?? null;

		// If there's blob data, create BlobResourceContents.
		if ( isset( $item['blob'] ) ) {
			return new \WP\McpSchema\Common\Protocol\BlobResourceContents(
				$item_uri,
				$item['blob'],
				$mime_type
			);
		}

		// Default to TextResourceContents.
		$text = $item['text'] ?? '';

		return new TextResourceContents( $item_uri, $text, $mime_type );
	}
}
