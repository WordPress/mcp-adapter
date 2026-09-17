# Custom Transport Layers

This guide covers how to implement custom transport layers for the MCP Adapter when the built-in `HttpTransport` doesn't meet your specific needs.

## Built-in Transports

- ✅ **`HttpTransport`** - Recommended (implements MCP 2025-11-25 specification)
- ✅ **`STDIO Transport`** - Available via WP-CLI commands

## When to Create Custom Transports

> **💡 Consider [Transport Permissions](transport-permissions.md) first**: For authentication needs, use transport permission callbacks instead of custom transports.

Create custom transports for:

- **Custom routing patterns** or URL structures
- **Message queue integration** (Redis, RabbitMQ, AWS SQS)
- **Request signing** and verification
- **Custom encryption** or data masking
- **Specialized protocols** beyond HTTP/STDIO

## Transport Interfaces

Custom transports implement one of two interfaces:

### McpTransportInterface (Base)
```php
interface McpTransportInterface {
    public function __construct( McpTransportContext $context );
    public function register_routes(): void;
}
```

### McpRestTransportInterface (REST-specific)
```php
interface McpRestTransportInterface extends McpTransportInterface {
    public function check_permission( WP_REST_Request $request );
    public function handle_request( WP_REST_Request $request ): \WP_REST_Response;
}
```

### Helper Trait
Use `McpTransportHelperTrait` for common functionality:
```php
use WP\MCP\Transport\Infrastructure\McpTransportHelperTrait;

class MyTransport implements McpRestTransportInterface {
    use McpTransportHelperTrait;
    
    // Provides get_transport_name() method
}
```

## Creating Custom Transports

### Basic Example: API Key Transport

```php
<?php
use WP\MCP\Transport\Contracts\McpRestTransportInterface;
use WP\MCP\Transport\Infrastructure\McpTransportContext;
use WP\MCP\Transport\Infrastructure\McpTransportHelperTrait;

class ApiKeyTransport implements McpRestTransportInterface {
    use McpTransportHelperTrait;
    
    private McpTransportContext $context;
    
    public function __construct( McpTransportContext $context ) {
        $this->context = $context;
        $this->register_routes();
    }
    
    public function register_routes(): void {
        $server = $this->context->mcp_server;
        
        register_rest_route(
            $server->get_server_route_namespace(),
            $server->get_server_route(),
            [
                'methods' => ['POST', 'GET', 'DELETE'],
                'callback' => [$this, 'handle_request'],
                'permission_callback' => [$this, 'check_permission'],
            ]
        );
    }
    
    public function check_permission( \WP_REST_Request $request ) {
        $api_key = sanitize_text_field( $request->get_header( 'X-API-Key' ) );

        if ( empty( $api_key ) ) {
            return false;
        }

        // Validate against stored keys
        $valid_keys = get_option( 'mcp_api_keys', array() );
        return in_array( $api_key, $valid_keys, true );
    }
    
    public function handle_request( \WP_REST_Request $request ): \WP_REST_Response {
        $body = $request->get_json_params();
        
        if ( empty( $body['method'] ) ) {
            return new \WP_REST_Response( 
                ['error' => 'MCP method required'], 
                400 
            );
        }
        
        // Route through the request router
        $result = $this->context->request_router->route_request(
            $body['method'],
            $body['params'] ?? [],
            $body['id'] ?? 0,
            $this->get_transport_name()
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
        array( ApiKeyTransport::class ), // Use custom transport
        \WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
        null,                            // Observability handler (null = default)
        array( 'my-plugin/secure-tool' ) // Tools
    );
});
```

## Origin Validation

The built-in `HttpTransport` validates the `Origin` header on every request routed through `HttpRequestHandler`, as required by the MCP Streamable HTTP transport specification to prevent DNS rebinding attacks. A missing or empty `Origin` is accepted, since non-browser clients (CLI tools, server-to-server integrations) do not send one. A present `Origin` must match the site's `home_url()`, `site_url()`, or `rest_url()` (compared by scheme, host, and effective port only), or it is rejected with HTTP 403 before the request is dispatched.

Use the `mcp_adapter_allowed_http_origins` filter to allow additional exact origins, for example when the site is embedded behind a different public domain:

```php
add_filter( 'mcp_adapter_allowed_http_origins', function ( array $origins, \WP\MCP\Core\McpServer $server ) {
    $origins[] = 'https://app.example.com';

    return $origins;
}, 10, 2 );
```

The filtered value must be a list of strings. Returning anything else is treated as invalid and causes every non-empty `Origin` to be rejected (fail closed).

Custom transports that bypass `HttpRequestHandler` (for example by talking to `request_router` directly, as in the example above) do not get this protection automatically and should implement their own `Origin` validation if they accept browser-originated requests.

## Transport Permissions vs Custom Transports

### Use Transport Permissions For:
- ✅ **Authentication logic** (role checks, API keys)
- ✅ **User-based permissions** (capability validation)
- ✅ **Time-based access** (business hours)
- ✅ **Most authorization needs**

See [Transport Permissions](transport-permissions.md) for simpler authentication solutions.

### Use Custom Transports For:
- ✅ **Custom routing patterns**
- ✅ **Message queue integration**
- ✅ **Request signing/encryption**
- ✅ **Specialized protocols**

## Implementation Notes

### Required Methods
- `__construct()`: Accept `McpTransportContext` and call `register_routes()`
- `register_routes()`: Register WordPress REST API endpoints
- `check_permission()`: Validate request access (REST transports only)
- `handle_request()`: Process MCP requests (REST transports only)

### Helper Trait Benefits
- `get_transport_name()`: Normalized transport name for metrics
- Consistent naming conventions
- Shared utility methods

### Request Routing
All transports use the injected `request_router` to process MCP methods:
```php
$result = $this->context->request_router->route_request(
    $method,
    $params,
    $request_id,
    $this->get_transport_name()
);
```

## Next Steps

- **[Transport Permissions](transport-permissions.md)** - Simpler authentication approach
- **[Error Handling](error-handling.md)** - Custom error management
- **[Architecture Overview](../architecture/overview.md)** - System design
