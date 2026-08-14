# Architecture overview

This document explains how the MCP Adapter transforms WordPress abilities into MCP components and handles requests from AI agents.

## Directory structure

```
includes/
│
├── Plugin.php                     # Bootstrap — singleton, dependency check, initializes McpAdapter
├── Autoloader.php                 # PSR-4 autoloader
│
├── Core/                          # Registry and server management
│   ├── McpAdapter.php             # Main singleton registry; fires mcp_adapter_init
│   ├── McpServer.php              # Individual server configuration and component access
│   ├── McpComponentRegistry.php   # Stores and retrieves McpComponentInterface instances
│   ├── McpTransportFactory.php    # Instantiates transports with dependency injection
│   └── McpVersionNegotiator.php   # MCP protocol version negotiation
│
├── Abilities/                     # Built-in meta-abilities for the default server
│   ├── DiscoverAbilitiesAbility.php  # mcp-adapter/discover-abilities
│   ├── ExecuteAbilityAbility.php     # mcp-adapter/execute-ability
│   ├── GetAbilityInfoAbility.php     # mcp-adapter/get-ability-info
│   └── McpAbilityHelperTrait.php     # Shared helpers (mcp.public check, mcp.type)
│
├── Cli/                           # WP-CLI integration
│   ├── McpCommand.php             # wp mcp-adapter serve / list
│   └── StdioServerBridge.php      # Bridges WP-CLI stdin/stdout to MCP server
│
├── Domain/                        # Business logic and MCP component models
│   ├── Contracts/
│   │   └── McpComponentInterface.php       # Internal contract for all MCP components
│   ├── Utils/
│   │   ├── McpNameSanitizer.php            # Converts ability names to MCP-safe names
│   │   ├── McpValidator.php                # Validates names, URIs, and schemas
│   │   ├── McpAnnotationMapper.php         # Maps ability meta.annotations to MCP DTOs
│   │   ├── SchemaTransformer.php           # Transforms JSON Schema formats
│   │   ├── ContentBlockHelper.php          # Factory for MCP content block arrays
│   │   └── AbilityArgumentNormalizer.php   # Normalizes empty {} input to null
│   ├── Tools/
│   │   ├── McpTool.php                     # Wraps protocol tool data with execution logic
│   │   ├── RegisterAbilityAsMcpTool.php    # Converts a WordPress ability to McpTool
│   │   └── McpToolValidator.php            # Validates tool names and schemas
│   ├── Resources/
│   │   ├── McpResource.php                 # Wraps protocol resource data with execution logic
│   │   ├── RegisterAbilityAsMcpResource.php # Converts a WordPress ability to McpResource
│   │   └── McpResourceValidator.php        # Validates resource URIs and schemas
│   └── Prompts/
│       ├── Contracts/
│       │   └── McpPromptBuilderInterface.php  # Interface for prompt message builders
│       ├── McpPrompt.php                      # Wraps protocol prompt data with execution logic
│       ├── McpPromptBuilder.php               # Builds prompt messages from ability output
│       ├── McpPromptValidator.php             # Validates prompt names and arguments
│       └── RegisterAbilityAsMcpPrompt.php     # Converts a WordPress ability to McpPrompt
│
├── Handlers/                      # JSON-RPC method handlers
│   ├── HandlerHelperTrait.php     # Shared error response helpers
│   ├── Initialize/
│   │   └── InitializeHandler.php  # Handles initialize / initialized
│   ├── Tools/
│   │   └── ToolsHandler.php       # Handles tools/list, tools/call
│   ├── Resources/
│   │   └── ResourcesHandler.php   # Handles resources/list, resources/read
│   ├── Prompts/
│   │   └── PromptsHandler.php     # Handles prompts/list, prompts/get
│   └── System/
│       └── SystemHandler.php      # Handles ping, notifications/cancelled
│
├── Infrastructure/
│   ├── ErrorHandling/
│   │   ├── Contracts/
│   │   │   └── McpErrorHandlerInterface.php  # log( $message, $context, $type )
│   │   ├── ErrorLogMcpErrorHandler.php        # Logs to PHP error_log
│   │   ├── NullMcpErrorHandler.php            # No-op (null object pattern)
│   │   └── McpErrorFactory.php                # Creates JSON-RPC error responses
│   └── Observability/
│       ├── Contracts/
│       │   └── McpObservabilityHandlerInterface.php  # record_event( $event, $tags, $duration_ms )
│       ├── ErrorLogMcpObservabilityHandler.php        # Logs events to PHP error_log
│       ├── NullMcpObservabilityHandler.php            # No-op (null object pattern)
│       ├── ConsoleObservabilityHandler.php            # Outputs events to stdout
│       ├── McpObservabilityHelperTrait.php            # Tag management helpers
│       └── FailureReason.php                          # Standardized failure reason constants
│
├── Transport/
│   ├── Contracts/
│   │   ├── McpTransportInterface.php      # Base transport contract
│   │   └── McpRestTransportInterface.php  # REST transport contract (register_routes, check_permission)
│   ├── HttpTransport.php                  # Unified HTTP transport (MCP 2025-11-25)
│   └── Infrastructure/
│       ├── HttpRequestContext.php         # Encapsulates HTTP request data
│       ├── HttpRequestHandler.php         # Processes raw HTTP requests
│       ├── HttpSessionValidator.php       # Validates Mcp-Session-Id header
│       ├── JsonRpcRequestDecoder.php       # Preserves JSON object/list identity
│       ├── JsonRpcResponseBuilder.php     # Builds JSON-RPC responses
│       ├── McpTransportContext.php        # Bundles server + handlers for transport use
│       ├── McpTransportHelperTrait.php    # Shared transport utilities
│       ├── RequestRouter.php              # Routes MCP methods to handlers; records observability events
│       └── SessionManager.php            # Creates and manages HTTP sessions
│
└── Servers/
    └── DefaultServerFactory.php          # Creates the default mcp-adapter-default-server
```

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

The Adapter Layer wraps each component's protocol data with execution wiring and WordPress-specific metadata. Domain models `McpTool`, `McpResource`, and `McpPrompt` each implement the `McpComponentInterface` contract:

```php
interface McpComponentInterface {
    public function get_protocol_dto(): array;
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

### McpVersionNegotiator

Negotiates the MCP protocol version between client and server. If the client requests a supported version it is echoed back; otherwise the server falls back to the latest supported version.

**Supported protocol versions** (newest-first):
- `2025-11-25` (latest — recommended)
- `2025-06-18`
- `2024-11-05`

### McpTransportFactory
- **Purpose**: Creates transport instances with dependency injection
- **Context Creation**: Builds `McpTransportContext` with all required handlers
- **Validation**: Ensures transport classes implement `McpTransportInterface`

### RequestRouter
- **Purpose**: Routes MCP method calls to handlers that return revision-neutral arrays in wire shape
- **Outcome split**: A result carrying a top-level `error` key is a JSON-RPC error envelope; the router forwards only its `error` object as `['error' => ...]`. Everything else is a success result and passes through unchanged
- **Observability**: Extracts per-component context from `McpComponentInterface::get_observability_context()` for request tagging

## Request flow

```
AI Agent --> Transport --> RequestRouter --> Handler --> McpComponentInterface --> Wire-shape array --> Response
```

### Detailed flow
1. **Transport** receives MCP request and authenticates
2. **RequestRouter** maps method to appropriate handler
3. **Handler** finds the `McpComponentInterface` component, validates input, and invokes execution
4. **Component** delegates to a WordPress ability or direct callable, returning a result
5. **Handler** serializes the result into a wire-shape array (the schema DTOs still validate and serialize internally until the dependency swap)
6. **RequestRouter** forwards the array, splitting error envelopes from success results
7. **Transport** wraps the array in a JSON-RPC envelope and returns it

### Method routing

The `RequestRouter` maps MCP methods to handlers. All handlers return revision-neutral arrays in wire shape:

| Method | Handler | Return Shape |
|--------|---------|--------------|
| `initialize` | `InitializeHandler::handle()` | Initialize result |
| `tools/list` | `ToolsHandler::list_tools()` | Tools list result |
| `tools/call` | `ToolsHandler::call_tool()` | Tool-call result or JSON-RPC error envelope |
| `resources/list` | `ResourcesHandler::list_resources()` | Resources list result |
| `resources/read` | `ResourcesHandler::read_resource()` | Read-resource result or JSON-RPC error envelope |
| `prompts/list` | `PromptsHandler::list_prompts()` | Prompts list result |
| `prompts/get` | `PromptsHandler::get_prompt()` | Prompt result or JSON-RPC error envelope |
| `ping` | `SystemHandler::ping()` | Empty result |

Protocol-level errors (tool not found, missing parameters) return a JSON-RPC error envelope array. Execution-level errors (permission denied, runtime failure) return the appropriate result array with `isError: true`.

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

### Protocol data access

Each component exposes its clean protocol data for serialization:

```php
$tool_data = $tool->get_protocol_dto();  // Returns array<string, mixed> in wire shape
```

The array contains only MCP specification fields. Adapter metadata (ability reference, schema transformation flags) lives on the `McpTool` instance and is never serialized.

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

Factory for creating content block arrays used in tool call results, prompt messages, and resource contents. Each method builds the matching schema DTO internally, so DTO validation still runs, and returns its array form.

| Method | Returns | Purpose |
|--------|---------|---------|
| `text( $text )` | `array` | Plain text content |
| `json_text( $data, $flags )` | `array` | JSON-encoded data as text (flags: `JSON_*` constants) |
| `image( $data, $mime_type )` | `array` | Base64-encoded image |
| `audio( $data, $mime_type )` | `array` | Base64-encoded audio |
| `embedded_text_resource( $uri, $text )` | `array` | Text resource embedded in content |
| `embedded_blob_resource( $uri, $blob )` | `array` | Binary resource embedded in content |
| `error_text( $message )` | `array` | Semantic alias for error messages |
| `to_array_list( $blocks )` | `array[]` | Returns content blocks in array form |

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

### Array-consuming RequestRouter

The `RequestRouter` consumes wire-shape arrays from handlers and splits outcomes for the transport:

1. It dispatches to the appropriate handler, which returns a revision-neutral array — a result in wire shape or a JSON-RPC error envelope.
2. For error envelopes (identified by their top-level `error` key), it forwards only the error object as `['error' => ...]`.
3. For success results, it forwards the array unchanged.
4. The transport wraps the array in the JSON-RPC 2.0 envelope.

## Error handling

### Two-part system

1. **Error Response Creation**: `McpErrorFactory` creates JSON-RPC error envelope arrays for protocol errors
2. **Error Logging**: `McpErrorHandlerInterface` implementations log errors for monitoring

```php
// Protocol error envelope array (returned to clients via JSON-RPC)
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
        try {
            $request_identity = JsonRpcRequestDecoder::decode( (string) $request->get_body() );
        } catch ( JsonException $exception ) {
            return new WP_REST_Response( ['error' => 'Invalid JSON'], 400 );
        }

        if ( ! $request_identity instanceof stdClass ) {
            return new WP_REST_Response( ['error' => 'MCP request object required'], 400 );
        }

        $body   = JsonRpcRequestDecoder::to_associative( $request_identity );
        $result = $this->context->request_router->route_request(
            $body['method'],
            $body['params'] ?? [],
            $body['id'] ?? 0,
            'my-transport',
            null,
            $request_identity
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

- **Two-layer separation**: Protocol data validated through `php-mcp-schema` carries no adapter-internal fields; `get_protocol_dto()` always returns spec-compliant wire-shape arrays
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
