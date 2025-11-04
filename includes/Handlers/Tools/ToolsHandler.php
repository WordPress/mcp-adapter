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
use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;

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
	 * Handle the tools/list request.
	 *
	 * @param int $request_id The request ID for JSON-RPC.
	 *
	 * @return array
	 */
	public function list_tools( int $request_id = 0 ): array {
		$tools      = $this->mcp->get_tools();
		$safe_tools = array();

		foreach ( $tools as $tool ) {
			$safe_tools[] = $this->sanitize_tool_data( $tool );
		}

		return array(
			'tools'     => $safe_tools,
			'_metadata' => array(
				'component_type' => 'tools',
				'tools_count'    => count( $safe_tools ),
			),
		);
	}

	/**
	 * Handle the tools/list/all request.
	 *
	 * @param int $request_id The request ID for JSON-RPC.
	 *
	 * @return array
	 */
	public function list_all_tools( int $request_id = 0 ): array {
		// Return all tools with additional details.
		$tools      = $this->mcp->get_tools();
		$safe_tools = array();

		foreach ( $tools as $tool ) {
			$safe_tool              = $this->sanitize_tool_data( $tool );
			$safe_tool['available'] = true;
			$safe_tools[]           = $safe_tool;
		}

		return array(
			'tools'     => $safe_tools,
			'_metadata' => array(
				'component_type' => 'tools',
				'tools_count'    => count( $safe_tools ),
			),
		);
	}

	/**
	 * Handle the tools/call request.
	 *
	 * @param array $message    Request message.
	 * @param int   $request_id The request ID for JSON-RPC.
	 *
	 * @return array
	 */
	public function call_tool( array $message, int $request_id = 0 ): array {
		// Extract parameters using helper method.
		$request_params = $this->extract_params( $message );

		if ( ! isset( $request_params['name'] ) ) {
			return array(
				'error'     => McpErrorFactory::missing_parameter( $request_id, 'tool name' )['error'],
				'_metadata' => array(
					'component_type' => 'tool',
					'failure_reason' => 'missing_parameter',
				),
			);
		}

		try {
			// Implement a tool calling logic here.
			$result = $this->handle_tool_call( $request_params, $request_id );

			// Check if the result contains an error.
			// Distinguish between protocol errors (JSON-RPC format) and tool execution errors (isError format).
			if ( isset( $result['error'] ) ) {
				$failure_reason = $result['_metadata']['failure_reason'] ?? '';

				// Protocol errors (keep JSON-RPC error format):
				// - not_found (tool doesn't exist)
				// - ability_retrieval_failed (internal error getting ability)
				$protocol_errors = array( 'not_found', 'ability_retrieval_failed' );

				if ( in_array( $failure_reason, $protocol_errors, true ) ) {
					// Return as JSON-RPC error
					return $result;
				}

				// Tool execution errors (convert to isError: true format):
				// - permission_denied, permission_check_failed
				// - wp_error, execution_failed
				$error_message = $result['error']['message'] ?? 'An error occurred while executing the tool.';
				$response      = array(
					'content' => array(
						array(
							'type' => 'text',
							'text' => $error_message,
						),
					),
					'isError' => true,
				);

				// Preserve metadata if present.
				if ( isset( $result['_metadata'] ) ) {
					$response['_metadata'] = $result['_metadata'];
				}

				return $response;
			}

			// Successful tool execution - format the response.
			$response = array(
				'content' => array(
					array(
						'type' => 'text',
					),
				),
			);

			// Extract and store metadata before adding result to response content.
			$response['_metadata'] = $result['_metadata'] ?? array(
				'component_type' => 'tool',
				'tool_name'      => $request_params['name'],
			);

			// Remove metadata from result so it doesn't appear in content or structuredContent.
			unset( $result['_metadata'] );

			// @todo: add support for EmbeddedResource schema.ts:619.
			if ( isset( $result['type'] ) && 'image' === $result['type'] ) {
				$response['content'][0]['type'] = 'image';
				$response['content'][0]['data'] = base64_encode( $result['results'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

				// @todo: improve this ?!.
				$response['content'][0]['mimeType'] = $result['mimeType'] ?? 'image/png';
			} else {
				$response['content'][0]['text'] = wp_json_encode( $result );
				$response['structuredContent']  = $result;
			}

			return $response;
		} catch ( \Throwable $exception ) {
			$this->mcp->error_handler->log(
				'Error calling tool',
				array(
					'tool'      => $request_params['name'],
					'exception' => $exception->getMessage(),
				)
			);

			return array(
				'error'     => McpErrorFactory::internal_error( $request_id, 'Failed to execute tool' )['error'],
				'_metadata' => array(
					'component_type' => 'tool',
					'tool_name'      => $request_params['name'],
					'failure_reason' => 'exception',
					'error_type'     => get_class( $exception ),
				),
			);
		}
	}

	/**
	 * Sanitize tool data for JSON encoding by removing callback functions and other problematic data.
	 *
	 * @param \WP\MCP\Domain\Tools\McpTool $tool Raw tool data.
	 *
	 * @return array Sanitized tool data safe for JSON encoding.
	 */
	private function sanitize_tool_data( McpTool $tool ): array {
		// Convert the tool to an array representation.
		$tool = $tool->to_array();
		// Create a safe copy with only JSON-serializable data.
		$safe_tool = array(
			'name'        => $tool['name'] ?? '',
			'description' => $tool['description'] ?? '',
			'type'        => $tool['type'] ?? 'action',
		);

		// Include input schema if present (should be JSON-safe).
		if ( isset( $tool['inputSchema'] ) && is_array( $tool['inputSchema'] ) ) {
			$safe_tool['inputSchema'] = $tool['inputSchema'];
		}

		// Include output schema if present (should be JSON-safe).
		if ( isset( $tool['outputSchema'] ) && is_array( $tool['outputSchema'] ) ) {
			$safe_tool['outputSchema'] = $tool['outputSchema'];
		}

		// Include annotations if present.
		if ( isset( $tool['annotations'] ) && is_array( $tool['annotations'] ) ) {
			$safe_tool['annotations'] = $tool['annotations'];
		}

		// Note: We deliberately exclude 'callback' and 'permission_callback'
		// as these are PHP callables that can cause circular references during JSON encoding.

		return $safe_tool;
	}

	/**
	 * Handle tool call request.
	 *
	 * @param array $params     The request parameters.
	 * @param int   $request_id The request ID for JSON-RPC.
	 *
	 * @return array
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

			return array(
				'error'     => McpErrorFactory::tool_not_found( $request_id, $tool_name )['error'],
				'_metadata' => array(
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

			return array(
				'error'     => McpErrorFactory::internal_error( $request_id, $ability->get_error_message() )['error'],
				'_metadata' => array(
					'component_type' => 'tool',
					'tool_name'      => $tool_name,
					'failure_reason' => 'ability_retrieval_failed',
					'error_code'     => $ability->get_error_code(),
				),
			);
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
					'error'     => McpErrorFactory::permission_denied( $request_id, $error_message )['error'],
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
				'error'     => McpErrorFactory::internal_error( $request_id, 'Error running ability permission callback' )['error'],
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
