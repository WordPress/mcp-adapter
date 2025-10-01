<?php
/**
 * HTTP Request Handler for MCP Transport
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Transport\Infrastructure;

use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;

/**
 * Handles HTTP request routing and processing for MCP transports.
 *
 * Centralizes request routing logic to eliminate duplication and provide
 * consistent request handling across transport implementations.
 *
 */
class HttpRequestHandler {

	/**
	 * The transport context.
	 *
	 * @var \WP\MCP\Transport\Infrastructure\McpTransportContext
	 */
	public McpTransportContext $transport_context;

	/**
	 * Constructor.
	 *
	 * @param \WP\MCP\Transport\Infrastructure\McpTransportContext $transport_context The transport context.
	 */
	public function __construct( McpTransportContext $transport_context ) {
		$this->transport_context = $transport_context;
	}

	/**
	 * Route HTTP request to appropriate handler.
	 *
	 * @param \WP\MCP\Transport\Infrastructure\HttpRequestContext $context The HTTP request context.
	 *
	 * @return \WP_REST_Response HTTP response.
	 */
	public function handle_request( HttpRequestContext $context ): \WP_REST_Response {
		// Handle POST requests (sending MCP messages to server)
		if ( 'POST' === $context->method ) {
			return $this->handle_mcp_request( $context );
		}

		// Handle GET requests (listening for messages from server via SSE)
		if ( 'GET' === $context->method ) {
			return $this->handle_sse_request( $context );
		}

		// Handle DELETE requests (session termination)
		if ( 'DELETE' === $context->method ) {
			return $this->handle_session_termination( $context );
		}

		// Method not allowed
		return new \WP_REST_Response(
			McpErrorFactory::internal_error( 0, 'Method not allowed' ),
			405
		);
	}


	/**
	 * Handle MCP POST requests.
	 *
	 * @param \WP\MCP\Transport\Infrastructure\HttpRequestContext $context The HTTP request context.
	 *
	 * @return \WP_REST_Response MCP response.
	 */
	private function handle_mcp_request( HttpRequestContext $context ): \WP_REST_Response {
		try {
			// Validate request body
			if ( null === $context->body ) {
				return new \WP_REST_Response(
					McpErrorFactory::parse_error( 0, 'Invalid JSON in request body' ),
					400
				);
			}

			return $this->process_mcp_messages( $context );
		} catch ( \Throwable $exception ) {
			$this->transport_context->mcp_server->error_handler->log(
				'Unexpected error in handle_mcp_request',
				array(
					'transport' => static::class,
					'server_id' => $this->transport_context->mcp_server->get_server_id(),
					'error'     => $exception->getMessage(),
				)
			);

			return new \WP_REST_Response(
				McpErrorFactory::internal_error( 0, 'Handler error occurred' ),
				500
			);
		}
	}

	/**
	 * Process MCP messages using JsonRpcResponseBuilder.
	 *
	 * @param \WP\MCP\Transport\Infrastructure\HttpRequestContext $context The HTTP request context.
	 *
	 * @return \WP_REST_Response MCP response.
	 */
	private function process_mcp_messages( HttpRequestContext $context ): \WP_REST_Response {
		$is_batch_request = JsonRpcResponseBuilder::is_batch_request( $context->body );
		$messages         = JsonRpcResponseBuilder::normalize_messages( $context->body );

		$response_body = JsonRpcResponseBuilder::process_messages(
			$messages,
			$is_batch_request,
			function ( array $message ) use ( $context ) {
				return $this->process_single_message( $message, $context );
			}
		);

		// Determine HTTP status code based on error type
		if ( ! $is_batch_request && isset( $response_body['error'] ) ) {
			$http_status = McpErrorFactory::get_http_status_for_error( $response_body );
			return new \WP_REST_Response( $response_body, $http_status );
		}

		return new \WP_REST_Response( $response_body, 200 );
	}

	/**
	 * Process a single MCP message.
	 *
	 * @param array              $message The MCP JSON-RPC message.
	 * @param \WP\MCP\Transport\Infrastructure\HttpRequestContext $context The HTTP request context.
	 *
	 * @return array|null JSON-RPC response or null for notifications.
	 */
	private function process_single_message( array $message, HttpRequestContext $context ): ?array {
		// Validate JSON-RPC message format
		$validation = McpErrorFactory::validate_jsonrpc_message( $message );
		if ( isset( $validation['error'] ) ) {
			return $validation;
		}

		// Handle notifications (no response required)
		if ( isset( $message['method'] ) && ! isset( $message['id'] ) ) {
			return null; // Notifications don't get a response
		}

		// Process requests with IDs
		if ( isset( $message['method'] ) && isset( $message['id'] ) ) {
			return $this->process_jsonrpc_request( $message, $context );
		}

		return null;
	}

	/**
	 * Process a JSON-RPC request message.
	 *
	 * @param array              $message The JSON-RPC message.
	 * @param \WP\MCP\Transport\Infrastructure\HttpRequestContext $context The HTTP request context.
	 *
	 * @return array JSON-RPC response.
	 */
	private function process_jsonrpc_request( array $message, HttpRequestContext $context ): array {
		$request_id = $message['id']; // Preserve original scalar ID (string, number, or null)
		$method     = $message['method'];
		$params     = $message['params'] ?? array();

		// Validate session for all requests except initialize (router will handle initialize session creation)
		if ( 'initialize' !== $method ) {
			$session_validation = HttpSessionValidator::validate_session( $context );
			if ( true !== $session_validation ) {
				return JsonRpcResponseBuilder::create_error_response( $request_id, $session_validation['error'] ?? $session_validation );
			}
		}

		// Route the request through the transport context
		$result = $this->transport_context->request_router->route_request(
			$method,
			$params,
			$request_id,
			$this->get_transport_name(),
			$context
		);

		// Handle session header if provided by router
		if ( isset( $result['_session_id'] ) ) {
			$this->add_session_header_to_response( $result['_session_id'] );
			unset( $result['_session_id'] ); // Remove from actual response data
		}

		// Format response based on result
		if ( isset( $result['error'] ) ) {
			return JsonRpcResponseBuilder::create_error_response( $request_id, $result['error'] );
		}

		return JsonRpcResponseBuilder::create_success_response( $request_id, $result );
	}


	/**
	 * Handle GET requests (SSE streaming).
	 *
	 * @param \WP\MCP\Transport\Infrastructure\HttpRequestContext $context The HTTP request context.
	 *
	 * @return \WP_REST_Response SSE response.
	 */
	private function handle_sse_request( HttpRequestContext $context ): \WP_REST_Response {
		// SSE streaming not yet implemented
		return new \WP_REST_Response(
			McpErrorFactory::internal_error( 0, 'SSE streaming not yet implemented' ),
			405
		);
	}

	/**
	 * Handle DELETE requests (session termination).
	 *
	 * @param \WP\MCP\Transport\Infrastructure\HttpRequestContext $context The HTTP request context.
	 *
	 * @return \WP_REST_Response Termination response.
	 */
	private function handle_session_termination( HttpRequestContext $context ): \WP_REST_Response {
		$result = HttpSessionValidator::terminate_session( $context );

		if ( true !== $result ) {
			$http_status = McpErrorFactory::get_http_status_for_error( $result );
			return new \WP_REST_Response( $result, $http_status );
		}

		return new \WP_REST_Response( null, 200 );
	}

	/**
	 * Get transport name for observability.
	 *
	 * @return string Transport name.
	 */
	private function get_transport_name(): string {
		return 'HTTP';
	}

	/**
	 * Add session header to the REST response.
	 *
	 * Uses a static flag to prevent multiple filters from being added
	 * if this method is called multiple times during a single request
	 * (e.g., during batch JSON-RPC processing).
	 *
	 * @param string $session_id The session ID to add to the response header.
	 *
	 * @return void
	 */
	private function add_session_header_to_response( string $session_id ): void {
		static $current_session_id = null;

		// Only add filter once per request, or if session ID changes
		if ( null !== $current_session_id && $current_session_id === $session_id ) {
			return;
		}

		add_filter(
			'rest_post_dispatch',
			static function ( $response ) use ( $session_id ) {
				if ( $response instanceof \WP_REST_Response ) {
					$response->header( 'Mcp-Session-Id', $session_id );
				}

				return $response;
			}
		);

		$current_session_id = $session_id;
	}
}
