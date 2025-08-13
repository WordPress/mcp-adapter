<?php
/**
 * Service for routing MCP requests to appropriate handlers.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Transport\Infrastructure;

use Throwable;
use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;

/**
 * Service for routing MCP requests to appropriate handlers.
 *
 * Extracted from AbstractMcpTransport to be reusable across
 * all transport implementations via dependency injection.
 */
class McpRequestRouter {

	/**
	 * The transport context.
	 *
	 * @var McpTransportContext
	 */
	private McpTransportContext $context;

	/**
	 * Initialize the request router.
	 *
	 * @param McpTransportContext $context The transport context.
	 */
	public function __construct(
		McpTransportContext $context
	) {
		$this->context = $context;
	}

	/**
	 * Route a request to the appropriate handler.
	 *
	 * @param string $method The MCP method name.
	 * @param array  $params The request parameters.
	 * @param int    $request_id The request ID (for JSON-RPC).
	 * @param string $transport_name Transport name for observability.
	 *
	 * @return array
	 */
	public function route_request( string $method, array $params, int $request_id = 0, string $transport_name = 'unknown' ): array {
		// Track request start time.
		$start_time = microtime( true );

		// Common tags for all metrics.
		$common_tags = array(
			'method'    => $method,
			'transport' => $transport_name,
		);

		// Record request event.
		$this->context->observability_handler::record_event( 'mcp.request.count', $common_tags );

		try {
			$result = match ( $method ) {
				'initialize', 'init' => $this->context->initialize_handler->handle( $request_id ),
				'ping' => $this->context->system_handler->ping( $request_id ),
				'tools/list' => $this->context->tools_handler->list_tools( $request_id ),
				'tools/list/all' => $this->context->tools_handler->list_all_tools( $request_id ),
				'tools/call' => $this->context->tools_handler->call_tool( $params, $request_id ),
				'resources/list' => $this->add_cursor_compatibility( $this->context->resources_handler->list_resources( $request_id ) ),
				'resources/templates/list' => $this->add_cursor_compatibility( $this->context->resources_handler->list_resource_templates( $request_id ) ),
				'resources/read' => $this->context->resources_handler->read_resource( $params, $request_id ),
				'resources/subscribe' => $this->context->resources_handler->subscribe_resource( $params, $request_id ),
				'resources/unsubscribe' => $this->context->resources_handler->unsubscribe_resource( $params, $request_id ),
				'prompts/list' => $this->context->prompts_handler->list_prompts( $request_id ),
				'prompts/get' => $this->context->prompts_handler->get_prompt( $params, $request_id ),
				'logging/setLevel' => $this->context->system_handler->set_logging_level( $params, $request_id ),
				'completion/complete' => $this->context->system_handler->complete( $request_id ),
				'roots/list' => $this->context->system_handler->list_roots( $request_id ),
				default => $this->create_method_not_found_error( $method ),
			};

			// Handle array error formats.
			if ( is_array( $result ) && isset( $result['error'] ) ) {
				// Track failed request.
				$error_code = $result['error']['code'] ?? -32603;
				$error_tags = array_merge(
					$common_tags,
					array( 'error_code' => $error_code )
				);
				$this->context->observability_handler::record_event( 'mcp.request.error', $error_tags );

				return $result;
			}

			// Track successful request.
			$this->context->observability_handler::record_event( 'mcp.request.success', $common_tags );

			return $result;

		} catch ( Throwable $exception ) {
			// Track failed request.
			$error_tags = array_merge(
				$common_tags,
				array( 'error_type' => get_class( $exception ) )
			);
			$this->context->observability_handler::record_event( 'mcp.request.error', $error_tags );

			// Create error response from exception.
			return array( 'error' => McpErrorFactory::internal_error( $request_id, 'Handler error occurred' )['error'] );

		} finally {
			// Track request duration.
			$duration = ( microtime( true ) - $start_time ) * 1000; // Convert to milliseconds.
			$this->context->observability_handler::record_timing( 'mcp.request.duration', $duration, $common_tags );
		}
	}

	/**
	 * Add nextCursor for backward compatibility with existing API.
	 *
	 * @param array $result The result array.
	 * @return array
	 */
	public function add_cursor_compatibility( array $result ): array {
		if ( ! isset( $result['nextCursor'] ) ) {
			$result['nextCursor'] = '';
		}

		return $result;
	}

	/**
	 * Create a method not found error with generic format.
	 *
	 * @param string $method The method that was not found.
	 * @return array
	 */
	private function create_method_not_found_error( string $method ): array {
		return array(
			'error' => McpErrorFactory::method_not_found( 0, $method )['error'],
		);
	}
}
