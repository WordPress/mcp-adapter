<?php
/**
 * MCP HTTP Transport for WordPress - MCP 2025-06-18 Compliant
 *
 * This transport implements the MCP Streamable HTTP specification and can work
 * both with and without the mcp-wordpress-remote proxy.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Transport;

use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;
use WP\MCP\Transport\Contracts\McpTransportInterface;
use WP\MCP\Transport\Infrastructure\McpTransportContext;
use WP\MCP\Transport\Infrastructure\McpTransportHelperTrait;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * MCP HTTP Transport - Unified transport for both proxy and direct clients
 *
 * Implements MCP 2025-06-18 Streamable HTTP specification with intelligent
 * detection for proxy vs direct client requests.
 */
class HttpTransport implements McpTransportInterface {
	use McpTransportHelperTrait;

	/**
	 * The transport context.
	 *
	 * @var \WP\MCP\Transport\Infrastructure\McpTransportContext
	 */
	private McpTransportContext $context;

	/**
	 * Session expiration time in seconds (24 hours)
	 *
	 * @var int
	 */
	private const SESSION_EXPIRATION = DAY_IN_SECONDS;

	/**
	 * Initialize the class and register routes
	 *
	 * @param \WP\MCP\Transport\Infrastructure\McpTransportContext $context The transport context.
	 */
	public function __construct( McpTransportContext $context ) {
		$this->context = $context;
		add_action( 'rest_api_init', array( $this, 'register_routes' ), 20001 );
	}

	/**
	 * Register MCP HTTP routes
	 */
	public function register_routes(): void {
		// Single endpoint that handles all HTTP methods per MCP spec
		register_rest_route(
			$this->context->mcp_server->get_server_route_namespace(),
			$this->context->mcp_server->get_server_route(),
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
	 * @param \WP_REST_Request<array<string, mixed>>|null $request The request object.
	 *
	 * @return bool|\WP_Error
	 */
	public function check_permission( ?WP_REST_Request $request = null ) {
		// Validate Origin header for security (MCP requirement)
		if ( $request ) {
			$origin = $request->get_header( 'origin' );
			if ( $origin && ! $this->is_allowed_origin( $origin ) ) {
				return new \WP_Error(
					'forbidden_origin',
					'Origin not allowed',
					array( 'status' => 403 )
				);
			}
		}

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

				return false;
			}
		}

		// Secure default: require logged-in user
		return is_user_logged_in();
	}

	/**
	 * Handle HTTP requests according to MCP 2025-06-18 specification
	 *
	 * @param mixed $request The request object.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_request( $request ) {
		$method = $request->get_method();

		// Handle preflight requests (CORS)
		if ( 'OPTIONS' === $method ) {
			return $this->handle_preflight_request( $request );
		}

		// Handle POST requests (sending MCP messages to server)
		if ( 'POST' === $method ) {
			return $this->handle_mcp_request( $request );
		}

		// Handle GET requests (listening for messages from server via SSE)
		if ( 'GET' === $method ) {
			return $this->handle_sse_request( $request );
		}

		// Handle DELETE requests (session termination)
		if ( 'DELETE' === $method ) {
			return $this->handle_session_termination( $request );
		}

		// Method not allowed
		$error = McpErrorFactory::internal_error( 0, 'Method not allowed' );
		return new WP_REST_Response( $error, 405 );
	}

	/**
	 * Handle CORS preflight requests
	 *
	 * @param mixed $request The request object.
	 *
	 * @return \WP_REST_Response
	 */
	private function handle_preflight_request( $request ): WP_REST_Response {
		$headers = array(
			'Access-Control-Allow-Origin'  => $this->get_cors_origin( $request ),
			'Access-Control-Allow-Methods' => 'GET, POST, DELETE, OPTIONS',
			'Access-Control-Allow-Headers' => 'Content-Type, Accept, Mcp-Session-Id, MCP-Protocol-Version, Origin',
			'Access-Control-Max-Age'       => self::SESSION_EXPIRATION, // 24 hours
		);

		return new WP_REST_Response( null, 200, $headers );
	}

	/**
	 * Handle MCP requests - unified handling for both proxy and direct clients
	 *
	 * @param mixed $request The request object.
	 *
	 * @return \WP_REST_Response
	 */
	private function handle_mcp_request( $request ): WP_REST_Response {
		try {
			// Get request body
			$body = $request->get_json_params();
			if ( null === $body ) {
				$error = McpErrorFactory::parse_error( 0, 'Invalid JSON in request body' );
				return new WP_REST_Response( $error, 400, $this->get_cors_headers( $request ) );
			}

			// Handle single message or batch (MCP spec allows both)
			$messages          = is_array( $body ) && isset( $body[0] ) ? $body : array( $body );
			$results           = array();
			$has_requests      = false;
			$has_notifications = false;

			foreach ( $messages as $message ) {
				// Validate JSON-RPC message format
				$validation = McpErrorFactory::validate_jsonrpc_message( $message );
				if ( true !== $validation ) {
					return new WP_REST_Response( $validation, 400, $this->get_cors_headers( $request ) );
				}

				// Check if this is a notification (no id) or request (has id)
				if ( isset( $message['method'] ) && ! isset( $message['id'] ) ) {
					$has_notifications = true;
					// Process notification but don't add to results
					$this->process_mcp_message( $message, $request );
				} elseif ( isset( $message['method'] ) && isset( $message['id'] ) ) {
					$has_requests = true;
					// Process request and add to results
					$results[] = $this->process_mcp_message( $message, $request );
				}
			}

			// If only notifications, return 202 Accepted with no body per MCP spec
			if ( $has_notifications && ! $has_requests ) {
				return new WP_REST_Response( null, 202, $this->get_cors_headers( $request ) );
			}

			// Return single result or batch for requests
			$response_body = count( $results ) === 1 ? $results[0] : $results;
			$headers       = array_merge(
				$this->get_cors_headers( $request ),
				array( 'Content-Type' => 'application/json' )
			);

			return new WP_REST_Response( $response_body, 200, $headers );
		} catch ( \Throwable $exception ) {
			// Log the error
			if ( $this->context->mcp_server->error_handler ) {
				$this->context->mcp_server->error_handler->log(
					'Unexpected error in handle_mcp_request',
					array( 'exception' => $exception->getMessage() )
				);
			}

			$error = McpErrorFactory::internal_error( 0, 'Handler error occurred' );
			return new WP_REST_Response( $error, 500, $this->get_cors_headers( $request ) );
		}
	}

	/**
	 * Process a single MCP message - unified for both proxy and direct clients
	 *
	 * @param array $message The MCP JSON-RPC message.
	 * @param mixed $request The request object.
	 *
	 * @return array
	 */
	private function process_mcp_message( array $message, $request ): array {
		$request_id = isset( $message['id'] ) ? (int) $message['id'] : 0;
		$method     = $message['method'];
		$params     = $message['params'] ?? array();

		// Handle initialize request specially (creates session for direct clients)
		if ( 'initialize' === $method ) {
			return $this->handle_initialize_request( $message, $request );
		}

		// For notifications (no id), we don't need session validation or responses
		if ( ! isset( $message['id'] ) ) {
			// Just log the notification and return empty array (won't be used)
			return array();
		}

		// For non-initialize requests, validate session
		$session_validation = $this->validate_session( $request );
		if ( true !== $session_validation ) {
			return array(
				'jsonrpc' => '2.0',
				'id'      => $request_id,
				'error'   => $session_validation['error'] ?? $session_validation,
			);
		}

		// Route the request
		$result = $this->context->request_router->route_request(
			$method,
			$params,
			$request_id,
			$this->get_transport_name()
		);

		// Format as JSON-RPC response
		if ( isset( $result['error'] ) ) {
			return array(
				'jsonrpc' => '2.0',
				'id'      => $request_id,
				'error'   => $result['error'],
			);
		}

		return array(
			'jsonrpc' => '2.0',
			'id'      => $request_id,
			'result'  => $result,
		);
	}

	/**
	 * Handle GET requests - listening for messages from server (SSE)
	 *
	 * @param mixed $request The request object.
	 *
	 * @return \WP_REST_Response
	 */
	private function handle_sse_request( $request ): WP_REST_Response {
		// Validate Accept header for SSE
		$accept = $request->get_header( 'accept' );
		if ( ! $accept || ! str_contains( $accept, 'text/event-stream' ) ) {
			return new WP_REST_Response(
				McpErrorFactory::invalid_request( 0, 'Accept header must include text/event-stream' ),
				406,
				$this->get_cors_headers( $request )
			);
		}

		// For now, return 405 as we don't support SSE streaming yet
		// TODO: Implement SSE streaming for server-initiated messages
		return new WP_REST_Response(
			McpErrorFactory::internal_error( 0, 'SSE streaming not yet implemented' ),
			405,
			$this->get_cors_headers( $request )
		);
	}

	/**
	 * Handle DELETE requests - session termination
	 *
	 * @param mixed $request The request object.
	 *
	 * @return \WP_REST_Response
	 */
	private function handle_session_termination( $request ): WP_REST_Response {
		$session_id = $request->get_header( 'Mcp-Session-Id' );

		if ( ! $session_id ) {
			return new WP_REST_Response(
				McpErrorFactory::invalid_request( 0, 'Missing Mcp-Session-Id header' ),
				400,
				$this->get_cors_headers( $request )
			);
		}

		// Terminate the session by deleting the transient
		delete_transient( "mcp_session_{$session_id}" );

		return new WP_REST_Response( null, 200, $this->get_cors_headers( $request ) );
	}




	/**
	 * Handle initialize request and create session
	 *
	 * @param array $message The initialize message.
	 * @param mixed $request The request object.
	 *
	 * @return array
	 */
	private function handle_initialize_request( array $message, $request ): array {
		$request_id = (int) $message['id'];
		$params     = $message['params'] ?? array();

		// Route the initialize request
		$result = $this->context->request_router->route_request(
			'initialize',
			$params,
			$request_id,
			$this->get_transport_name()
		);

		// If successful, create a session for all clients (unified session management)
		if ( ! isset( $result['error'] ) ) {
			// Check if client already has a session
			$existing_session = $request->get_header( 'Mcp-Session-Id' );

			if ( ! $existing_session ) {
				// Create new session for all clients (proxy and direct)
				$session_id = $this->create_session( $params['clientInfo'] ?? array() );

				// Add session header to response
				add_filter(
					'rest_post_dispatch',
					static function ( $response ) use ( $session_id ) {
						if ( $response instanceof WP_REST_Response ) {
							$response->header( 'Mcp-Session-Id', $session_id );
						}
						return $response;
					}
				);
			}
		}

		// Format as JSON-RPC response
		if ( isset( $result['error'] ) ) {
			return array(
				'jsonrpc' => '2.0',
				'id'      => $request_id,
				'error'   => $result['error'],
			);
		}

		return array(
			'jsonrpc' => '2.0',
			'id'      => $request_id,
			'result'  => $result,
		);
	}



	/**
	 * Validate session for all client requests (unified session management)
	 *
	 * @param mixed $request The request object.
	 *
	 * @return array|true Returns true if valid, error array if invalid.
	 */
	private function validate_session( $request ) {
		$session_id = $request->get_header( 'Mcp-Session-Id' );

		if ( ! $session_id ) {
			return McpErrorFactory::invalid_request( 0, 'Missing Mcp-Session-Id header' );
		}

		// All sessions are now managed by WordPress - check transients
		$session_data = get_transient( "mcp_session_{$session_id}" );

		if ( false === $session_data ) {
			return McpErrorFactory::invalid_request( 0, 'Invalid or expired session' );
		}

		// Session found - update last activity and refresh expiration
		$session_data['last_activity'] = time();
		set_transient( "mcp_session_{$session_id}", $session_data, self::SESSION_EXPIRATION );

		return true;
	}

	/**
	 * Create a new session using WordPress transients
	 *
	 * @param array $client_info The client information from initialize request.
	 *
	 * @return string The session ID.
	 */
	private function create_session( array $client_info ): string {
		$session_id = wp_generate_uuid4();

		$session_data = array(
			'created_at'    => time(),
			'last_activity' => time(),
			'client_info'   => $client_info,
		);

		// Store session as transient with automatic expiration
		set_transient( "mcp_session_{$session_id}", $session_data, self::SESSION_EXPIRATION );

		return $session_id;
	}

	/**
	 * Get CORS headers for the response
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request The request object.
	 *
	 * @return array
	 */
	private function get_cors_headers( $request ): array {
		return array(
			'Access-Control-Allow-Origin'  => $this->get_cors_origin( $request ),
			'Access-Control-Allow-Methods' => 'GET, POST, DELETE, OPTIONS',
			'Access-Control-Allow-Headers' => 'Content-Type, Accept, Mcp-Session-Id, MCP-Protocol-Version, Origin',
		);
	}

	/**
	 * Get appropriate CORS origin
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request The request object.
	 *
	 * @return string
	 */
	private function get_cors_origin( $request ): string {
		return '*';
	}

	/**
	 * Check if origin is allowed
	 *
	 * @param string $origin The origin to check.
	 *
	 * @return bool
	 */
	private function is_allowed_origin( string $origin ): bool {
		// TODO: Implement proper origin validation
		return true;
	}
}
