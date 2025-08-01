<?php //phpcs:ignore
/**
 * Tools method handlers for MCP requests.
 *
 * @package WP\MCP
 */

declare(strict_types=1);

namespace WP\MCP\RequestMethodHandlers;

use WP\MCP\Registry\Server;
use WP\MCP\Utils\ErrorHandler;
use WP\MCP\Utils\HandleToolsCall;

/**
 * Handles tools-related MCP methods.
 */
class ToolsHandler {
	/**
	 * The WordPress MCP instance.
	 *
	 * @var Server
	 */
	private Server $mcp;

	/**
	 * Constructor.
	 *
	 * @param Server $mcp The WordPress MCP instance.
	 */
	public function __construct( Server $mcp ) {
		$this->mcp = $mcp;
	}

	/**
	 * Handle the tools/list request.
	 *
	 * @return array
	 */
	public function list_tools(): array {
		$tools = $this->mcp->get_tools();
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
	 * @param array $params Request parameters.
	 * @return array
	 */
	public function list_all_tools( array $params ): array {
		// Return all tools with additional details.
		$tools = $this->mcp->get_tools();
		$safe_tools = array();

		foreach ( $tools as $tool ) {
			$safe_tool = $this->sanitize_tool_data( $tool );
			$safe_tool['available'] = true;
			$safe_tools[] = $safe_tool;
		}

		return array(
			'tools' => $safe_tools,
		);
	}

	/**
	 * Handle the tools/call request.
	 *
	 * @param array $message Request message.
	 * @return array
	 */
	public function call_tool( array $message ): array {
		// Handle both direct params and nested params structure.
		$request_params = $message['params'] ?? $message;

		if ( ! isset( $request_params['name'] ) ) {
			return array(
				'error' => ErrorHandler::missing_parameter( 0, 'name' )['error'],
			);
		}

		// Clean parameters arguments.
		if ( ! empty( $request_params['arguments'] ) ) {
			foreach ( $request_params['arguments'] as $key => $value ) {
				if ( empty( $value ) || 'null' === $value ) {
					unset( $request_params['arguments'][ $key ] );
				}
			}
		}

		try {
			// Implement a tool calling logic here.
			$result = HandleToolsCall::run( $request_params );

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
				$response['content'][0]['data'] = base64_encode( $result['results'] ); //phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

				// @todo: improve this ?!.
				$response['content'][0]['mimeType'] = $result['mimeType'] ?? 'image/png';
			} else {
				$response['content'][0]['text'] = wp_json_encode( $result );
			}

			return $response;

		} catch ( \Throwable $exception ) {
			ErrorHandler::log(
				'Error calling tool',
				array(
					'tool'      => $request_params['name'],
					'exception' => $exception->getMessage(),
				)
			);
			return array(
				'error' => ErrorHandler::internal_error( 0, 'Failed to execute tool' )['error'],
			);
		}
	}

	/**
	 * Sanitize tool data for JSON encoding by removing callback functions and other problematic data.
	 *
	 * @param array $tool Raw tool data.
	 * @return array Sanitized tool data safe for JSON encoding.
	 */
	private function sanitize_tool_data( array $tool ): array {
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

		// Include annotations if present.
		if ( isset( $tool['annotations'] ) && is_array( $tool['annotations'] ) ) {
			$safe_tool['annotations'] = $tool['annotations'];
		}

		// Include REST alias info if present (but not callbacks).
		if ( isset( $tool['rest_alias'] ) && is_array( $tool['rest_alias'] ) ) {
			$safe_tool['rest_alias'] = array(
				'route'  => $tool['rest_alias']['route'] ?? '',
				'method' => $tool['rest_alias']['method'] ?? '',
			);

			// Include input schema replacements if present.
			if ( isset( $tool['rest_alias']['inputSchemaReplacements'] ) ) {
				$safe_tool['rest_alias']['inputSchemaReplacements'] = $tool['rest_alias']['inputSchemaReplacements'];
			}
		}

		// Note: We deliberately exclude 'callback' and 'permission_callback'
		// as these are PHP callables that can cause circular references during JSON encoding.

		return $safe_tool;
	}
}
