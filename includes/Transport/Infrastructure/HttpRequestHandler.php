<?php
/**
 * HTTP Request Handler for MCP Transport
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Transport\Infrastructure;

use WP\MCP\Core\McpVersionNegotiator;
use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;

/**
 * Handles HTTP request routing and processing for MCP transports.
 *
 * Centralizes request routing logic to eliminate duplication and provide
 * consistent request handling across transport implementations.
 *
 * @internal
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

		// Handle GET requests (reserved for SSE streaming; currently not implemented).
		if ( 'GET' === $context->method ) {
			return $this->handle_sse_request( $context );
		}

		// Handle DELETE requests (session termination)
		if ( 'DELETE' === $context->method ) {
			return $this->handle_session_termination( $context );
		}

		// Method not allowed
		return new \WP_REST_Response(
			McpErrorFactory::invalid_request( null, 'Method not allowed' )->toArray(),
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
					McpErrorFactory::parse_error( null, 'Invalid JSON in request body' )->toArray(),
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
				McpErrorFactory::internal_error( null, 'Handler error occurred' )->toArray(),
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

		// Per MCP spec 2025-06-18: Notifications return HTTP 202 Accepted with no body.
		// A null response_body indicates only notifications were processed (no requests with IDs).
		if ( null === $response_body ) {
			return new \WP_REST_Response( null, 202 );
		}

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
	 * @param array $message The MCP JSON-RPC message.
	 * @param \WP\MCP\Transport\Infrastructure\HttpRequestContext $context The HTTP request context.
	 *
	 * @return array|null JSON-RPC response or null for notifications.
	 */
	private function process_single_message( array $message, HttpRequestContext $context ): ?array {
		// Validate JSON-RPC message format
		$validation = McpErrorFactory::validate_jsonrpc_message( $message );
		if ( true !== $validation ) {
			return $validation->toArray();
		}

		// Handle notifications (no response required)
		if ( isset( $message['method'] ) && ! isset( $message['id'] ) ) {
			return null; // Notifications don't get a response
		}

		// Process requests with IDs
		if ( isset( $message['method'] ) && isset( $message['id'] ) ) {
			return $this->process_jsonrpc_request( $message, $context );
		}

		// JSON-RPC responses from client (has result/error, no method) also return null.
		// Per MCP spec: client responses get HTTP 202 Accepted with no body, same as notifications.
		return null;
	}

	/**
	 * Process a JSON-RPC request message.
	 *
	 * @param array $message The JSON-RPC message.
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

			// Validate MCP-Protocol-Version header for non-initialize requests.
			$protocol_version_error = $this->validate_protocol_version_header( $context );
			if ( null !== $protocol_version_error ) {
				return JsonRpcResponseBuilder::create_error_response( $request_id, $protocol_version_error );
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
	 * Get transport name for observability.
	 *
	 * @return string Transport name.
	 */
	private function get_transport_name(): string {
		return 'HTTP';
	}

	/**
	 * Validate the MCP-Protocol-Version header on non-initialize requests.
	 *
	 * A missing header is accepted (returns null). A header containing a supported
	 * version is also accepted. An unsupported version returns a JSON-RPC
	 * invalid-request error payload.
	 *
	 * @since n.e.x.t
	 *
	 * @param \WP\MCP\Transport\Infrastructure\HttpRequestContext $context The HTTP request context.
	 *
	 * @return array|null Null when the header is absent or valid, error payload otherwise.
	 */
	private function validate_protocol_version_header( HttpRequestContext $context ): ?array {
		if ( null === $context->protocol_version ) {
			return null;
		}

		if ( McpVersionNegotiator::is_supported( $context->protocol_version ) ) {
			return null;
		}

		return McpErrorFactory::create_error(
			McpErrorFactory::INVALID_REQUEST,
			sprintf(
				'Bad Request: Unsupported protocol version: %s (supported versions: %s)',
				$context->protocol_version,
				implode( ', ', McpVersionNegotiator::SUPPORTED_PROTOCOL_VERSIONS )
			)
		)->toArray();
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

	/**
	 * Handle GET requests (SSE streaming).
	 *
	 * @param \WP\MCP\Transport\Infrastructure\HttpRequestContext $context The HTTP request context.
	 *
	 * @return \WP_REST_Response SSE response.
	 */
	private function handle_sse_request( HttpRequestContext $context ): \WP_REST_Response {
		// SSE streaming not yet implemented - return HTTP 405 with no body
		return new \WP_REST_Response( null, 405 );
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
}
