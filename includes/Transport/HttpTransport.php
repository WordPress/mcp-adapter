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
use WP\MCP\Transport\Infrastructure\AuthenticationValidator;
use WP\MCP\Transport\Infrastructure\HttpRequestContext;
use WP\MCP\Transport\Infrastructure\HttpRequestHandler;
use WP\MCP\Transport\Infrastructure\TransportContext;
use WP\MCP\Transport\Infrastructure\TransportHelperTrait;

/**
 * MCP HTTP Transport - Unified transport for both proxy and direct clients
 *
 * Implements MCP 2025-06-18 Streamable HTTP specification
 */
class HttpTransport implements McpRestTransportInterface {
	use TransportHelperTrait;

	/**
	 * The HTTP request handler.
	 *
	 * @var \WP\MCP\Transport\Infrastructure\HttpRequestHandler
	 */
	private HttpRequestHandler $request_handler;

	/**
	 * Initialize the class and register routes
	 *
	 * @param \WP\MCP\Transport\Infrastructure\TransportContext $transport_context The transport context.
	 */
	public function __construct( TransportContext $transport_context ) {
		$this->request_handler = new HttpRequestHandler( $transport_context );

		// Register routes directly since we're already in the correct context
		// For REST API: constructor runs during mcp_adapter_init which runs during rest_api_init
		// For WP-CLI: routes not needed (uses STDIO), registration may fail silently
		$this->register_routes();
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
	 * @return bool|\WP_Error
	 */
	public function check_permission( \WP_REST_Request $request ) {
		$context = new HttpRequestContext( $request );

		// Check permission using callback or default
		$transport_context = $this->request_handler->transport_context;
		return AuthenticationValidator::check_permission(
			$context,
			$transport_context->transport_permission_callback
		);
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
