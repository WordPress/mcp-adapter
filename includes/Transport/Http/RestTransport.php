<?php
/**
 * MCP REST Transport for WordPress.
 * The REST transport requires the mcp-wordpress-remote proxy to be used with your MCP client.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Transport\Http;

use WP\MCP\Transport\Contracts\McpRestTransportInterface;
use WP\MCP\Transport\Infrastructure\TransportContext;
use WP\MCP\Transport\Infrastructure\TransportHelperTrait;

/**
 * Class McpRestTransport
 *
 * Registers REST API routes for the Model Context Protocol (MCP) REST transport.
 * Uses WordPress-style responses for REST transport via mcp-wordpress-remote.
 *
 * @deprecated Use HttpTransport instead. This class is deprecated and will be removed in a future version.
 */
class RestTransport implements McpRestTransportInterface {
	use TransportHelperTrait;

	/**
	 * The transport context.
	 *
	 * @var \WP\MCP\Transport\Infrastructure\TransportContext
	 */
	private TransportContext $context;

	/**
	 * Initialize the class and register routes
	 *
	 * @param \WP\MCP\Transport\Infrastructure\TransportContext $context The transport context.
	 */
	public function __construct( TransportContext $context ) {
		_deprecated_class( self::class, '', '\WP\MCP\Transport\HttpTransport' );

		$this->context = $context;

		// Register routes directly since we're already in the correct context
		// For REST API: constructor runs during mcp_adapter_init which runs during rest_api_init
		// For WP-CLI: routes not needed (uses STDIO), registration may fail silently
		$this->register_routes();
	}

	/**
	 * Register all MCP proxy routes
	 */
	public function register_routes(): void {
		// Single endpoint for all MCP operations.
		register_rest_route(
			$this->context->mcp_server->get_server_route_namespace(),
			$this->context->mcp_server->get_server_route(),
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_request' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	/**
	 * Check if the user has permission to access the MCP API
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request The request object.
	 * @return bool|\WP_Error
	 */
	public function check_permission( \WP_REST_Request $request ) {
		// Use custom permission callback if provided
		if ( null !== $this->context->transport_permission_callback ) {
			try {
				return call_user_func( $this->context->transport_permission_callback );
			} catch ( \Throwable $e ) {
				// Log error and fall back to default
				$this->context->mcp_server->error_handler->log(
					'Transport permission callback failed',
					array(
						'transport' => static::class,
						'server_id' => $this->context->mcp_server->get_server_id(),
						'error'     => $e->getMessage(),
					)
				);

				// Fall back to secure default
				return is_user_logged_in();
			}
		}

		// Secure default: require logged-in user
		return is_user_logged_in();
	}

	/**
	 * Handle all MCP requests
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request The request.
	 *
	 * @return \WP_REST_Response
	 */
	public function handle_request( \WP_REST_Request $request ): \WP_REST_Response {
		$message = $request->get_json_params();

		$validation = $this->validate_rest_message( is_array( $message ) ? $message : array() );
		if ( true !== $validation ) {
			return new \WP_REST_Response( $validation->get_error_data(), $validation->get_error_data()['status'] ?? 400 );
		}

		$method = $message['method'];
		$params = $message['params'] ?? $message; // backward compatibility with the old request format.

		// Route the request using the request router.
		$result = $this->context->request_router->route_request( $method, $params, 0, $this->get_transport_name() );

		// Check if the result contains an error.
		if ( isset( $result['error'] ) ) {
			$error = $this->format_error_response( $result );
			return new \WP_REST_Response( $error->get_error_data(), $error->get_error_data()['status'] ?? 500 );
		}

		return $this->format_success_response( $result );
	}

	/**
	 * Validate REST message shape and return either true or WP_Error.
	 *
	 * @param array $message Incoming message.
	 * @return \WP_Error|true
	 */
	private function validate_rest_message( array $message ) {
		if ( empty( $message ) ) {
			return new \WP_Error( 'invalid_request', 'Invalid request: Empty body', array( 'status' => 400 ) );
		}

		if ( ! isset( $message['method'] ) || ! is_string( $message['method'] ) || '' === trim( $message['method'] ) ) {
			return new \WP_Error( 'invalid_request', 'Invalid request: Missing or invalid method', array( 'status' => 400 ) );
		}

		return true;
	}

	/**
	 * Format a successful response (WordPress format)
	 *
	 * @param array $result The result data.
	 * @param int   $request_id The request ID (unused in WordPress format).
	 *
	 * @return \WP_REST_Response
	 */
	protected function format_success_response( array $result, int $request_id = 0 ): \WP_REST_Response {
		return rest_ensure_response( $result );
	}

	/**
	 * Format an error response (WordPress format)
	 *
	 * @param array $error The error data.
	 * @param int   $request_id The request ID (unused in WordPress format).
	 *
	 * @return \WP_Error
	 */
	protected function format_error_response( array $error, int $request_id = 0 ): \WP_Error {
		// Convert legacy array error format to WP_Error
		$error_data = $error['error'] ?? $error;
		$code       = $error_data['code'] ?? 'unknown_error';
		$message    = $error_data['message'] ?? 'Unknown error';
		$data       = $error_data['data'] ?? array( 'status' => 500 );

		return new \WP_Error( $code, $message, $data );
	}
}
