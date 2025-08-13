# Custom Transport Layers

This guide covers how to implement custom transport layers for the MCP Adapter. While the adapter includes
production-ready REST API and streaming transports, you may need custom protocols for specific requirements,
infrastructure, or integration patterns.

## Table of Contents

1. [When to Create Custom Transports](#when-to-create-custom-transports)
2. [Transport Architecture](#transport-architecture)
3. [Basic Custom Transport](#basic-custom-transport)
4. [Advanced Transport Features](#advanced-transport-features)
5. [Real-World Examples](#real-world-examples)
6. [Testing and Debugging](#testing-and-debugging)

## When to Create Custom Transports

> **💡 Start with [Transport Permissions](transport-permissions.md)**: For most authentication needs, use transport permission callbacks instead of creating custom transports. They're simpler, more maintainable, and provide the same flexibility.

### Common Use Cases

**Product-Specific Requirements**

- Use [Transport Permissions](transport-permissions.md)
- Product-specific routing patterns or URL structures
- Integration with existing API gateways or middleware

**Infrastructure Integration**

- Message queue systems (Redis, RabbitMQ, AWS SQS)
- WebSocket connections for real-time communication
- gRPC or other binary protocols for high-performance scenarios

**Security & Compliance**

- Request signing and verification
- Custom encryption or data masking
- Audit logging and compliance tracking
- Rate limiting and DDoS protection

**Performance Optimization**

- Connection pooling and persistent connections
- Custom caching strategies
- Compression and data optimization
- Load balancing and failover mechanisms

### Enterprise Example

Here's how an enterprise transport implementation might look:

```php
class EnterpriseRestTransport extends \WP\MCP\Transport\Http\RestTransport {
    // Custom routing for enterprise infrastructure
    // Integration with API gateways and proxies
    // Enterprise-specific authentication
    // Monitoring and logging integration
}
```

## Transport Architecture

### McpTransportInterface

All custom transports implement `McpTransportInterface`:

```php
use WP\MCP\Transport\Contracts\McpTransportInterface;
use WP\MCP\Transport\Infrastructure\McpTransportContext;

interface McpTransportInterface {
    public function __construct( McpTransportContext $context );
    public function check_permission(): WP_Error|bool;
    public function handle_request( mixed $request ): mixed;
    public function register_routes(): void;
}
```

### Key Responsibilities

1. **Dependency Injection**: Accept `McpTransportContext` with all required dependencies
2. **Route Registration**: Define how MCP endpoints are exposed via WordPress REST API
3. **Permission Checking**: Implement authentication and authorization logic (or use [Transport Permissions](transport-permissions.md) for simpler cases)
4. **Request Handling**: Process incoming MCP requests using the injected request router
5. **Response Formatting**: Structure transport-specific responses (REST vs JSON-RPC)

## Basic Custom Transport

Let's create a simple custom transport that adds API key authentication:

```php
<?php
use WP\MCP\Transport\Contracts\McpTransportInterface;
use WP\MCP\Transport\Infrastructure\McpTransportContext;
use WP\MCP\Transport\Infrastructure\McpTransportHelperTrait;

class ApiKeyTransport implements McpTransportInterface {
    use McpTransportHelperTrait;
    
    private McpTransportContext $context;
    
    public function __construct( McpTransportContext $context ) {
        $this->context = $context;
        add_action( 'rest_api_init', array( $this, 'register_routes' ), 20003 );
    }
    
    public function register_routes(): void {
        register_rest_route(
            $this->context->mcp_server->get_server_route_namespace(),
            $this->context->mcp_server->get_server_route() . '/api-key',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'handle_request' ),
                'permission_callback' => array( $this, 'check_permission' ),
            )
        );
    }
    
    public function check_permission(): WP_Error|bool {
        $request = rest_get_server()->get_request();
        $api_key = $request->get_header( 'X-MCP-API-Key' );
        
        if ( empty( $api_key ) ) {
            return new WP_Error( 'missing_api_key', 'API key required', array( 'status' => 401 ) );
        }
        
        // Simple validation - check against stored keys
        $valid_keys = get_option( 'mcp_api_keys', [] );
        if ( ! in_array( $api_key, $valid_keys, true ) ) {
            return new WP_Error( 'invalid_api_key', 'Invalid API key', array( 'status' => 403 ) );
        }
        
        return true;
    }
    
    public function handle_request( mixed $request ): WP_REST_Response|WP_Error {
        $body = $request->get_json_params();
        
        if ( empty( $body['method'] ) ) {
            return new WP_Error( 'missing_method', 'MCP method required', array( 'status' => 400 ) );
        }
        
        // Route through the request router
        $result = $this->context->request_router->route_request(
            $body['method'],
            $body['params'] ?? array(),
            $body['id'] ?? 0,
            'api-key'
        );
        
        return rest_ensure_response( $result );
    }
}
```

### Using the Custom Transport

```php
add_action( 'mcp_adapter_init', function( $adapter ) {
    $adapter->create_server(
        'api-key-server',
        'my-plugin',
        'secure-mcp',
        'Secure MCP Server',
        'MCP server with API key authentication',
        '1.0.0',
        [ ApiKeyTransport::class ], // Use custom transport
        \WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
        \WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class,
        [ 'my-plugin/secure-tool' ]
    );
});
```

## Advanced Transport Features

### Simple WebSocket Transport

For real-time communication:

```php
class WebSocketTransport implements McpTransportInterface {
    use McpTransportHelperTrait;
    
    private McpTransportContext $context;
    
    public function __construct( McpTransportContext $context ) {
        $this->context = $context;
        add_action( 'rest_api_init', array( $this, 'register_routes' ), 20004 );
    }
    
    public function register_routes(): void {
        register_rest_route(
            $this->context->mcp_server->get_server_route_namespace(),
            $this->context->mcp_server->get_server_route() . '/ws-info',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_websocket_info' ),
                'permission_callback' => array( $this, 'check_permission' ),
            )
        );
    }
    
    public function check_permission(): WP_Error|bool {
        return is_user_logged_in();
    }
    
    public function handle_request( mixed $request ): WP_REST_Response|WP_Error {
        return new WP_Error( 'websocket_only', 'Use WebSocket connection', array( 'status' => 400 ) );
    }
    
    public function get_websocket_info( WP_REST_Request $request ): WP_REST_Response {
        return rest_ensure_response([
            'websocket_url' => 'ws://localhost:8080',
            'protocol' => 'mcp-v1'
        ]);
    }
}
```

### Queue-Based Transport

For asynchronous processing:

```php
class QueueTransport implements McpTransportInterface {
    use McpTransportHelperTrait;
    
    private McpTransportContext $context;
    
    public function __construct( McpTransportContext $context ) {
        $this->context = $context;
        add_action( 'rest_api_init', array( $this, 'register_routes' ), 20005 );
    }
    
    public function register_routes(): void {
        register_rest_route(
            $this->context->mcp_server->get_server_route_namespace(),
            $this->context->mcp_server->get_server_route() . '/queue',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'handle_request' ),
                'permission_callback' => array( $this, 'check_permission' ),
            )
        );
    }
    
    public function check_permission(): WP_Error|bool {
        return is_user_logged_in();
    }
    
    public function handle_request( mixed $request ): WP_REST_Response|WP_Error {
        $body = $request->get_json_params();
        
        // Queue the request for later processing
        $job_id = wp_generate_uuid4();
        wp_schedule_single_event( time() + 10, 'mcp_process_queue_job', [ $job_id, $body ] );
        
        return rest_ensure_response([
            'job_id' => $job_id,
            'status' => 'queued'
        ]);
    }
}
```

## Testing and Debugging

### Simple Testing

Test your custom transport with basic checks:

```php
// Test route registration
add_action( 'rest_api_init', function() {
    $routes = rest_get_server()->get_routes();
    if ( isset( $routes['/my-plugin/v1/mcp/api-key'] ) ) {
        error_log( 'Custom transport route registered successfully' );
    }
});

// Test authentication
$request = new WP_REST_Request( 'POST', '/my-plugin/v1/mcp/api-key' );
$request->set_header( 'X-MCP-API-Key', 'test-key' );
$transport = new ApiKeyTransport( $context );
$result = $transport->check_permission();
```

### Debug Logging

Add simple logging to your transport:

```php
class DebugTransport implements McpTransportInterface {
    use McpTransportHelperTrait;
    
    public function handle_request( mixed $request ): WP_REST_Response|WP_Error {
        // Log incoming requests
        if ( WP_DEBUG ) {
            error_log( '[MCP Debug] Request: ' . wp_json_encode( $request->get_json_params() ) );
        }
        
        $result = $this->context->request_router->route_request( /* ... */ );
        
        // Log responses
        if ( WP_DEBUG ) {
            error_log( '[MCP Debug] Response: ' . wp_json_encode( $result ) );
        }
        
        return rest_ensure_response( $result );
    }
}
```

## Production Considerations

### Security Checklist

- Validate all input parameters
- Implement proper authentication
- Sanitize error messages
- Use HTTPS in production
- Rate limit requests if needed

### Performance Tips

- Cache responses when appropriate
- Use async processing for slow operations
- Monitor response times
- Log errors for debugging

### Basic Monitoring

```php
// Simple request logging
add_action( 'mcp_transport_request', function( $transport_name, $method ) {
    error_log( "[MCP] {$transport_name} handled {$method}" );
});
```

Custom transports provide flexibility for integrating MCP with your specific infrastructure needs.

## Custom Transports vs Transport Permissions

### When to Use Transport Permissions (Recommended)

Use [Transport Permissions](transport-permissions.md) for:

- ✅ **Authentication logic**: Admin-only access, role checks, API keys
- ✅ **Rate limiting**: Request throttling per user or globally  
- ✅ **Time-based access**: Business hours, scheduled maintenance
- ✅ **Simple custom logic**: Most authentication needs

**Benefits**: Simpler, easier to test, better error handling, no custom classes needed.

### When to Use Custom Transports

Create custom transports only for:

- 🔧 **Protocol changes**: Different message formats, non-HTTP protocols
- 🔧 **Routing changes**: Custom URL patterns, middleware integration
- 🔧 **Infrastructure integration**: Message queues, WebSockets, gRPC
- 🔧 **Advanced features**: Request signing, compression, connection pooling

**Consider**: Custom transports require more maintenance and testing.

### Migration Path

If you have custom transports only for authentication:

```php
// ❌ Custom transport just for auth
class AdminTransport extends RestTransport {
    public function check_permission(): bool {
        return current_user_can('manage_options');
    }
}

// ✅ Use transport permissions instead
McpAdapter::instance()->create_server(
    'server-id', 'namespace', 'route', 'name', 'desc', '1.0.0',
    [RestTransport::class], // Standard transport
    null, null, ['tools'], [], [],
    function(): bool { return current_user_can('manage_options'); } // Permission callback
);
```

## Next Steps

- **Start with [Transport Permissions](transport-permissions.md)** for authentication needs
- **Review [Error Handling](error-handling.md)** for advanced error management
- **Explore [Architecture Overview](../architecture/overview.md)** to understand system design
- **Check [Creating Abilities](creating-abilities.md)** for production patterns
