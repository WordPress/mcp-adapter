<?php
/**
 * HTTP Request Handler for MCP Transport
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Transport\Infrastructure;

use WP\MCP\Core\McpProtocolContext;
use WP\MCP\Core\McpVersionNegotiator;
use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;
use WP\MCP\Infrastructure\Protocol\V20260728WireEncoder;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

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
	 * Get the transport context.
	 *
	 * @since 0.5.0
	 *
	 * @return \WP\MCP\Transport\Infrastructure\McpTransportContext
	 */
	public function get_transport_context(): McpTransportContext {
		return $this->transport_context;
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
			return $this->handle_sse_request();
		}

		// Handle DELETE requests (session termination)
		if ( 'DELETE' === $context->method ) {
			return $this->handle_session_termination( $context );
		}

		// Method not allowed
		return new \WP_REST_Response(
			McpErrorFactory::invalid_request( null, 'Method not allowed' ),
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
			if ( $context->body_has_parse_error ) {
				return new \WP_REST_Response(
					McpErrorFactory::parse_error( null, 'Invalid JSON in request body' ),
					400
				);
			}

			if ( ! $context->identity_body instanceof \stdClass && ! is_array( $context->identity_body ) ) {
				return new \WP_REST_Response(
					McpErrorFactory::invalid_request( null, 'The JSON sent is not a valid Request object' ),
					400
				);
			}

			return $this->process_mcp_messages( $context );
		} catch ( \Throwable $exception ) {
			$this->transport_context->mcp_server->get_error_handler()->log(
				'Unexpected error in handle_mcp_request',
				array(
					'transport' => static::class,
					'server_id' => $this->transport_context->mcp_server->get_server_id(),
					'error'     => $exception->getMessage(),
				)
			);

			return new \WP_REST_Response(
				McpErrorFactory::internal_error( null, 'Handler error occurred' ),
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
		$is_batch_request  = JsonRpcResponseBuilder::is_batch_request( $context->identity_body );
		$messages          = JsonRpcResponseBuilder::normalize_messages( $context->identity_body );
		$identities        = $is_batch_request ? $context->identity_body : array( $context->identity_body );
		$identity_index    = 0;
		$response_revision = null;

		$response_body = JsonRpcResponseBuilder::process_messages(
			$messages,
			$is_batch_request,
			function ( array $message ) use ( $context, $identities, &$identity_index, &$response_revision ) {
				$identity = $identities[ $identity_index ] ?? null;
				++$identity_index;

				return $this->process_single_message(
					$message,
					$context,
					$identity instanceof \stdClass ? $identity : null,
					$response_revision
				);
			}
		);

		// Legacy session-era notifications return HTTP 202 Accepted with no body.
		// A null response_body indicates only notifications were processed (no requests with IDs).
		if ( null === $response_body ) {
			return new \WP_REST_Response( null, 202 );
		}

		// Determine HTTP status code based on error type
		if ( ! $is_batch_request && isset( $response_body['error'] ) ) {
			$http_status = McpErrorFactory::get_http_status_for_error( $response_body, $response_revision );

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
	 * @param \stdClass|null $request_identity Identity-preserving request object.
	 * @param string|null $response_revision Exact revision used for the response.
	 *
	 * @return array|null JSON-RPC response or null for notifications.
	 */
	private function process_single_message( array $message, HttpRequestContext $context, ?\stdClass $request_identity = null, ?string &$response_revision = null ): ?array {
		// Validate JSON-RPC message format
		$validation = McpErrorFactory::validate_jsonrpc_message( $message );
		if ( true !== $validation ) {
			return $validation;
		}

		// Handle notifications (no response required)
		if ( isset( $message['method'] ) && ! isset( $message['id'] ) ) {
			return null; // Notifications don't get a response
		}

		// Process requests with IDs
		if ( isset( $message['method'] ) && isset( $message['id'] ) ) {
			return $this->process_jsonrpc_request( $message, $context, $request_identity, $response_revision );
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
	 * @param \stdClass|null $request_identity Identity-preserving request object.
	 * @param string|null $response_revision Exact revision used for the response.
	 *
	 * @return array JSON-RPC response.
	 */
	private function process_jsonrpc_request( array $message, HttpRequestContext $context, ?\stdClass $request_identity = null, ?string &$response_revision = null ): array {
		$request_id = $message['id']; // Preserve original scalar ID (string, number, or null)
		$method     = $message['method'];
		$params     = $message['params'] ?? array();

		if ( ! is_array( $params ) ) {
			return McpErrorFactory::invalid_params( $request_id, 'params must be an object' );
		}

		$resolution        = $this->resolve_protocol_context( $method, $params, $request_id, $context );
		$response_revision = $resolution['revision'];

		if ( isset( $resolution['error'] ) ) {
			return $resolution['error'];
		}

		$protocol_context = $resolution['context'] ?? null;
		if ( ! $protocol_context instanceof McpProtocolContext ) {
			throw new \LogicException( 'A successful protocol resolution must include a context.' );
		}

		// Route the request through the transport context
		$new_session_id = null;
		$result         = $this->transport_context->request_router->route_request(
			$method,
			$params,
			$request_id,
			$this->get_transport_name(),
			$context,
			$request_identity,
			$new_session_id,
			$protocol_context
		);

		// Carry transport session metadata outside the validated MCP result.
		if ( null !== $new_session_id ) {
			$this->add_session_header_to_response( $new_session_id );
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
	 * Resolve and validate the request revision before session or handler work.
	 *
	 * @param string $method JSON-RPC method.
	 * @param array<string, mixed> $params Request parameters.
	 * @param string|int|null $request_id Request id.
	 * @param \WP\MCP\Transport\Infrastructure\HttpRequestContext $context HTTP context.
	 *
	 * @return array{revision: string, context?: \WP\MCP\Core\McpProtocolContext, error?: array<string, mixed>}
	 */
	private function resolve_protocol_context( string $method, array $params, $request_id, HttpRequestContext $context ): array {
		if ( 'initialize' === $method ) {
			return array(
				'revision' => McpVersionNegotiator::LEGACY_PROTOCOL_VERSION,
				'context'  => McpProtocolContext::for_revision( McpVersionNegotiator::LEGACY_PROTOCOL_VERSION ),
			);
		}

		$request_version = McpVersionNegotiator::request_protocol_version( $params );
		$header_version  = $context->protocol_version;
		$modern_signal   = 'server/discover' === $method
			|| ( null !== $request_version && ! McpVersionNegotiator::is_legacy( $request_version ) )
			|| McpVersionNegotiator::MODERN_PROTOCOL_VERSION === $header_version;

		if ( $modern_signal ) {
			$encoder = $this->transport_context->mcp_server->get_wire_encoder_for_revision( McpVersionNegotiator::MODERN_PROTOCOL_VERSION );
			if ( ! $encoder instanceof V20260728WireEncoder ) {
				throw new \LogicException( 'Modern MCP revision resolved to the wrong encoder.' );
			}

			if ( null === $header_version ) {
				return array(
					'revision' => McpVersionNegotiator::MODERN_PROTOCOL_VERSION,
					'error'    => $encoder->header_mismatch_error( $request_id, 'Missing MCP-Protocol-Version header' ),
				);
			}

			if ( null === $request_version ) {
				return array(
					'revision' => McpVersionNegotiator::MODERN_PROTOCOL_VERSION,
					'error'    => McpErrorFactory::invalid_params( $request_id, 'Missing or malformed protocol version in request _meta' ),
				);
			}

			if ( $header_version !== $request_version ) {
				return array(
					'revision' => McpVersionNegotiator::MODERN_PROTOCOL_VERSION,
					'error'    => $encoder->header_mismatch_error( $request_id ),
				);
			}

			if ( ! McpVersionNegotiator::is_supported( $request_version ) ) {
				return array(
					'revision' => McpVersionNegotiator::MODERN_PROTOCOL_VERSION,
					'error'    => $encoder->unsupported_protocol_version_error(
						$request_id,
						$request_version,
						McpVersionNegotiator::SUPPORTED_PROTOCOL_VERSIONS
					),
				);
			}

			if ( McpVersionNegotiator::is_modern( $request_version ) ) {
				return array(
					'revision' => $request_version,
					'context'  => McpProtocolContext::for_revision( $request_version ),
				);
			}
		}

		$session_version = HttpSessionValidator::validate_session_protocol_version( $context, $this->transport_context->error_handler );
		if ( is_array( $session_version ) ) {
			return array(
				'revision' => McpVersionNegotiator::LEGACY_PROTOCOL_VERSION,
				'error'    => JsonRpcResponseBuilder::create_error_response( $request_id, $session_version['error'] ?? $session_version ),
			);
		}

		if ( null !== $header_version && ! McpVersionNegotiator::is_supported( $header_version ) ) {
			return array(
				'revision' => $session_version,
				'error'    => JsonRpcResponseBuilder::create_error_response(
					$request_id,
					McpErrorFactory::create_error(
						McpErrorFactory::INVALID_REQUEST,
						sprintf(
							'Bad Request: Unsupported protocol version: %s (supported versions: %s)',
							$header_version,
							implode( ', ', McpVersionNegotiator::SUPPORTED_PROTOCOL_VERSIONS )
						)
					)
				),
			);
		}

		if ( null !== $header_version && $header_version !== $session_version ) {
			return array(
				'revision' => $session_version,
				'error'    => McpErrorFactory::invalid_request( $request_id, 'MCP-Protocol-Version header does not match the negotiated session version' ),
			);
		}

		return array(
			'revision' => $session_version,
			'context'  => McpProtocolContext::for_revision( $session_version ),
		);
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
	 * @return \WP_REST_Response SSE response.
	 */
	private function handle_sse_request(): \WP_REST_Response {
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
		$result = HttpSessionValidator::terminate_session_with_error_handler( $context, $this->transport_context->error_handler );

		if ( true !== $result ) {
			$http_status = McpErrorFactory::get_http_status_for_error( $result );

			return new \WP_REST_Response( $result, $http_status );
		}

		return new \WP_REST_Response( null, 200 );
	}
}
