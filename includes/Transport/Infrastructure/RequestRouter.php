<?php
/**
 * Service for routing MCP requests to appropriate handlers.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Transport\Infrastructure;

use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;

/**
 * Service for routing MCP requests to appropriate handlers.
 *
 * Extracted from AbstractMcpTransport to be reusable across
 * all transport implementations via dependency injection.
 */
class RequestRouter {

	/**
	 * The transport context.
	 *
	 * @var \WP\MCP\Transport\Infrastructure\McpTransportContext
	 */
	private McpTransportContext $context;

	/**
	 * Initialize the request router.
	 *
	 * @param \WP\MCP\Transport\Infrastructure\McpTransportContext $context The transport context.
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
	 * @param mixed  $request_id The request ID (for JSON-RPC) - string, number, or null.
	 * @param string $transport_name Transport name for observability.
	 * @param \WP\MCP\Transport\Infrastructure\HttpRequestContext|null $http_context HTTP context for session management.
	 *
	 * @return array
	 */
	public function route_request( string $method, array $params, $request_id = 0, string $transport_name = 'unknown', ?HttpRequestContext $http_context = null ): array {
		// Track request start time.
		$start_time = microtime( true );

		// Common tags for all metrics.
		$common_tags = array(
			'method'     => $method,
			'transport'  => $transport_name,
			'server_id'  => $this->context->mcp_server->get_server_id(),
			'params'     => $this->sanitize_params_for_logging( $params ),
			'request_id' => $request_id,
			'session_id' => $http_context ? $http_context->session_id : null,
		);

		$handlers = array(
			'initialize'          => fn() => $this->handle_initialize_with_session( $params, $request_id, $http_context ),
			'ping'                => fn() => $this->context->system_handler->ping( $request_id ),
			'tools/list'          => fn() => $this->context->tools_handler->list_tools( $request_id ),
			'tools/list/all'      => fn() => $this->context->tools_handler->list_all_tools( $request_id ),
			'tools/call'          => fn() => $this->context->tools_handler->call_tool( $params, $request_id ),
			'resources/list'      => fn() => $this->add_cursor_compatibility( $this->context->resources_handler->list_resources( $request_id ) ),
			'resources/read'      => fn() => $this->context->resources_handler->read_resource( $params, $request_id ),
			'prompts/list'        => fn() => $this->context->prompts_handler->list_prompts( $request_id ),
			'prompts/get'         => fn() => $this->context->prompts_handler->get_prompt( $params, $request_id ),
			'logging/setLevel'    => fn() => $this->context->system_handler->set_logging_level( $params, $request_id ),
			'completion/complete' => fn() => $this->context->system_handler->complete( $request_id ),
			'roots/list'          => fn() => $this->context->system_handler->list_roots( $request_id ),
		);

		try {
			$result = isset( $handlers[ $method ] ) ? $handlers[ $method ]() : $this->create_method_not_found_error( $method );

			// Calculate request duration.
			$duration = ( microtime( true ) - $start_time ) * 1000; // Convert to milliseconds.

			// Extract metadata from handler response (if present).
			$metadata = $result['_metadata'] ?? array();
			unset( $result['_metadata'] ); // Don't send to client.

			// Capture newly created session ID from initialize if present.
			if ( isset( $result['_session_id'] ) ) {
				$metadata['new_session_id'] = $result['_session_id'];
			}

			// Merge common tags with handler metadata.
			$tags = array_merge( $common_tags, $metadata );

			// Determine status and record event.
			if ( isset( $result['error'] ) ) {
				$tags['status']     = 'error';
				$tags['error_code'] = $result['error']['code'] ?? -32603;
				$this->context->observability_handler->record_event( 'mcp.request', $tags, $duration );

				return $result;
			}

			// Successful request.
			$tags['status'] = 'success';
			$this->context->observability_handler->record_event( 'mcp.request', $tags, $duration );

			return $result;
		} catch ( \Throwable $exception ) {
			// Calculate request duration.
			$duration = ( microtime( true ) - $start_time ) * 1000; // Convert to milliseconds.

			// Track exception with categorization.
			$tags = array_merge(
				$common_tags,
				array(
					'status'         => 'error',
					'error_type'     => get_class( $exception ),
					'error_category' => $this->categorize_error( $exception ),
				)
			);
			$this->context->observability_handler->record_event( 'mcp.request', $tags, $duration );

			// Create error response from exception.
			return array( 'error' => McpErrorFactory::internal_error( $request_id, 'Handler error occurred' )['error'] );
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
	 * Handle initialize requests with session management.
	 *
	 * @param array $params The request parameters.
	 * @param mixed $request_id The request ID.
	 * @param \WP\MCP\Transport\Infrastructure\HttpRequestContext|null $http_context HTTP context for session management.
	 * @return array
	 */
	private function handle_initialize_with_session( array $params, $request_id, ?HttpRequestContext $http_context ): array {
		// Get the initialize response from the handler
		$result = $this->context->initialize_handler->handle( $request_id );

		// Handle session creation if HTTP context is provided and initialize was successful
		if ( $http_context && ! isset( $result['error'] ) && ! $http_context->session_id ) {
			$session_result = HttpSessionValidator::create_session( $params );

			if ( is_array( $session_result ) ) {
				// Session creation failed, return error
				return array( 'error' => $session_result );
			}

			// Store session ID in result for HttpRequestHandler to add as header
			$result['_session_id'] = $session_result;
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

	/**
	 * Categorize an exception into a general error category.
	 *
	 * @param \Throwable $exception The exception to categorize.
	 *
	 * @return string
	 */
	private function categorize_error( \Throwable $exception ): string {
		$error_categories = array(
			\ArgumentCountError::class       => 'arguments',
			\Error::class                    => 'system',
			\InvalidArgumentException::class => 'validation',
			\LogicException::class           => 'logic',
			\RuntimeException::class         => 'execution',
			\TypeError::class                => 'type',
		);

		return $error_categories[ get_class( $exception ) ] ?? 'unknown';
	}

	/**
	 * Sanitize request params for logging to remove sensitive data and limit size.
	 *
	 * @param array $params The request parameters to sanitize.
	 *
	 * @return array Sanitized parameters safe for logging.
	 */
	private function sanitize_params_for_logging( array $params ): array {
		// Return early for empty parameters.
		if ( empty( $params ) ) {
			return array();
		}

		$sanitized = array();

		// Extract only safe, useful fields for observability
		$safe_fields = array( 'name', 'protocolVersion', 'uri' );

		foreach ( $safe_fields as $field ) {
			if ( ! isset( $params[ $field ] ) || ! is_scalar( $params[ $field ] ) ) {
				continue;
			}

			$sanitized[ $field ] = $params[ $field ];
		}

		// Add clientInfo name if available (useful for debugging)
		if ( isset( $params['clientInfo']['name'] ) ) {
			$sanitized['client_name'] = $params['clientInfo']['name'];
		}

		// Add arguments count for tool calls (but not the actual arguments to avoid logging sensitive data)
		if ( isset( $params['arguments'] ) && is_array( $params['arguments'] ) ) {
			$sanitized['arguments_count'] = count( $params['arguments'] );
			$sanitized['arguments_keys']  = array_keys( $params['arguments'] );
		}

		return $sanitized;
	}
}
