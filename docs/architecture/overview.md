# Architecture overview

This document explains how the MCP Adapter transforms WordPress abilities into MCP components and handles requests from AI agents.

## System architecture

The MCP Adapter uses a two-layer architecture that separates protocol concerns from WordPress integration:

### Schema layer (protocol DTOs)

The Schema Layer is provided by the `php-mcp-schema` package (`WP\McpSchema\` namespace). It contains protocol-only data transfer objects that are safe to expose to MCP clients.

**Key DTOs:**

| Category | Classes |
|----------|---------|
| Component definitions | `Tool`, `Resource`, `Prompt`, `PromptArgument`, `ToolAnnotations`, `Annotations` |
| Result types | `ListToolsResult`, `CallToolResult`, `ListResourcesResult`, `ReadResourceResult`, `ListPromptsResult`, `GetPromptResult` |
| Content blocks | `TextContent`, `ImageContent`, `AudioContent`, `EmbeddedResource` |

All DTOs extend `AbstractDataTransferObject`, which provides `toArray()` and `fromArray()` methods for serialization. These types carry no execution logic and no adapter-internal metadata.

### Adapter layer (WordPress integration)

The Adapter Layer wraps each protocol DTO with execution wiring and WordPress-specific metadata. Domain models `McpTool`, `McpResource`, and `McpPrompt` each implement the `McpComponentInterface` contract:

```php
interface McpComponentInterface {
    public function get_protocol_dto(): AbstractDataTransferObject;
    public function execute( $arguments );
    public function check_permission( $arguments );
    public function get_adapter_meta(): array;
    public function get_observability_context(): array;
}
```

This separation ensures that:

- **Protocol DTOs** contain only fields defined by the MCP specification and are serialized directly into responses.
- **Adapter metadata** (ability references, schema transformation flags, permission callbacks) stays internal and is never exposed to MCP clients.
- **Observability context** provides structured tags for logging and metrics without polluting DTO `_meta`.

`McpComponentInterface` is an internal contract (`@internal`). It is not intended for third-party implementation.

### Supporting layers

The remaining layers wire the Schema and Adapter layers together:

- **Core:** `McpAdapter` (singleton registry), `McpServer`, `McpComponentRegistry`, `McpTransportFactory`
- **Handlers:** `InitializeHandler`, `ToolsHandler`, `ResourcesHandler`, `PromptsHandler`, `SystemHandler`
- **Transport:** `HttpTransport`, STDIO transport, `RequestRouter`
- **Infrastructure:** Error handling (`McpErrorHandlerInterface`), Observability (`McpObservabilityHandlerInterface`)

## Core components

### McpAdapter (singleton registry)
- **Purpose**: Central registry managing multiple MCP servers
- **Key Methods**: `create_server()`, `get_server()`, `get_servers()`, `instance()`
- **Initialization**: Hooks into `rest_api_init` and fires `mcp_adapter_init` action

### McpServer (server instance)
- **Purpose**: Individual MCP server with specific configuration
- **Components**: Uses `McpComponentRegistry` to manage `McpComponentInterface` instances
- **Typed access**: `get_tools()`, `get_resources()`, `get_prompts()` return component collections
- **Dependencies**: Error handler, observability handler, transport permission callback

### McpComponentRegistry
- **Purpose**: Stores and retrieves `McpComponentInterface` instances
- **Registration**: `register_tools()`, `register_resources()`, `register_prompts()` accept both ability names and `McpComponentInterface` instances
- **Name sanitization**: Uses `McpNameSanitizer` to normalize tool and prompt names
- **Validation**: Validates components with `McpValidator` when validation is enabled

### McpTransportFactory
- **Purpose**: Creates transport instances with dependency injection
- **Context Creation**: Builds `McpTransportContext` with all required handlers
- **Validation**: Ensures transport classes implement `McpTransportInterface`

### RequestRouter
- **Purpose**: Routes MCP method calls to handlers that return schema DTOs
- **DTO serialization boundary**: Converts `AbstractDataTransferObject` results to arrays via `toArray()` and `JSONRPCErrorResponse` results to error arrays
- **Observability**: Extracts per-component context from `McpComponentInterface::get_observability_context()` for request tagging

## Request flow

```
AI Agent --> Transport --> RequestRouter --> Handler --> McpComponentInterface --> Schema DTO --> Response
```

### Detailed flow
1. **Transport** receives MCP request and authenticates
2. **RequestRouter** maps method to appropriate handler
3. **Handler** finds the `McpComponentInterface` component, validates input, and invokes execution
4. **Component** delegates to a WordPress ability or direct callable, returning a result
5. **Handler** wraps the result in a schema DTO (e.g., `CallToolResult`)
6. **RequestRouter** calls `toArray()` on the DTO at the serialization boundary
7. **Transport** wraps the array in a JSON-RPC envelope and returns it

### Method routing

The `RequestRouter` maps MCP methods to handlers. All handlers return schema DTOs:

| Method | Handler | Return Type |
|--------|---------|-------------|
| `initialize` | `InitializeHandler::handle()` | `InitializeResult` |
| `tools/list` | `ToolsHandler::list_tools()` | `ListToolsResult` |
| `tools/call` | `ToolsHandler::call_tool()` | `CallToolResult` or `JSONRPCErrorResponse` |
| `resources/list` | `ResourcesHandler::list_resources()` | `ListResourcesResult` |
| `resources/read` | `ResourcesHandler::read_resource()` | `ReadResourceResult` or `JSONRPCErrorResponse` |
| `prompts/list` | `PromptsHandler::list_prompts()` | `ListPromptsResult` |
| `prompts/get` | `PromptsHandler::get_prompt()` | `GetPromptResult` or `JSONRPCErrorResponse` |
| `ping` | `SystemHandler::ping()` | `Result` |

Protocol-level errors (tool not found, missing parameters) return `JSONRPCErrorResponse`. Execution-level errors (permission denied, runtime failure) return the appropriate result DTO with `isError: true`.

## Component creation

### From WordPress ability

WordPress abilities are converted to MCP components using factory methods on each domain model:

```php
// Tool from ability
$tool = McpTool::fromAbility( $ability );  // Returns McpTool|WP_Error

// Resource from ability
$resource = McpResource::fromAbility( $ability );  // Returns McpResource|WP_Error

// Prompt from ability
$prompt = McpPrompt::fromAbility( $ability );  // Returns McpPrompt|WP_Error
```

### From array configuration

Components can also be created directly without a WordPress ability:

```php
$tool = McpTool::fromArray( [
    'name'        => 'my-tool',
    'title'       => 'My Tool',
    'description' => 'Does something useful',
    'inputSchema' => [ 'type' => 'object', 'properties' => [ ... ] ],
    'handler'     => fn( $args ) => [ 'result' => 'done' ],
    'permission'  => fn() => current_user_can( 'edit_posts' ),
    'annotations' => [ 'readOnlyHint' => true ],
] );
```

### Protocol DTO access

Each component exposes its clean protocol DTO for serialization:

```php
$dto = $tool->get_protocol_dto();  // Returns WP\McpSchema\Server\Tools\DTO\Tool
$array = $dto->toArray();          // Protocol-safe array for JSON responses
```

The DTO contains only MCP specification fields. Adapter metadata (ability reference, schema transformation flags) lives on the `McpTool` instance and is never serialized.

## Utility classes

### McpNameSanitizer

Normalizes component names to MCP-valid format per MCP 2025-11-25 spec.

- **Charset**: `A-Za-z0-9_.-` only
- **Max length**: 128 characters
- **Transformations**: `/` to `-`, accent transliteration, invalid character replacement
- **Truncation**: Long names are truncated with an MD5 hash suffix for uniqueness
- **Usage**: Applied automatically during tool and prompt registration (not used for resources, which use URIs)

```php
$name = McpNameSanitizer::sanitize_name( 'my-plugin/action-name' );
// Returns: 'my-plugin-action-name'
```

### ContentBlockHelper

Factory for creating typed content block DTOs used in tool call results, prompt messages, and resource contents.

| Method | Returns | Purpose |
|--------|---------|---------|
| `text( $text )` | `TextContent` | Plain text content |
| `json_text( $data, $flags )` | `TextContent` | JSON-encoded data as text (flags: `JSON_*` constants) |
| `image( $data, $mime_type )` | `ImageContent` | Base64-encoded image |
| `audio( $data, $mime_type )` | `AudioContent` | Base64-encoded audio |
| `embedded_text_resource( $uri, $text )` | `EmbeddedResource` | Text resource embedded in content |
| `embedded_blob_resource( $uri, $blob )` | `EmbeddedResource` | Binary resource embedded in content |
| `error_text( $message )` | `TextContent` | Semantic alias for error messages |
| `to_array_list( $blocks )` | `array[]` | Converts content block DTOs to arrays |

### AbilityArgumentNormalizer

Normalizes arguments between MCP clients and WordPress abilities. MCP clients send `{}` (empty object) for tools without arguments, which PHP decodes as `[]` (empty array). Abilities without an input schema expect `null`, not an empty array. This normalizer bridges that gap.

```php
$args = AbilityArgumentNormalizer::normalize( $ability, $args );
```

### FailureReason

Provides a centralized, stable vocabulary of failure reason constants for observability events. Categories include:

- **Registration failures**: `ABILITY_NOT_FOUND`, `DUPLICATE_URI`, `ABILITY_CONVERSION_FAILED`
- **Permission failures**: `PERMISSION_DENIED`, `PERMISSION_CHECK_FAILED`, `NO_PERMISSION_STRATEGY`
- **Execution failures**: `NOT_FOUND`, `EXECUTION_FAILED`, `EXECUTION_EXCEPTION`
- **Validation failures**: `MISSING_PARAMETER`, `INVALID_PARAMETER`

### McpValidator

Extended validation for MCP component data per the MCP 2025-11-25 specification:

- `validate_name()` -- Name charset and length validation
- `validate_resource_uri()` -- URI format per RFC 3986
- `validate_mime_type()` -- MIME type format validation
- `validate_icons_array()` -- Icon object validation (src, mimeType, sizes, theme)
- `get_annotation_validation_errors()` -- Annotation field validation (audience, priority, lastModified)
- `validate_base64()` -- Base64 content validation

## Transport layer

### Transport interfaces

```php
interface McpTransportInterface {
    public function __construct( McpTransportContext $context );
    public function register_routes(): void;
}

interface McpRestTransportInterface extends McpTransportInterface {
    public function check_permission( WP_REST_Request $request );
    public function handle_request( WP_REST_Request $request ): WP_REST_Response;
}
```

### Built-in transports

- **HttpTransport**: Recommended (MCP Streamable HTTP compliant)
- **STDIO Transport**: Via WP-CLI commands

### Dependency injection

Transports and the `RequestRouter` receive all dependencies through `McpTransportContext`, which bundles the server instance, all handlers, the router, error handler, and observability handler.

### DTO-aware RequestRouter

The `RequestRouter` is the serialization boundary between typed DTOs and transport-level arrays:

1. It dispatches to the appropriate handler, which returns an `AbstractDataTransferObject` or `JSONRPCErrorResponse`.
2. For success DTOs, it calls `toArray()` and returns the resulting array.
3. For error DTOs, it extracts the error object and returns `['error' => ...]`.
4. The transport wraps the array in the JSON-RPC 2.0 envelope.

## Error handling

### Two-part system

1. **Error Response Creation**: `McpErrorFactory` creates `JSONRPCErrorResponse` DTOs for protocol errors
2. **Error Logging**: `McpErrorHandlerInterface` implementations log errors for monitoring

```php
// Protocol error DTO (returned to clients via JSON-RPC)
$error_response = McpErrorFactory::tool_not_found( $request_id, $tool_name );

// Error logging (for monitoring)
$error_handler->log( 'Tool not found', [
    'tool_name' => $tool_name,
    'user_id'   => get_current_user_id(),
    'server_id' => $server_id,
], 'error' );
```

### Built-in error handlers

- **ErrorLogMcpErrorHandler**: Logs to PHP error log
- **NullMcpErrorHandler**: No-op handler (default)

## Observability

### Event emission pattern

The system emits events rather than storing counters:

```php
interface McpObservabilityHandlerInterface {
    public function record_event( string $event, array $tags = [], ?float $duration_ms = null ): void;
}
```

### Tracked events

- **Request events**: `mcp.request` with status, method, transport, and duration tags
- **Component events**: `mcp.component.registered`, `mcp.component.registration_failed`
- **Per-component context**: Extracted from `McpComponentInterface::get_observability_context()` and merged into request tags

## Extension points

### Custom transport

```php
class MyTransport implements McpRestTransportInterface {
    use McpTransportHelperTrait;

    private McpTransportContext $context;

    public function __construct( McpTransportContext $context ) {
        $this->context = $context;
    }

    public function register_routes(): void {
        // Register custom REST routes
    }

    public function check_permission( WP_REST_Request $request ) {
        return current_user_can( 'manage_options' );
    }

    public function handle_request( WP_REST_Request $request ): WP_REST_Response {
        $body   = $request->get_json_params();
        $result = $this->context->request_router->route_request(
            $body['method'],
            $body['params'] ?? [],
            $body['id'] ?? 0,
            'my-transport'
        );

        return new WP_REST_Response( $result );
    }
}
```

### Custom error handler

```php
class MyErrorHandler implements McpErrorHandlerInterface {
    public function log( string $message, array $context = [], string $type = 'error' ): void {
        MyMonitoringSystem::send( $message, $context, $type );
    }
}
```

### Custom observability handler

```php
class MyObservabilityHandler implements McpObservabilityHandlerInterface {
    use McpObservabilityHelperTrait;

    public function record_event( string $event, array $tags = [], ?float $duration_ms = null ): void {
        $formatted_event = self::format_metric_name( $event );
        $merged_tags     = self::merge_tags( $tags );

        MyMetricsSystem::counter( $formatted_event, 1, $merged_tags );

        if ( null !== $duration_ms ) {
            MyMetricsSystem::timing( $formatted_event, $duration_ms, $merged_tags );
        }
    }
}
```

## Design principles

- **Two-layer DTO separation**: Protocol DTOs from `php-mcp-schema` carry no adapter-internal fields; `get_protocol_dto()->toArray()` always produces spec-compliant output
- **Dependency injection**: All transports receive dependencies through `McpTransportContext`; no global state beyond the `McpAdapter` singleton
- **Interface-based design**: Error handlers, observability, and transports are all swappable via interfaces
- **Event emission over counters**: Observability emits events; external systems handle aggregation — zero overhead when disabled
- **Lazy loading**: Components created only when needed; validation disabled by default via `mcp_adapter_validation_enabled` filter

## Next steps

- **[Creating Abilities](../guides/creating-abilities.md)** -- Build MCP components from WordPress abilities
- **[Custom Transports](../guides/custom-transports.md)** -- Implement specialized transport protocols
- **[Error Handling](../guides/error-handling.md)** -- Custom error management
- **[Observability](../guides/observability.md)** -- Metrics and monitoring
- **[v0.5.0 Migration Guide](../migration/v0.5.0.md)** -- Upgrading from previous versions
