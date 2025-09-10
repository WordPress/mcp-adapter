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
use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;

/**
 * Handles tools-related MCP methods.
 */
class ToolsHandler {
	/**
	 * Error categories keyed by throwable class name.
	 *
	 * @used-by ::categorize_error() method.
	*/
	private static array $error_categories = array(
		\ArgumentCountError::class       => 'arguments',
		\Error::class                    => 'system',
		\InvalidArgumentException::class => 'validation',
		\LogicException::class           => 'logic',
		\RuntimeException::class         => 'execution',
		\TypeError::class                => 'type',
	);

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
			'tools' => $safe_tools,
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
			'tools' => $safe_tools,
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
		// Handle both direct params and nested params structure.
		$request_params = $message['params'] ?? $message;

		if ( ! isset( $request_params['name'] ) ) {
			return array( 'error' => McpErrorFactory::missing_parameter( $request_id, 'name' )['error'] );
		}

		try {
			// Implement a tool calling logic here.
			$result = $this->handle_tool_call( $request_params, $request_id );

			// Check if the result contains an error.
			if ( isset( $result['error'] ) ) {
				return $result; // Return error directly.
			}

			$response = array(
				'content' => array(
					array(
						'type' => 'text',
					),
				),
			);

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
			if ( $this->mcp->error_handler ) {
				$this->mcp->error_handler->log(
					'Error calling tool',
					array(
						'tool'      => $request_params['name'],
						'exception' => $exception->getMessage(),
					)
				);
			}

			return array( 'error' => McpErrorFactory::internal_error( $request_id, 'Failed to execute tool' )['error'] );
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
	 * @param array $message    The message.
	 * @param int   $request_id The request ID for JSON-RPC.
	 *
	 * @return array
	 */
	public function handle_tool_call( array $message, int $request_id = 0 ): array {
		$tool_name = $message['params']['name'] ?? $message['name'] ?? '';
		$args      = $message['params']['arguments'] ?? $message['arguments'] ?? array();

		// Get the tool callbacks.
		$tool = $this->mcp->get_tool( $tool_name );

		// Check if the tool exists.
		if ( ! $tool ) {
			if ( $this->mcp->error_handler ) {
				$this->mcp->error_handler->log(
					'Tool not found',
					array(
						'tool' => $tool_name,
					)
				);
			}

			// Track tool not found event.
			$this->mcp->observability_handler::record_event(
				'mcp.tool.not_found',
				array(
					'tool_name' => $tool_name,
					'server_id' => $this->mcp->get_server_id(),
				)
			);

			return array( 'error' => McpErrorFactory::tool_not_found( $request_id, $tool_name )['error'] );
		}

		/**
		 * Assume tools can only be registered with valid abilities.
		 * If not, the has_permission() will let us know in the try-catch block.
		 *
		 * @var \WP_Ability $ability
		 */
		$ability = $tool->get_ability();

		// Run ability Permission Callback.
		try {
			$has_permission = $ability->has_permission( $args );
			if ( true !== $has_permission ) {
				// Track permission denied event.
				$this->mcp->observability_handler::record_event(
					'mcp.tool.permission_denied',
					array(
						'tool_name'    => $tool_name,
						'ability_name' => $ability->get_name(),
						'server_id'    => $this->mcp->get_server_id(),
					)
				);

				return array( 'error' => McpErrorFactory::permission_denied( $request_id, 'Access denied for tool: ' . $tool_name )['error'] );
			}
		} catch ( \Throwable $e ) {
			if ( $this->mcp->error_handler ) {
				$this->mcp->error_handler->log(
					'Error running ability permission callback',
					array(
						'ability'   => $ability->get_name(),
						'exception' => $e->getMessage(),
					)
				);
			}

			// Track permission check error event.
			$this->mcp->observability_handler::record_event(
				'mcp.tool.permission_check_failed',
				array(
					'tool_name'    => $tool_name,
					'ability_name' => $ability->get_name(),
					'error_type'   => get_class( $e ),
					'server_id'    => $this->mcp->get_server_id(),
				)
			);

			return array( 'error' => McpErrorFactory::internal_error( $request_id, 'Error running ability permission callback' )['error'] );
		}

		// Execute the tool callback.
		try {
			$result = $ability->execute( $args );

			// Track successful tool execution.
			$this->mcp->observability_handler::record_event(
				'mcp.tool.execution_success',
				array(
					'tool_name'    => $tool_name,
					'ability_name' => $ability->get_name(),
					'server_id'    => $this->mcp->get_server_id(),
				)
			);

			return $result;
		} catch ( \Throwable $e ) {
			if ( $this->mcp->error_handler ) {
				$this->mcp->error_handler->log(
					'Tool execution failed',
					array(
						'tool'      => $tool_name,
						'exception' => $e->getMessage(),
					)
				);
			}

			// Track tool execution error event.
			$this->mcp->observability_handler::record_event(
				'mcp.tool.execution_failed',
				array(
					'tool_name'      => $tool_name,
					'ability_name'   => $ability->get_name(),
					'error_type'     => get_class( $e ),
					'error_category' => $this->categorize_error( $e ),
					'server_id'      => $this->mcp->get_server_id(),
				)
			);

			return array( 'error' => McpErrorFactory::internal_error( $request_id, 'Error executing tool' )['error'] );
		}
	}

	/**
	 * Categorize an exception into a general error category.
	 *
	 * @param \Throwable $exception The exception to categorize.
	 *
	 * @return string
	 */
	private function categorize_error( \Throwable $exception ): string {
		return self::$error_categories[ get_class( $exception ) ] ?? 'unknown';
	}
}
