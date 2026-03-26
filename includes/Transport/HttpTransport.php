<?php
/**
 * MCP HTTP Transport for WordPress (MCP 2025-11-25 baseline)
 *
 * This transport implements the MCP HTTP transport surface used by this plugin.
 * It can work both with and without the mcp-wordpress-remote proxy.
 *
 * Note: SSE (GET streaming) is not yet implemented; GET currently returns 405.
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
 * Implements the MCP 2025-11-25 HTTP transport shape used by this adapter (POST + sessions).
 *
 * Note: SSE (GET streaming) is not yet implemented; GET currently returns 405.
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

		// Single endpoint for MCP communication (POST, GET reserved for SSE, DELETE for session termination).
		// Do not remove GET: it is part of the MCP HTTP transport shape and will be implemented (SSE) in a future iteration.
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
					// Cast to bool to match return type while preserving truthy/falsy semantics.
					return (bool) $result;
				}

				// Log the error and deny access (fail-closed)
				$this->request_handler->transport_context->error_handler->log(
					'Permission callback returned WP_Error: ' . $result->get_error_message(),
					array( 'HttpTransport::check_permission' )
				);

				return false;
			} catch ( \Throwable $e ) {
				// Log the error and deny access (fail-closed)
				$this->request_handler->transport_context->error_handler->log( 'Error in transport permission callback: ' . $e->getMessage(), array( 'HttpTransport::check_permission' ) );

				return false;
			}
		}

		/**
		 * Filters the default user capability required for MCP transport access.
		 *
		 * This filter is only applied when no custom transport permission callback
		 * is provided. The capability is checked using current_user_can().
		 *
		 * @since 0.3.0
		 *
		 * @param string                                        $capability The required capability. Default 'read'.
		 * @param \WP\MCP\Transport\Infrastructure\HttpRequestContext $context    The HTTP request context.
		 */
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
	 * Handle HTTP requests according to MCP 2025-11-25 specification
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
