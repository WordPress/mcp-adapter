<?php
/**
 * Tools method handlers for MCP requests.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Handlers\Tools;

use WP\MCP\Core\McpServer;
use WP\MCP\Domain\Tools\ToolMetadataHelper;
use WP\MCP\Handlers\HandlerHelperTrait;
use WP\MCP\Infrastructure\Dto\ContentBlockHelper;
use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;
use WP\McpSchema\Server\Tools\CallToolResult;
use WP\McpSchema\Server\Tools\ListToolsResult;

/**
 * Handles tools-related MCP methods.
 */
class ToolsHandler {
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
	 * Handles the tools/list request.
	 *
	 * Returns a ListToolsResult DTO containing all registered tools.
	 * Internal adapter metadata is stored under `_meta['mcp_adapter']` and stripped at the
	 * transport boundary (RequestRouter) before returning to MCP clients.
	 *
	 * @param string|int|null $request_id Optional. The request ID for JSON-RPC. Default 0.
	 *
	 * @return \WP\McpSchema\Server\Tools\ListToolsResult Response with tools list.
	 */
	public function list_tools( $request_id = 0 ): ListToolsResult {
		$tools = array_values( $this->mcp->get_tools() );

		return ListToolsResult::fromArray(
			array(
				'tools' => $tools,
			)
		);
	}

	/**
	 * Handles the tools/list/all request.
	 *
	 * This is a custom extension to the MCP spec that includes availability status.
	 * Returns a ListToolsResult DTO containing all registered tools.
	 *
	 * Note: The 'available' flag is a non-standard extension. Since Tool DTOs don't
	 * support arbitrary fields, this information would need to be communicated via
	 * _meta if needed. For now, we return the standard ListToolsResult.
	 *
	 * @param string|int|null $request_id Optional. The request ID for JSON-RPC. Default 0.
	 *
	 * @return \WP\McpSchema\Server\Tools\ListToolsResult Response with all tools.
	 */
	public function list_all_tools( $request_id = 0 ): ListToolsResult {
		// Return all tools - availability checking can be done via _meta if needed.
		return $this->list_tools( $request_id );
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
	 * @param array $message    Request message.
	 * @param string|int|null $request_id Optional. The request ID for JSON-RPC. Default 0.
	 *
	 * @return \WP\McpSchema\Server\Tools\CallToolResult|\WP\McpSchema\Common\JsonRpc\JSONRPCErrorResponse
	 */
	public function call_tool( array $message, $request_id = 0 ) {
		// Extract parameters using helper method.
		$request_params = $this->extract_params( $message );

		if ( ! isset( $request_params['name'] ) ) {
			return McpErrorFactory::missing_parameter( $request_id, 'tool name' );
		}

		try {
			// Delegate to handle_tool_call which returns array with results or error info.
			$result = $this->handle_tool_call( $request_params, $request_id );

			// Check if the result contains an error.
			if ( isset( $result['error'] ) ) {
				$failure_reason = '';
				if ( isset( $result['_meta'] ) && is_array( $result['_meta'] ) && isset( $result['_meta']['mcp_adapter'] ) && is_array( $result['_meta']['mcp_adapter'] ) ) {
					$failure_reason = (string) ( $result['_meta']['mcp_adapter']['failure_reason'] ?? '' );
				}

				// Protocol errors (return JSON-RPC error response):
				// - not_found (tool doesn't exist)
				// - ability_retrieval_failed (internal error getting ability)
				$protocol_errors = array( 'not_found', 'ability_retrieval_failed' );

				if ( \in_array( $failure_reason, $protocol_errors, true ) ) {
					// Return the JSONRPCErrorResponse directly from handle_tool_call.
					return $result['_error_response'];
				}

				// Tool execution errors (return CallToolResult with isError=true):
				// - permission_denied, permission_check_failed
				// - wp_error, execution_failed
				// Note: Error format varies by ability implementation. ExecuteAbilityAbility
				// returns errors as plain strings, while other abilities return an array
				// with a 'message' key. This handles both formats for compatibility (#89).
				if ( \is_string( $result['error'] ) ) {
					$error_message = $result['error'];
				} else {
					$error_message = $result['error']['message'] ?? 'An error occurred while executing the tool.';
				}

				return CallToolResult::fromArray(
					array(
						'content'           => array( ContentBlockHelper::text( $error_message ) ),
						'structuredContent' => null,
						'isError'           => true,
					)
				);
			}

			// Successful tool execution - build CallToolResult DTO.
			// Remove internal adapter metadata before building response.
			if ( isset( $result['_meta'] ) && is_array( $result['_meta'] ) && isset( $result['_meta']['mcp_adapter'] ) ) {
				unset( $result['_meta']['mcp_adapter'] );
				if ( empty( $result['_meta'] ) ) {
					unset( $result['_meta'] );
				}
			}

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
			if ( isset( $result['type'] ) && 'image' === $result['type'] ) {
				$image_data = base64_encode( $result['results'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				$mime_type  = $result['mimeType'] ?? 'image/png';

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

			return CallToolResult::fromArray(
				array(
					'content'           => array( ContentBlockHelper::text( (string) $json_text ) ),
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
	 * Handles tool call request.
	 *
	 * @param array $params     The request parameters.
	 * @param string|int|null $request_id Optional. The request ID for JSON-RPC. Default 0.
	 *
	 * @return array Response with tool execution results or error.
	 */
	public function handle_tool_call( array $params, $request_id = 0 ): array {
		$tool_name = $params['name'];
		$args      = $params['arguments'] ?? array();

		// Get the tool callbacks.
		$tool = $this->mcp->get_tool( $params['name'] );

		// Check if the tool exists.
		if ( ! $tool ) {
			$this->mcp->error_handler->log(
				'Tool not found',
				array(
					'tool' => $tool_name,
				)
			);

			$error_response = McpErrorFactory::tool_not_found( $request_id, $tool_name );
			return array(
				'error'           => $error_response->getError()->toArray(),
				'_error_response' => $error_response,
				'_meta'           => array(
					'mcp_adapter' => array(
						'component_type' => 'tool',
						'tool_name'      => $tool_name,
						'failure_reason' => 'not_found',
					),
				),
			);
		}

		$ability_name = ToolMetadataHelper::get_ability_name( $tool );
		if ( null === $ability_name ) {
			$error_response = McpErrorFactory::internal_error( $request_id, 'Tool is missing ability metadata.' );
			return array(
				'error'           => $error_response->getError()->toArray(),
				'_error_response' => $error_response,
				'_meta'           => array(
					'mcp_adapter' => array(
						'component_type' => 'tool',
						'tool_name'      => $tool_name,
						'failure_reason' => 'missing_ability_metadata',
					),
				),
			);
		}

		$ability = \wp_get_ability( $ability_name );
		if ( ! $ability ) {
			$ability = new \WP_Error(
				'ability_not_found',
				sprintf(
					/* translators: %s: ability name */
					esc_html__( "WordPress ability '%s' does not exist.", 'mcp-adapter' ),
					esc_html( $ability_name )
				)
			);
		}

		// Check if getting the ability returned an error
		if ( is_wp_error( $ability ) ) {
			$this->mcp->error_handler->log(
				'Failed to get ability for tool',
				array(
					'tool'          => $tool_name,
					'error_message' => $ability->get_error_message(),
				)
			);

			$error_response = McpErrorFactory::internal_error( $request_id, $ability->get_error_message() );
			return array(
				'error'           => $error_response->getError()->toArray(),
				'_error_response' => $error_response,
				'_meta'           => array(
					'mcp_adapter' => array(
						'component_type' => 'tool',
						'tool_name'      => $tool_name,
						'failure_reason' => 'ability_retrieval_failed',
						'error_code'     => $ability->get_error_code(),
					),
				),
			);
		}

		// Unwrap arguments if schema was transformed from flattened to object format
		$input_wrapped = ToolMetadataHelper::is_input_transformed( $tool );
		if ( $input_wrapped ) {
			// Unwrap: {input: "value"} → "value"
			$input_wrapper = ToolMetadataHelper::get_input_wrapper( $tool );
			$args          = is_array( $args ) ? ( $args[ $input_wrapper ] ?? null ) : null;
		}

		// If ability has no input schema and args is empty, pass null instead
		$ability_input_schema = $ability->get_input_schema();
		if ( empty( $ability_input_schema ) && empty( $args ) ) {
			$args = null;
		}

		// Run ability Permission Callback.
		try {
			$has_permission = $ability->check_permissions( $args );
			if ( true !== $has_permission ) {
				// Extract detailed error message and code if WP_Error was returned
				$error_message  = 'Access denied for tool: ' . $tool_name;
				$failure_reason = 'permission_denied';

				if ( is_wp_error( $has_permission ) ) {
					$error_message  = $has_permission->get_error_message();
					$failure_reason = $has_permission->get_error_code(); // Use WP_Error code as failure_reason
				}

				return array(
					'error' => McpErrorFactory::permission_denied( $request_id, $error_message )->getError()->toArray(),
					'_meta' => array(
						'mcp_adapter' => array(
							'component_type' => 'tool',
							'tool_name'      => $tool_name,
							'ability_name'   => $ability->get_name(),
							'failure_reason' => $failure_reason,
						),
					),
				);
			}
		} catch ( \Throwable $e ) {
			$this->mcp->error_handler->log(
				'Error running ability permission callback',
				array(
					'ability'   => $ability->get_name(),
					'exception' => $e->getMessage(),
				)
			);

			return array(
				'error' => McpErrorFactory::internal_error( $request_id, 'Error running ability permission callback' )->getError()->toArray(),
				'_meta' => array(
					'mcp_adapter' => array(
						'component_type' => 'tool',
						'tool_name'      => $tool_name,
						'ability_name'   => $ability->get_name(),
						'failure_reason' => 'permission_check_failed',
						'error_type'     => get_class( $e ),
					),
				),
			);
		}

		// Execute the tool callback.
		try {
			$result = $ability->execute( $args );

			// Handle WP_Error objects that weren't converted by the ability.
			if ( is_wp_error( $result ) ) {
				$this->mcp->error_handler->log(
					'Ability returned WP_Error object',
					array(
						'ability'       => $ability->get_name(),
						'error_code'    => $result->get_error_code(),
						'error_message' => $result->get_error_message(),
					)
				);

				// Return error for conversion to isError format by call_tool().
				return array(
					'error' => array(
						'message' => $result->get_error_message(),
						'code'    => $result->get_error_code(),
					),
					'_meta' => array(
						'mcp_adapter' => array(
							'component_type' => 'tool',
							'tool_name'      => $tool_name,
							'ability_name'   => $ability->get_name(),
							'failure_reason' => 'wp_error',
							'error_code'     => $result->get_error_code(),
						),
					),
				);
			}

			// Wrap result if output schema was transformed from flattened to object format
			$output_wrapped = ToolMetadataHelper::is_output_transformed( $tool );
			if ( $output_wrapped ) {
				// Wrap: "value" → {result: "value"}
				$output_wrapper = ToolMetadataHelper::get_output_wrapper( $tool );
				$result         = array( $output_wrapper => $result );
			}

			// Ensure $result is always an array before adding metadata.
			if ( ! is_array( $result ) ) {
				$result = array( 'result' => $result );
			}

			return $result;
		} catch ( \Throwable $e ) {
			$this->mcp->error_handler->log(
				'Tool execution failed',
				array(
					'tool'      => $tool_name,
					'exception' => $e->getMessage(),
				)
			);

			// Return error for conversion to isError format by call_tool().
			return array(
				'error' => array(
					'message' => $e->getMessage(),
				),
				'_meta' => array(
					'mcp_adapter' => array(
						'component_type' => 'tool',
						'tool_name'      => $tool_name,
						'ability_name'   => $ability->get_name(),
						'failure_reason' => 'execution_failed',
						'error_type'     => get_class( $e ),
					),
				),
			);
		}
	}
}
