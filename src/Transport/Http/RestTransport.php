<?php
/**
 * MCP REST Transport for WordPress.
 * The REST transport requires the mcp-wordpress-remote proxy to be used with your MCP client.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Transport\Http;

use Throwable;
use WP\MCP\Core\McpServer;
use WP\MCP\Transport\Contracts\McpTransportInterface;
use WP\MCP\Transport\Infrastructure\McpTransportContext;
use WP\MCP\Transport\Infrastructure\McpTransportHelperTrait;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Class McpRestTransport
 *
 * Registers REST API routes for the Model Context Protocol (MCP) REST transport.
 * Uses WordPress-style responses for REST transport via mcp-wordpress-remote.
 */
class RestTransport implements McpTransportInterface {
	use McpTransportHelperTrait;

	/**
	 * The transport context.
	 *
	 * @var McpTransportContext
	 */
	private McpTransportContext $context;

	/**
	 * Initialize the class and register routes
	 *
	 * @param McpTransportContext $context The transport context.
	 */
	public function __construct( McpTransportContext $context ) {
		$this->context = $context;
		add_action( 'rest_api_init', array( $this, 'register_routes' ), 20001 );
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
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_request' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	/**
	 * Check if the user has permission to access the MCP API
	 *
	 * @return bool|WP_Error
	 */
	public function check_permission(): WP_Error|bool {
		// Use custom permission callback if provided
		if ( null !== $this->context->transport_permission_callback ) {
			try {
				return call_user_func( $this->context->transport_permission_callback );
			} catch ( Throwable $e ) {
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
	 * Handle all MCP requests
	 *
	 * @param mixed $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_request( mixed $request ): WP_Error|WP_REST_Response {
		$message = $request->get_json_params();

		$validation = $this->validate_rest_message( is_array( $message ) ? $message : array() );
		if ( true !== $validation ) {
			return $validation;
		}

		$method = $message['method'];
		$params = $message['params'] ?? $message; // backward compatibility with the old request format.

		// Route the request using the request router.
		$result = $this->context->request_router->route_request( $method, $params, 0, $this->get_transport_name() );

		// Check if the result contains an error.
		if ( isset( $result['error'] ) ) {
			return $this->format_error_response( $result );
		}

		return $this->format_success_response( $result );
	}

	/**
	 * Validate REST message shape and return either true or WP_Error.
	 *
	 * @param array $message Incoming message.
	 * @return WP_Error|true
	 */
	private function validate_rest_message( array $message ): WP_Error|bool {
		if ( empty( $message ) ) {
			return new WP_Error( 'invalid_request', 'Invalid request: Empty body', array( 'status' => 400 ) );
		}

		if ( ! isset( $message['method'] ) || ! is_string( $message['method'] ) || '' === trim( $message['method'] ) ) {
			return new WP_Error( 'invalid_request', 'Invalid request: Missing or invalid method', array( 'status' => 400 ) );
		}

		return true;
	}

	/**
	 * Format a successful response (WordPress format)
	 *
	 * @param array $result The result data.
	 * @param int   $request_id The request ID (unused in WordPress format).
	 *
	 * @return WP_REST_Response
	 */
	protected function format_success_response( array $result, int $request_id = 0 ): WP_REST_Response {
		return rest_ensure_response( $result );
	}

	/**
	 * Format an error response (WordPress format)
	 *
	 * @param array $error The error data.
	 * @param int   $request_id The request ID (unused in WordPress format).
	 *
	 * @return WP_Error
	 */
	protected function format_error_response( array $error, int $request_id = 0 ): WP_Error {
		// Convert legacy array error format to WP_Error
		$error_data = $error['error'] ?? $error;
		$code       = $error_data['code'] ?? 'unknown_error';
		$message    = $error_data['message'] ?? 'Unknown error';
		$data       = $error_data['data'] ?? array( 'status' => 500 );

		return new WP_Error( $code, $message, $data );
	}
}
