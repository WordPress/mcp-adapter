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

use WP\MCP\Transport\Contracts\McpRestTransportInterface;
use WP\MCP\Transport\Infrastructure\HttpRequestContext;
use WP\MCP\Transport\Infrastructure\HttpRequestHandler;
use WP\MCP\Transport\Infrastructure\McpTransportContext;
use WP\MCP\Transport\Infrastructure\McpTransportHelperTrait;

/**
 * MCP HTTP Transport - Unified transport for both proxy and direct clients
 *
 * Implements MCP 2025-06-18 Streamable HTTP specification
 */
class HttpTransport implements McpRestTransportInterface {
	use McpTransportHelperTrait;

	/**
	 * The HTTP request handler.
	 *
	 * @var \WP\MCP\Transport\Infrastructure\HttpRequestHandler
	 */
	protected HttpRequestHandler $request_handler;

	/**
	 * Initialize the class and register routes
	 *
	 * @param \WP\MCP\Transport\Infrastructure\McpTransportContext $transport_context The transport context.
	 */
	public function __construct( McpTransportContext $transport_context ) {
		$this->request_handler = new HttpRequestHandler( $transport_context );
		add_action( 'rest_api_init', array( $this, 'register_routes' ), 16 );
	}

	/**
	 * Register MCP HTTP routes
	 */
	public function register_routes(): void {
		// Get server info from request handler's transport context
		$server = $this->request_handler->transport_context->mcp_server;

		// Single endpoint for MCP communication (POST, GET for SSE, DELETE for session termination)
		register_rest_route(
			$server->get_server_route_namespace(),
			$server->get_server_route(),
			array(
				'methods'             => array( 'POST', 'GET', 'DELETE' ),
				'callback'            => array( $this, 'handle_request' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	/**
	 * Check if the user has permission to access the MCP API
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request The request object.
	 *
	 * @return bool True if the user has permission, false otherwise.
	 */
	public function check_permission( \WP_REST_Request $request ) {
		$context = new HttpRequestContext( $request );

		// Check permission using callback or default
		$transport_context = $this->request_handler->transport_context;

		if ( null !== $transport_context->transport_permission_callback ) {
			try {
				$result = call_user_func( $transport_context->transport_permission_callback, $context->request );

				// Handle WP_Error returns
				if ( ! is_wp_error( $result ) ) {
					// Return boolean result directly
					return $result;
				}

				// Log the error and fall back to default permission
				$this->request_handler->transport_context->error_handler->log(
					'Permission callback returned WP_Error: ' . $result->get_error_message(),
					array( 'HttpTransport::check_permission' )
				);
				// Fall through to default permission check
			} catch ( \Throwable $e ) {
				// Log the error using the error handler, and fall back to default permission
				$this->request_handler->transport_context->error_handler->log( 'Error in transport permission callback: ' . $e->getMessage(), array( 'HttpTransport::check_permission' ) );
			}
		}
		$user_capability = apply_filters( 'mcp_adapter_default_transport_permission_user_capability', 'read', $context );

		// Validate that the filtered capability is a non-empty string
		if ( ! is_string( $user_capability ) || empty( $user_capability ) ) {
			$user_capability = 'read';
		}

		$user_has_capability = current_user_can( $user_capability ); // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Capability is filtered and defaults to 'read'

		if ( ! $user_has_capability ) {
			$user_id = get_current_user_id();
			$this->request_handler->transport_context->error_handler->log(
				sprintf( 'Permission denied for MCP API access. User ID %d does not have capability "%s"', $user_id, $user_capability ),
				array( 'HttpTransport::check_permission' )
			);
		}

		return $user_has_capability;
	}

	/**
	 * Handle HTTP requests according to MCP 2025-06-18 specification
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request The request object.
	 *
	 * @return \WP_REST_Response
	 */
	public function handle_request( \WP_REST_Request $request ): \WP_REST_Response {
		$context = new HttpRequestContext( $request );

		return $this->request_handler->handle_request( $context );
	}
}
