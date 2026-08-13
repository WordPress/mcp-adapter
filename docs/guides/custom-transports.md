# Custom transports

Use a custom transport when the built-in `HttpTransport` and STDIO bridge do not fit the delivery mechanism. The injected `RequestRouter` still owns MCP request validation, method dispatch, and response serialization.

The built-in transports support both exact revisions described in [Protocol versions](protocol-versions.md):

- MCP 2025-11-25 uses `initialize` and a negotiated session.
- MCP 2026-07-28 is stateless and selects the revision in every request.

For authentication or authorization changes on the existing HTTP route, use [transport permissions](transport-permissions.md) instead of a custom transport.

## Transport interfaces

All transports implement `McpTransportInterface`:

```php
interface McpTransportInterface {
    public function __construct( McpTransportContext $context );
    public function register_routes(): void;
}
```

REST transports also implement `McpRestTransportInterface`:

```php
interface McpRestTransportInterface extends McpTransportInterface {
    public function check_permission( WP_REST_Request $request );
    public function handle_request( WP_REST_Request $request ): \WP_REST_Response;
}
```

`McpTransportHelperTrait` provides the normalized transport name used for observability:

```php
use WP\MCP\Transport\Infrastructure\McpTransportHelperTrait;

class MyTransport implements McpRestTransportInterface {
    use McpTransportHelperTrait;
}
```

## Route a request

Call `RequestRouter::route_request()` with the MCP method, params, request ID, and transport name. Existing four- and five-argument calls remain compatible and default to the legacy revision when no other selector exists.

When the transport knows the revision, pass it as the optional sixth argument:

```php
$result = $this->context->request_router->route_request(
    $method,
    $params,
    $request_id,
    $this->get_transport_name(),
    null,              // HttpRequestContext, when available.
    $protocol_version  // "2025-11-25", "2026-07-28", or null.
);
```

The router also reads `params._meta["io.modelcontextprotocol/protocolVersion"]` for modern requests and `params.protocolVersion` for `initialize`. An explicit version does not replace transport-level lifecycle enforcement. A custom transport is responsible for:

- validating the top-level JSON-RPC envelope before routing;
- suppressing responses to notifications and client responses;
- retaining the negotiated `2025-11-25` version and any session identity between legacy requests;
- requiring and comparing the relevant protocol headers when its delivery mechanism uses them;
- keeping MCP 2026-07-28 requests independent and passing their exact declared version;
- authenticating the caller before routing; and
- wrapping the router's bare result or `error` array in the transport's JSON-RPC envelope.

## REST example

For a custom REST route, extend the built-in `HttpTransport` so JSON-RPC envelopes, notifications, batches, lossless JSON decoding, protocol headers, and legacy sessions keep the same behavior as the default route. Customize authentication with the server's transport permission callback.

```php
<?php
use WP\MCP\Transport\HttpTransport;

class CustomRouteTransport extends HttpTransport {

    public function register_routes(): void {
        $server = $this->request_handler->get_transport_context()->mcp_server;

        register_rest_route(
            $server->get_server_route_namespace(),
            '/custom/' . ltrim( $server->get_server_route(), '/' ),
            array(
                'methods'             => array( 'POST', 'GET', 'DELETE' ),
                'callback'            => array( $this, 'handle_request' ),
                'permission_callback' => array( $this, 'check_permission' ),
            )
        );
    }
}
```

The inherited request path keeps nested JSON objects as `stdClass` and JSON lists as PHP arrays until exact schema validation. A transport that implements another delivery mechanism should use `JsonRpcRequestDecoder` and `JsonRpcRequestParams` for the same lossless boundary. Do not replace this boundary with `WP_REST_Request::get_json_params()` when accepting raw JSON.

Register the transport when creating a server:

```php
add_action( 'mcp_adapter_init', function( $adapter ) {
    $adapter->create_server(
        'custom-route-server',
        'my-plugin',
        'secure-mcp',
        'Custom Route MCP Server',
        'MCP server exposed on a custom REST route',
        '1.0.0',
        array( CustomRouteTransport::class ),
        \WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
        null,
        array( 'my-plugin/secure-tool' ),
        array(),
        array(),
        static function(): bool {
            return current_user_can( 'manage_options' );
        }
    );
} );
```

## When a custom transport is appropriate

Use transport permissions for role checks, API keys on the built-in route, user capabilities, and other authorization rules.

Use a custom transport for:

- custom routing or URL structures;
- message queues such as Redis, RabbitMQ, or Amazon SQS;
- request signing, encryption, or data masking; or
- delivery mechanisms other than HTTP and STDIO.

## Next steps

- [Protocol versions](protocol-versions.md) — implement the exact lifecycle for each supported revision
- [Transport permissions](transport-permissions.md) — customize access to built-in transports
- [Error handling](error-handling.md) — integrate custom error management
- [Architecture overview](../architecture/overview.md) — understand request routing and schema serialization
