<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Fixtures;

use WP\MCP\Transport\Contracts\McpRestTransportInterface;
use WP\MCP\Transport\Infrastructure\McpTransportContext;
use WP\MCP\Transport\Infrastructure\McpTransportHelperTrait;

class DummyTransport implements McpRestTransportInterface {

	use McpTransportHelperTrait;

	private McpTransportContext $context;

	public function __construct(
		McpTransportContext $context
	) {
		$this->context = $context;
		// No route registration needed for tests
	}

	/**
	 * @param \WP_REST_Request<array<string, mixed>> $request
	 * @return true
	 */
	public function check_permission( \WP_REST_Request $request ) {
		return true;
	}

	/**
	 * @param \WP_REST_Request<array<string, mixed>> $request
	 * @return \WP_REST_Response
	 */
	public function handle_request( \WP_REST_Request $request ): \WP_REST_Response {
		// Simple test implementation
		return new \WP_REST_Response( array( 'success' => true ) );
	}

	public function register_routes(): void {
		// No-op for testing
	}

	// Expose route_request for testing (no more reflection needed!)
	public function test_route_request( string $method, array $params, int $request_id = 0 ): array {
		return $this->context->request_router->route_request(
			$method,
			$params,
			$request_id,
			$this->get_transport_name()
		);
	}
}
