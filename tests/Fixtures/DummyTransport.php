<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Fixtures;

use WP\MCP\Transport\Contracts\McpTransportInterface;
use WP\MCP\Transport\Infrastructure\McpTransportContext;
use WP\MCP\Transport\Infrastructure\McpTransportHelperTrait;
use WP_Error;

class DummyTransport implements McpTransportInterface
{
    use McpTransportHelperTrait;
    
    private McpTransportContext $context;
    
    public function __construct(
        McpTransportContext $context
    ) {
        $this->context = $context;
        // No route registration needed for tests
    }
    
    public function check_permission(): WP_Error|bool
    {
        return true;
    }
    
    public function handle_request( mixed $request): mixed
    {
        // Simple test implementation
        return ['success' => true];
    }
    
    public function register_routes(): void
    {
        // No-op for testing
    }
    
    // Expose route_request for testing (no more reflection needed!)
    public function test_route_request(string $method, array $params, int $request_id = 0): array
    {
        return $this->context->request_router->route_request(
            $method, 
            $params, 
            $request_id, 
            $this->get_transport_name()
        );
    }
}
