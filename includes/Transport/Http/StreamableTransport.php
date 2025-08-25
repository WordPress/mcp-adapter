<?php
/**
 * The WordPress MCP Streamable HTTP Transport class.
 *
 * @todo: this is not used yet and it requires OAuth2.1 implementation according to MCP requirements https://modelcontextprotocol.io/specification/2025-06-18/basic/authorization#standards-compliance.
 *
 * @package WordPressMcp
 */

declare( strict_types=1 );

namespace WP\MCP\Transport\Http;

use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;
use WP\MCP\Transport\Contracts\McpTransportInterface;
use WP\MCP\Transport\Infrastructure\McpTransportContext;
use WP\MCP\Transport\Infrastructure\McpTransportHelperTrait;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * The WordPress MCP Streamable HTTP Transport class.
 * Uses JSON-RPC 2.0 format for direct streamable connections.
 */
class StreamableTransport implements McpTransportInterface {
	use McpTransportHelperTrait;

	/**
	 * The transport context.
	 *
	 * @var \WP\MCP\Transport\Infrastructure\McpTransportContext
	 */
	private McpTransportContext $context;
	/**
	 * The request ID.
	 *
	 * @var int
	 */
	private int $request_id = 0;

	/**
	 * Initialize the class and register routes
	 *
	 * @param \WP\MCP\Transport\Infrastructure\McpTransportContext $context The transport context.
	 */
	public function __construct( McpTransportContext $context ) {
		$this->context = $context;
		add_action( 'rest_api_init', array( $this, 'register_routes' ), 20002 );
	}

	/**
	 * Register MCP streamable route
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->context->mcp_server->get_server_route_namespace(),
			$this->context->mcp_server->get_server_route() . '/streamable',
			array(
				'methods'             => WP_REST_Server::ALLMETHODS,
				'callback'            => array( $this, 'handle_request' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	/**
	 * Check if the user has permission to access the MCP API
	 *
	 * @param \WP_REST_Request|null $request The request object.
	 *
	 * @return bool|\WP_Error
	 */
	public function check_permission( ?WP_REST_Request $request = null ) {
		// Use custom permission callback if provided
		if ( null !== $this->context->transport_permission_callback ) {
			try {
				return call_user_func( $this->context->transport_permission_callback, $request );
			} catch ( \Throwable $e ) {
				// Log error and fall back to default
				if ( $this->context->mcp_server->error_handler ) {
					$this->context->mcp_server->error_handler->log(
						'Transport permission callback failed',
						array(
							'transport' => static::class,
							'server_id' => $this->context->mcp_server->get_server_id(),
							'error'     => $e->getMessage(),
						)
					);
				}

				// Fall back to secure default
				return is_user_logged_in();
			}
		}

		// Secure default: require logged-in user
		return is_user_logged_in();
	}

	/**
	 * Handle the HTTP request
	 *
	 * @param mixed $request The request object.
	 *
	 * @return \WP_REST_Response
	 */
	public function handle_request( $request ): WP_REST_Response {
		// Handle preflight requests.
		if ( 'OPTIONS' === $request->get_method() ) {
			return new WP_REST_Response( null );
		}

		$method = $request->get_method();

		if ( 'POST' === $method ) {
			return $this->handle_post_request( $request );
		}

		// Return 405 for unsupported methods.
		$error = McpErrorFactory::internal_error( 0, 'Method not allowed' );
		return new WP_REST_Response( $error, 405 );
	}

	/**
	 * Handle POST requests
	 *
	 * @param \WP_REST_Request $request The request object.
	 *
	 * @return \WP_REST_Response
	 */
	private function handle_post_request( WP_REST_Request $request ): WP_REST_Response {
		try {
			// Validate Accept header - client MUST include both content types.
			$accept_header = $request->get_header( 'accept' );
			if ( ! $accept_header ||
				! str_contains( $accept_header, 'application/json' ) ||
				! str_contains( $accept_header, 'text/event-stream' ) ) {
				$error = McpErrorFactory::invalid_request( 0, 'Invalid Accept header' );
				return new WP_REST_Response( $error, 406 );
			}

			// Validate content type - be more flexible with content-type headers.
			$content_type = $request->get_header( 'content-type' );
			if ( $content_type && ! str_contains( $content_type, 'application/json' ) ) {
				$error = McpErrorFactory::invalid_request( 0, 'Invalid Content-Type' );
				return new WP_REST_Response( $error, 415 );
			}

			// Get the JSON-RPC message(s) - can be single message or array batch.
			$body = $request->get_json_params();
			if ( null === $body ) {
				return new WP_REST_Response( McpErrorFactory::parse_error( 0, 'Invalid JSON in request body' ), 400 );
			}

			// Handle both single messages and batched arrays.
			$messages                       = is_array( $body ) && isset( $body[0] ) ? $body : array( $body );
			$has_requests                   = false;
			$has_notifications_or_responses = false;

			// Validate all messages and categorize them.
			foreach ( $messages as $message ) {
				$validation_result = McpErrorFactory::validate_jsonrpc_message( $message );
				if ( true !== $validation_result ) {
					// validation_result is an error array from factory
					return new WP_REST_Response( $validation_result, 400 );
				}

				// Check if it's a request (has id and method) or notification/response.
				if ( isset( $message['method'] ) && isset( $message['id'] ) ) {
					$has_requests = true;
				} else {
					$has_notifications_or_responses = true;
				}
			}

			// If only notifications or responses, return 202 Accepted with no body.
			if ( $has_notifications_or_responses && ! $has_requests ) {
				return new WP_REST_Response( null );
			}

			// Process requests and return JSON response.
			$results        = array();
			$has_initialize = false;
			foreach ( $messages as $message ) {
				if ( ! isset( $message['method'] ) || ! isset( $message['id'] ) ) {
					continue;
				}

				$this->request_id = (int) $message['id'];
				if ( 'initialize' === $message['method'] ) {
					$has_initialize = true;
				}
				$results[] = $this->process_message( $message );
			}

			// Return single result or batch.
			$response_body = count( $results ) === 1 ? $results[0] : $results;

			$headers = array(
				'Content-Type'                 => 'application/json',
				'Access-Control-Allow-Origin'  => '*',
				'Access-Control-Allow-Methods' => 'OPTIONS, GET, POST, PUT, PATCH, DELETE',
			);

			return new WP_REST_Response( $response_body, 200, $headers );
		} catch ( \Throwable $exception ) {
			// Log the error using the error handler if available.
			if ( $this->context->mcp_server->error_handler ) {
				$this->context->mcp_server->error_handler->log( 'Unexpected error in handle_post_request', array( 'exception' => $exception->getMessage() ) );
			}

			$error = McpErrorFactory::internal_error( 0, 'Handler error occurred' );
			return new WP_REST_Response( $error, 500 );
		}
	}

	/**
	 * Process a JSON-RPC message
	 *
	 * @param array $message The JSON-RPC message.
	 *
	 * @return array
	 */
	private function process_message( array $message ): array {
		$this->request_id = (int) $message['id'];
		$params           = $message['params'] ?? array();

		// Route the request using the request router.
		$result = $this->context->request_router->route_request( $message['method'], $params, $this->request_id, $this->get_transport_name() );

		// Check if the result contains an error.
		if ( isset( $result['error'] ) ) {
			return $this->format_error_response( $result, $this->request_id );
		}

		return $this->format_success_response( $result, $this->request_id );
	}



	/**
	 * Format a successful response (JSON-RPC 2.0 format)
	 *
	 * @param array $result The result data.
	 * @param int   $request_id The request ID.
	 *
	 * @return array
	 */
	protected function format_success_response( array $result, int $request_id = 0 ): array {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $request_id,
			'result'  => $result,
		);
	}

	/**
	 * Format an error response (JSON-RPC 2.0 format)
	 *
	 * @param array $error The error data.
	 * @param int   $request_id The request ID.
	 *
	 * @return array
	 */
	protected function format_error_response( array $error, int $request_id = 0 ): array {
		if ( isset( $error['error'] ) ) {
			return array(
				'jsonrpc' => '2.0',
				'id'      => $request_id,
				'error'   => $error['error'],
			);
		}

		// If it's not already a proper error response, make it one.
		return McpErrorFactory::internal_error( $request_id, 'Invalid error response format' );
	}
}
