<?php
/**
 * Tools method handlers for MCP requests.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Handlers\Tools;

use WP\MCP\Core\McpServer;
use WP\MCP\Domain\Tools\McpTool;
use WP\MCP\Handlers\HandlerHelperTrait;
use WP\MCP\Infrastructure\Dto\ContentBlockHelper;
use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;
// phpcs:ignore SlevomatCodingStandard.Namespaces.UnusedUses.UnusedUse -- Used in @return PHPDoc
use WP\McpSchema\Common\JsonRpc\JSONRPCErrorResponse;
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
	 * The internal _metadata is no longer included as it's stripped by the RequestRouter
	 * and DTOs handle MCP-spec _meta separately.
	 *
	 * @param int $request_id Optional. The request ID for JSON-RPC. Default 0.
	 *
	 * @return \WP\McpSchema\Server\Tools\ListToolsResult Response with tools list.
	 */
	public function list_tools( int $request_id = 0 ): ListToolsResult {
		$tools = $this->mcp->get_tools();

		// Convert each McpTool domain object to a php-mcp-schema Tool DTO.
		// Use array_values() to ensure numeric keys for MCP protocol compliance.
		// The internal tools array uses tool names as keys for fast lookup.
		$tool_dtos = array_values(
			array_map(
				static fn( McpTool $tool ) => $tool->to_schema_dto(),
				$tools
			)
		);

		return new ListToolsResult( $tool_dtos );
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
	 * @param int $request_id Optional. The request ID for JSON-RPC. Default 0.
	 *
	 * @return \WP\McpSchema\Server\Tools\ListToolsResult Response with all tools.
	 */
	public function list_all_tools( int $request_id = 0 ): ListToolsResult {
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
	 * @param int   $request_id Optional. The request ID for JSON-RPC. Default 0.
	 *
	 * @return \WP\McpSchema\Server\Tools\CallToolResult|\WP\McpSchema\Common\JsonRpc\JSONRPCErrorResponse
	 */
	public function call_tool( array $message, int $request_id = 0 ) {
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
				$failure_reason = $result['_metadata']['failure_reason'] ?? '';

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

				return new CallToolResult(
					array( ContentBlockHelper::text( $error_message ) ),
					null, // _meta
					null, // structuredContent
					true  // isError
				);
			}

			// Successful tool execution - build CallToolResult DTO.
			// Remove internal metadata before building response.
			unset( $result['_metadata'] );

			// Handle image results.
			// @todo: add support for EmbeddedResource schema.ts:619.
			if ( isset( $result['type'] ) && 'image' === $result['type'] ) {
				$image_data = base64_encode( $result['results'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				$mime_type  = $result['mimeType'] ?? 'image/png';

				return new CallToolResult(
					array( ContentBlockHelper::image( $image_data, $mime_type ) ),
					null, // _meta
					null, // structuredContent - images don't have structured content
					null  // isError
				);
			}

			// Standard result - JSON-encode for text content, include as structuredContent.
			$json_text = wp_json_encode( $result );

			return new CallToolResult(
				array( ContentBlockHelper::text( (string) $json_text ) ),
				null,    // _meta
				$result, // structuredContent
				null     // isError
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
	 * @param int   $request_id Optional. The request ID for JSON-RPC. Default 0.
	 *
	 * @return array Response with tool execution results or error.
	 */
	public function handle_tool_call( array $params, int $request_id = 0 ): array {
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
				'_metadata'       => array(
					'component_type' => 'tool',
					'tool_name'      => $tool_name,
					'failure_reason' => 'not_found',
				),
			);
		}

		/**
		 * Get the ability
		 *
		 * @var \WP_Ability|\WP_Error $ability
		 */
		$ability = $tool->get_ability();

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
				'_metadata'       => array(
					'component_type' => 'tool',
					'tool_name'      => $tool_name,
					'failure_reason' => 'ability_retrieval_failed',
					'error_code'     => $ability->get_error_code(),
				),
			);
		}

		// Unwrap arguments if schema was transformed from flattened to object format
		$tool_metadata = $tool->get_metadata();
		$input_wrapped = ! empty( $tool_metadata['_input_schema_transformed'] );
		if ( $input_wrapped ) {
			// Unwrap: {input: "value"} → "value"
			$input_wrapper = $tool_metadata['_input_schema_wrapper'] ?? 'input';
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
					'error'     => McpErrorFactory::permission_denied( $request_id, $error_message )->getError()->toArray(),
					'_metadata' => array(
						'component_type' => 'tool',
						'tool_name'      => $tool_name,
						'ability_name'   => $ability->get_name(),
						'failure_reason' => $failure_reason,
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
				'error'     => McpErrorFactory::internal_error( $request_id, 'Error running ability permission callback' )->getError()->toArray(),
				'_metadata' => array(
					'component_type' => 'tool',
					'tool_name'      => $tool_name,
					'ability_name'   => $ability->get_name(),
					'failure_reason' => 'permission_check_failed',
					'error_type'     => get_class( $e ),
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
					'error'     => array(
						'message' => $result->get_error_message(),
						'code'    => $result->get_error_code(),
					),
					'_metadata' => array(
						'component_type' => 'tool',
						'tool_name'      => $tool_name,
						'ability_name'   => $ability->get_name(),
						'failure_reason' => 'wp_error',
						'error_code'     => $result->get_error_code(),
					),
				);
			}

			// Wrap result if output schema was transformed from flattened to object format
			$output_wrapped = ! empty( $tool_metadata['_output_schema_transformed'] );
			if ( $output_wrapped ) {
				// Wrap: "value" → {result: "value"}
				$output_wrapper = $tool_metadata['_output_schema_wrapper'] ?? 'result';
				$result         = array( $output_wrapper => $result );
			}

			// Ensure $result is always an array before adding metadata.
			if ( ! is_array( $result ) ) {
				$result = array( 'result' => $result );
			}

			// Successful execution - add metadata.
			$result['_metadata'] = array(
				'component_type' => 'tool',
				'tool_name'      => $tool_name,
				'ability_name'   => $ability->get_name(),
			);

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
				'error'     => array(
					'message' => $e->getMessage(),
				),
				'_metadata' => array(
					'component_type' => 'tool',
					'tool_name'      => $tool_name,
					'ability_name'   => $ability->get_name(),
					'failure_reason' => 'execution_failed',
					'error_type'     => get_class( $e ),
				),
			);
		}
	}
}
