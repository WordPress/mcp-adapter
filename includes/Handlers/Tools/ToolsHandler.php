<?php
/**
 * Tools method handlers for MCP requests.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Handlers\Tools;

use WP\MCP\Core\McpServer;
use WP\MCP\Domain\Utils\ContentBlockHelper;
use WP\MCP\Handlers\HandlerHelperTrait;
use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;
use WP\MCP\Infrastructure\Observability\FailureReason;
use WP\McpSchema\Server\Tools\DTO\CallToolResult;
use WP\McpSchema\Server\Tools\DTO\ListToolsResult;

/**
 * Handles tools-related MCP methods.
 */
class ToolsHandler {
	use HandlerHelperTrait;

	/**
	 * Default MIME type for image results when none is specified.
	 *
	 * @var string
	 */
	private const DEFAULT_IMAGE_MIME_TYPE = 'image/png';

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
	 * Handles the tools/list/all request.
	 *
	 * This is a custom extension to the MCP spec that includes availability status.
	 * Returns a ListToolsResult DTO containing all registered tools.
	 *
	 * Note: The 'available' flag is a non-standard extension and is not currently implemented.
	 *
	 * @return \WP\McpSchema\Server\Tools\DTO\ListToolsResult Response with all tools.
	 */
	public function list_all_tools(): ListToolsResult {
		// Return the standard tools list.
		return $this->list_tools();
	}

	/**
	 * Handles the tools/list request.
	 *
	 * Returns a ListToolsResult DTO containing all registered tools.
	 * Tool DTOs are protocol-only; internal adapter metadata is stored in McpTool instances and is never exposed
	 * to MCP clients.
	 *
	 * @return \WP\McpSchema\Server\Tools\DTO\ListToolsResult Response with tools list.
	 */
	public function list_tools(): ListToolsResult {
		$tools = array_values( $this->mcp->get_tools() );

		/**
		 * Filters the list of tools before returning to the client.
		 *
		 * Use this filter to hide tools per user/role, add dynamic tools,
		 * or reorder the tools list.
		 *
		 * @since n.e.x.t
		 *
		 * @param array<\WP\McpSchema\Server\Tools\DTO\Tool> $tools  Array of Tool DTOs.
		 * @param \WP\MCP\Core\McpServer                     $server The MCP server instance.
		 */
		$tools = $this->validate_filtered_list(
			apply_filters( 'mcp_adapter_tools_list', $tools, $this->mcp ),
			$tools,
			'mcp_adapter_tools_list',
			$this->mcp->error_handler
		);

		return ListToolsResult::fromArray(
			array(
				'tools' => $tools,
			)
		);
	}

	/**
	 * Handles the tools/call request.
	 *
	 * Returns either a CallToolResult DTO (for success or tool execution errors)
	 * or a JSONRPCErrorResponse DTO (for protocol errors like tool not found).
	 *
	 * The MCP spec distinguishes between:
	 * 1. **Protocol errors** (tool not found, server error) → JSONRPCErrorResponse
	 * 2. **Tool execution errors** (permission denied, runtime error) → CallToolResult with isError=true
	 *
	 * This distinction is critical for LLM self-correction - execution errors are
	 * visible to the LLM, while protocol errors indicate infrastructure issues.
	 *
	 * @param array $params Request params.
	 * @param string|int|null $request_id Optional. The request ID for JSON-RPC. Default 0.
	 *
	 * @return \WP\McpSchema\Server\Tools\DTO\CallToolResult|\WP\McpSchema\Common\JsonRpc\DTO\JSONRPCErrorResponse
	 */
	public function call_tool( array $params, $request_id = 0 ) {
		// Extract parameters using helper method.
		$request_params = $this->extract_params( $params );

		if ( ! isset( $request_params['name'] ) ) {
			return McpErrorFactory::missing_parameter( $request_id, 'tool name' );
		}

		if ( isset( $request_params['arguments'] ) && ! is_array( $request_params['arguments'] ) ) {
			return McpErrorFactory::invalid_params( $request_id, 'arguments must be an object' );
		}

		try {
			$tool_name = trim( (string) $request_params['name'] );
			$args      = $request_params['arguments'] ?? array();

			$mcp_tool = $this->mcp->get_mcp_tool( $tool_name );
			if ( ! $mcp_tool ) {
				$this->mcp->error_handler->log(
					'Tool not found',
					array(
						'tool_name' => $tool_name,
					),
					'warning'
				);

				return McpErrorFactory::tool_not_found( $request_id, $tool_name );
			}

			$permission = $mcp_tool->check_permission( $args );
			if ( true !== $permission ) {
				$error_message = __( 'Permission denied', 'mcp-adapter' );
				if ( is_wp_error( $permission ) ) {
					$error_message = $permission->get_error_message();

					$this->mcp->error_handler->log(
						'Tool permission check failed',
						array(
							'tool_name'      => $tool_name,
							'error_code'     => $permission->get_error_code(),
							'error_message'  => $permission->get_error_message(),
							'error_data'     => $permission->get_error_data(),
							'failure_reason' => FailureReason::PERMISSION_CHECK_FAILED,
						)
					);
				}

				return $this->create_error_result( $error_message );
			}

			/**
			 * Filters tool arguments before execution, or short-circuits execution entirely.
			 *
			 * Return the (optionally modified) arguments array to proceed with execution,
			 * or return a WP_Error to block execution and return an error to the client.
			 *
			 * @since n.e.x.t
			 *
			 * @param array                        $args      The tool arguments.
			 * @param string                       $tool_name The tool name being called.
			 * @param \WP\MCP\Domain\Tools\McpTool $mcp_tool  The MCP tool instance.
			 * @param \WP\MCP\Core\McpServer       $server    The MCP server instance.
			 */
			$args = apply_filters( 'mcp_adapter_pre_tool_call', $args, $tool_name, $mcp_tool, $this->mcp );

			// Allow pre-filter to short-circuit execution by returning WP_Error.
			if ( is_wp_error( $args ) ) {
				return $this->create_error_result( $args->get_error_message() );
			}

			$result = $mcp_tool->execute( $args );

			/**
			 * Filters the tool execution result before response assembly.
			 *
			 * Use this filter for result transformation, PII redaction,
			 * audit logging, or content enrichment.
			 *
			 * @since n.e.x.t
			 *
			 * @param mixed|\WP_Error              $result    The raw execution result (may be WP_Error).
			 * @param array                        $args      The tool arguments used.
			 * @param string                       $tool_name The tool name that was called.
			 * @param \WP\MCP\Domain\Tools\McpTool $mcp_tool  The MCP tool instance.
			 * @param \WP\MCP\Core\McpServer       $server    The MCP server instance.
			 */
			$result = apply_filters( 'mcp_adapter_tool_call_result', $result, $args, $tool_name, $mcp_tool, $this->mcp );

			if ( is_wp_error( $result ) ) {
				$this->mcp->error_handler->log(
					'Tool execution returned WP_Error',
					array(
						'tool_name'     => $tool_name,
						'error_code'    => $result->get_error_code(),
						'error_message' => $result->get_error_message(),
						'error_data'    => $result->get_error_data(),
					)
				);

				return $this->create_error_result( $result->get_error_message() );
			}

			// Backward compatibility: treat `{ success: false, error: string }` as tool execution error.
			if (
				is_array( $result )
				&& array_key_exists( 'success', $result )
				&& false === $result['success']
				&& isset( $result['error'] )
				&& is_string( $result['error'] )
				&& '' !== trim( $result['error'] )
			) {
				return $this->create_error_result( $result['error'] );
			}

			// Successful tool execution - build CallToolResult DTO.

			// Handle embedded resource results (MCP ContentBlock type: "resource").
			// This allows tools to return text/blob resources using the MCP schema's EmbeddedResource content block.
			if ( isset( $result['type'] ) && 'resource' === $result['type'] ) {
				$resource_item = $result;
				if ( isset( $result['resource'] ) && is_array( $result['resource'] ) ) {
					$resource_item = $result['resource'];
				}

				$uri       = $resource_item['uri'] ?? null;
				$mime_type = $resource_item['mimeType'] ?? null;

				if ( is_string( $uri ) ) {
					$uri = trim( $uri );
				}

				// Only return an EmbeddedResource if we have a valid URI and some content.
				if ( is_string( $uri ) && '' !== $uri ) {
					if ( isset( $resource_item['text'] ) && is_string( $resource_item['text'] ) ) {
						return CallToolResult::fromArray(
							array(
								'content' => array(
									ContentBlockHelper::embedded_text_resource(
										$uri,
										$resource_item['text'],
										is_string( $mime_type ) ? $mime_type : null
									),
								),
								'isError' => false,
							)
						);
					}

					if ( isset( $resource_item['blob'] ) && is_string( $resource_item['blob'] ) ) {
						return CallToolResult::fromArray(
							array(
								'content' => array(
									ContentBlockHelper::embedded_blob_resource(
										$uri,
										$resource_item['blob'],
										is_string( $mime_type ) ? $mime_type : null
									),
								),
								'isError' => false,
							)
						);
					}
				}
			}

			// Handle image results.
			if ( isset( $result['type'] ) && 'image' === $result['type'] && isset( $result['results'] ) ) {
				$image_data = base64_encode( $result['results'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				$mime_type  = $result['mimeType'] ?? self::DEFAULT_IMAGE_MIME_TYPE;

				return CallToolResult::fromArray(
					array(
						'content'           => array( ContentBlockHelper::image( $image_data, $mime_type ) ),
						'structuredContent' => null,
						'isError'           => false,
					)
				);
			}

			// Standard result - JSON-encode for text content, include as structuredContent.
			$json_text = wp_json_encode( $result );
			if ( false === $json_text ) {
				$json_text = '{}';
			}

			return CallToolResult::fromArray(
				array(
					'content'           => array( ContentBlockHelper::text( $json_text ) ),
					'structuredContent' => $result,
					'isError'           => false,
				)
			);
		} catch ( \Throwable $exception ) {
			$this->mcp->error_handler->log(
				'Error calling tool',
				array(
					'tool'      => $request_params['name'],
					'exception' => $exception->getMessage(),
				)
			);

			return McpErrorFactory::internal_error( $request_id, 'Failed to execute tool' );
		}
	}

	/**
	 * Create an error CallToolResult from a message string.
	 *
	 * @since n.e.x.t
	 *
	 * @param string $message The error message.
	 *
	 * @return \WP\McpSchema\Server\Tools\DTO\CallToolResult
	 */
	private function create_error_result( string $message ): CallToolResult {
		return CallToolResult::fromArray(
			array(
				'content'           => array( ContentBlockHelper::text( $message ) ),
				'structuredContent' => null,
				'isError'           => true,
			)
		);
	}
}
