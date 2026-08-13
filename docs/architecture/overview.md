# Architecture overview

This document explains how the MCP Adapter transforms WordPress abilities into MCP components and handles requests from AI agents.

## Directory structure

```text
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
│   ├── McpProtocolContext.php     # Exact request revision, schema catalog, and capabilities
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
│   ├── Continuation/
│   │   ├── McpContinuationContext.php      # Stateless input responses and request state
│   │   └── McpExecutionResult.php          # Complete or input-required callback outcome
│   ├── Utils/
│   │   ├── McpNameSanitizer.php            # Converts ability names to MCP-safe names
│   │   ├── McpValidator.php                # Validates names, URIs, and schemas
│   │   ├── McpAnnotationMapper.php         # Maps ability meta.annotations to protocol arrays
│   │   ├── SchemaTransformer.php           # Transforms JSON Schema formats
│   │   ├── ContentBlockHelper.php          # Factory for MCP content block arrays
│   │   └── AbilityArgumentNormalizer.php   # Normalizes empty {} input to null
│   ├── Tools/
│   │   ├── McpTool.php                     # Stores tool data and execution logic
│   │   ├── RegisterAbilityAsMcpTool.php    # Converts a WordPress ability to McpTool
│   │   └── McpToolValidator.php            # Validates tool names and schemas
│   ├── Resources/
│   │   ├── McpResource.php                 # Stores resource data and execution logic
│   │   ├── RegisterAbilityAsMcpResource.php # Converts a WordPress ability to McpResource
│   │   └── McpResourceValidator.php        # Validates resource URIs and schemas
│   └── Prompts/
│       ├── Contracts/
│       │   └── McpPromptBuilderInterface.php  # Interface for prompt message builders
│       ├── McpPrompt.php                      # Stores prompt data and execution logic
│       ├── McpPromptBuilder.php               # Builds prompt messages from ability output
│       ├── McpPromptValidator.php             # Validates prompt names and arguments
│       └── RegisterAbilityAsMcpPrompt.php     # Converts a WordPress ability to McpPrompt
│
├── Handlers/                      # JSON-RPC method handlers
│   ├── HandlerHelperTrait.php     # Shared error response helpers
│   ├── Initialize/
│   │   └── InitializeHandler.php  # Handles legacy initialize
│   ├── Tools/
│   │   └── ToolsHandler.php       # Handles tools/list, tools/call
│   ├── Resources/
│   │   └── ResourcesHandler.php   # Handles resources/list, resources/read
│   ├── Prompts/
│   │   └── PromptsHandler.php     # Handles prompts/list, prompts/get
│   └── System/
│       └── SystemHandler.php      # Handles legacy ping
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
│   ├── HttpTransport.php                  # Unified dual-revision HTTP transport
│   └── Infrastructure/
│       ├── HttpRequestContext.php         # Encapsulates HTTP request data
│       ├── HttpRequestHandler.php         # Processes raw HTTP requests
│       ├── HttpSessionValidator.php       # Validates Mcp-Session-Id header
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

The MCP Adapter uses two layers that separate exact wire contracts from WordPress integration.

### Schema layer (descriptor-backed records)

The `php-mcp-schema` package (`WP\McpSchema\` namespace) owns the exact schemas for each supported MCP revision. `McpProtocolContext` selects one catalog for each request. The router hydrates descriptor-backed records from revision-neutral arrays, validates them, and calls `toWireArray()` at the response boundary.

The Adapter's component, handler, and routing state does not use generated concrete protocol DTO trees. That keeps revision identity in one place and prevents internal code from accidentally serializing the shape for a different revision.

`php-mcp-schema` also retains five narrow, descriptor-backed facades under legacy namespaces: `InitializeResult`, `Tool`, `Resource`, `Prompt`, and `PromptArgument`. The Adapter uses them only at established public compatibility boundaries such as prompt builders and list/initialize filters. They hydrate through the descriptor runtime; they are not a second wire codec or the Adapter's internal data model.

### Adapter layer (WordPress integration)

`McpTool`, `McpResource`, and `McpPrompt` store clean protocol arrays alongside their execution wiring and WordPress-specific metadata. Each implements the internal `McpComponentInterface` contract:

```php
interface McpComponentInterface {
    public function get_protocol_data(): array;
    public function execute( $arguments, ?McpContinuationContext $continuation = null );
    public function check_permission( $arguments );
    public function get_adapter_meta(): array;
    public function get_observability_context(): array;
}
```

This separation ensures that:

- **Protocol data** contains only fields defined by MCP and remains independent of an exact revision until routing.
- **Adapter metadata** (ability references, schema transformation flags, permission callbacks) stays internal and is never exposed to MCP clients.
- **Schema catalogs** validate exact request and response shapes and own JSON map/list identity and wire serialization.
- **Compatibility facades** preserve established third-party types without reintroducing revision-specific internal DTO graphs.
- **Observability context** provides structured tags for logging and metrics without polluting protocol `_meta`.

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
- **Protocol access**: `get_tools()`, `get_resources()`, and `get_prompts()` return protocol arrays
- **Dependencies**: Error handler, observability handler, transport permission callback

### McpComponentRegistry

- **Purpose**: Stores and retrieves `McpComponentInterface` instances
- **Registration**: `register_tools()`, `register_resources()`, `register_prompts()` accept both ability names and `McpComponentInterface` instances
- **Name sanitization**: Uses `McpNameSanitizer` to normalize tool and prompt names
- **Validation**: Validates components with `McpValidator` when validation is enabled

### McpVersionNegotiator

Owns the supported revision list and maps each supported protocol string to its exact schema catalog.

**Supported protocol versions** (newest-first):

- `2026-07-28` — stateless discovery and per-request revision selection
- `2025-11-25` — initialize negotiation and session lifecycle

Legacy `initialize` cannot negotiate into the stateless lifecycle. It echoes `2025-11-25` when requested and otherwise falls back to that supported legacy revision. Modern clients use `server/discover` and select `2026-07-28` in every request.

### McpTransportFactory

- **Purpose**: Creates transport instances with dependency injection
- **Context Creation**: Builds `McpTransportContext` with all required handlers
- **Validation**: Ensures transport classes implement `McpTransportInterface`

### RequestRouter

- **Purpose**: Selects the exact protocol context, validates requests, dispatches revision-neutral handlers, and schema-encodes results
- **Serialization boundary**: Hydrates descriptor-backed records and calls `toWireArray()` for the selected revision
- **Observability**: Extracts per-component context from `McpComponentInterface::get_observability_context()` for request tagging

## Request flow

```text
AI Agent -> Transport -> RequestRouter -> Handler -> McpComponentInterface
                     -> exact schema record -> JSON-RPC response
```

### Detailed flow

1. **Transport** authenticates the request and determines its protocol revision from the negotiated legacy session or modern request metadata.
2. **RequestRouter** creates `McpProtocolContext` and validates the full request against that revision's schema.
3. **Handler** finds the `McpComponentInterface`, checks permission, and invokes execution.
4. **Component** delegates to a WordPress ability or direct callable and returns revision-neutral data or `McpExecutionResult`.
5. **RequestRouter** adds revision-specific result fields and serializes through the selected descriptor-backed record.
6. **Transport** wraps the validated result or error in a JSON-RPC 2.0 envelope.

### Method routing

The `RequestRouter` maps supported MCP methods to revision-neutral handlers:

| Method | Revision | Handler/result source |
|--------|----------|-----------------------|
| `initialize` | 2025-11-25 | `InitializeHandler::handle()` |
| `ping` | 2025-11-25 | `SystemHandler::ping()` |
| `server/discover` | 2026-07-28 | `RequestRouter` capability discovery |
| `tools/list` | Both | `ToolsHandler::list_tools()` |
| `tools/call` | Both | `ToolsHandler::call_tool()` |
| `resources/list` | Both | `ResourcesHandler::list_resources()` |
| `resources/templates/list` | Both | `ResourcesHandler::list_resource_templates()` |
| `resources/read` | Both | `ResourcesHandler::read_resource()` |
| `prompts/list` | Both | `PromptsHandler::list_prompts()` |
| `prompts/get` | Both | `PromptsHandler::get_prompt()` |

MCP 2026-07-28 does not define `ping`, so the modern router returns method-not-found for it. Protocol-level errors are plain JSON-RPC error arrays validated by the schema layer. Tool execution errors remain successful protocol results with `isError: true`, so clients can expose them to the model for correction.

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
    'inputSchema' => array(
        'type'       => 'object',
        'properties' => array(),
    ),
    'handler'     => fn( $args ) => [ 'result' => 'done' ],
    'permission'  => fn() => current_user_can( 'edit_posts' ),
    'annotations' => [ 'readOnlyHint' => true ],
] );
```

### Protocol data access

Each component exposes clean, revision-neutral protocol data:

```php
$data = $tool->get_protocol_data();
```

The array contains only MCP fields. Adapter metadata (ability reference, schema transformation flags, callbacks) lives on the `McpTool` instance and is never serialized. Callers should not hydrate schema records themselves; the router does that after it knows the exact request revision.

### Prompt-builder compatibility

`McpPromptBuilderInterface::build()` retains its established return type:

```php
interface McpPromptBuilderInterface {
    public function build(): \WP\McpSchema\Server\Prompts\DTO\Prompt;
}
```

The built-in `McpPromptBuilder` and existing third-party implementations therefore remain compatible. The returned `Prompt` is a descriptor-backed legacy namespace facade. `McpPrompt::fromBuilder()` converts it to revision-neutral protocol data immediately; execution and exact wire serialization then follow the same path as other prompts.

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

Factory for creating revision-neutral content block arrays used in tool call results, prompt messages, and resource contents.

| Method | Returns | Purpose |
|--------|---------|---------|
| `text( $text )` | `array` | Plain text content |
| `json_text( $data, $flags )` | `array` | JSON-encoded data as text (flags: `JSON_*` constants) |
| `image( $data, $mime_type )` | `array` | Base64-encoded image |
| `audio( $data, $mime_type )` | `array` | Base64-encoded audio |
| `embedded_text_resource( $uri, $text )` | `array` | Text resource embedded in content |
| `embedded_blob_resource( $uri, $blob )` | `array` | Binary resource embedded in content |
| `error_text( $message )` | `array` | Semantic alias for error messages |
| `to_array_list( $blocks )` | `array[]` | Returns a compatibility list of content block arrays |

### AbilityArgumentNormalizer

Normalizes arguments between MCP clients and WordPress abilities. MCP clients send `{}` (empty object) for tools without arguments, which PHP decodes as `[]` (empty array). Abilities without an input schema expect `null`, not an empty array. This normalizer bridges that gap.

```php
$args = AbilityArgumentNormalizer::normalize( $ability, $args );
```

### Stateless continuation

For MCP 2026-07-28, direct tool, resource, and prompt callbacks can opt in to a second `McpContinuationContext` argument. The context carries `inputResponses`, opaque `requestState`, and the client capabilities declared on the current request. One-argument callbacks remain compatible.

A callback returns `McpExecutionResult::input_required()` to pause or `McpExecutionResult::complete()` to provide its final value. The Adapter does not keep server-side continuation state between rounds. See [Protocol versions](../guides/protocol-versions.md#multi-round-continuation) for an example and the state-security requirements.

### FailureReason

Provides a centralized, stable vocabulary of failure reason constants for observability events. Categories include:

- **Registration failures**: `ABILITY_NOT_FOUND`, `DUPLICATE_URI`, `ABILITY_CONVERSION_FAILED`
- **Permission failures**: `PERMISSION_DENIED`, `PERMISSION_CHECK_FAILED`, `NO_PERMISSION_STRATEGY`
- **Execution failures**: `NOT_FOUND`, `EXECUTION_FAILED`, `EXECUTION_EXCEPTION`
- **Validation failures**: `MISSING_PARAMETER`, `INVALID_PARAMETER`

### McpValidator

Provides Adapter-side validation for revision-neutral component data before the exact schema boundary:

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

- **HttpTransport**: Supports the MCP 2025-11-25 session lifecycle and MCP 2026-07-28 stateless requests
- **STDIO Transport**: Supports both revisions through `wp mcp-adapter serve`

### Dependency injection

Transports and the `RequestRouter` receive all dependencies through `McpTransportContext`, which bundles the server instance, all handlers, the router, error handler, and observability handler.

### Schema-backed RequestRouter

The `RequestRouter` is the boundary between revision-neutral Adapter arrays and exact MCP wire shapes:

1. It resolves `McpProtocolContext` from initialize data, modern `_meta`, or an explicit transport-selected version.
2. It validates the full JSON-RPC request with the exact `php-mcp-schema` catalog before dispatch.
3. It dispatches to handlers that return arrays or `McpExecutionResult`.
4. It adds revision-specific fields, hydrates the exact result record, and calls `toWireArray()`.
5. It validates the complete JSON-RPC success or error envelope before the transport sends it.

## Error handling

### Two-part system

1. **Error Response Creation**: `McpErrorFactory` creates stable JSON-RPC error arrays for protocol errors
2. **Error Logging**: `McpErrorHandlerInterface` implementations log errors for monitoring

```php
// Protocol error envelope (returned to clients via JSON-RPC)
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

The descriptor-backed wire boundary does not change the public `McpAdapter::create_server()`, `get_server()`, or `get_servers()` APIs, component `fromArray()` configuration keys, or existing hook names and argument order.

Four established filters retain their legacy object payloads through descriptor-backed facades:

| Filter | First payload |
|--------|---------------|
| `mcp_adapter_initialize_response` | `WP\McpSchema\Common\Protocol\DTO\InitializeResult` |
| `mcp_adapter_tools_list` | Array of `WP\McpSchema\Server\Tools\DTO\Tool` |
| `mcp_adapter_resources_list` | Array of `WP\McpSchema\Server\Resources\DTO\Resource` |
| `mcp_adapter_prompts_list` | Array of `WP\McpSchema\Server\Prompts\DTO\Prompt` |

Use each facade's existing getters and `toArray()`/`fromArray()` methods when changing these values. The handlers convert accepted filter output back to revision-neutral arrays before the exact wire boundary. Pre-execution and result filters still run around tool calls, resource reads, and prompt gets in the same order.

When a direct callback opts into modern continuation, the corresponding result filter can receive `McpExecutionResult` before the handler returns it to the router. Filters that do not implement continuation should return that object unchanged.

### Custom transport

```php
use WP\MCP\Transport\HttpTransport;

class MyTransport extends HttpTransport {
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

Extending `HttpTransport` preserves its complete JSON-RPC, notification, batch, object/list, protocol-header, and session handling. Non-HTTP transports can use the injected `RequestRouter` directly after implementing those delivery-level responsibilities.

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

- **Exact schema ownership**: `php-mcp-schema` owns revision-specific validation and serialization; Adapter components expose only revision-neutral protocol arrays
- **Narrow public compatibility**: Legacy namespace facades exist only for established prompt-builder and hook payload types
- **Dependency injection**: All transports receive dependencies through `McpTransportContext`; no global state beyond the `McpAdapter` singleton
- **Interface-based design**: Error handlers, observability, and transports are all swappable via interfaces
- **Request-scoped revision identity**: A request uses exactly one negotiated or declared protocol revision from transport selection through response serialization
- **Stateless modern continuation**: Callbacks own any `requestState`; the Adapter only returns it to the client and receives it on the next independent request
- **Event emission over counters**: Observability emits events; external systems handle aggregation — zero overhead when disabled
- **Layered validation**: Optional component-registration checks use `mcp_adapter_validation_enabled`; exact request and wire-response schema validation always runs in the router

## Next steps

- **[Creating Abilities](../guides/creating-abilities.md)** -- Build MCP components from WordPress abilities
- **[Protocol Versions](../guides/protocol-versions.md)** -- Implement exact legacy and modern request lifecycles
- **[Custom Transports](../guides/custom-transports.md)** -- Implement specialized transport protocols
- **[Error Handling](../guides/error-handling.md)** -- Custom error management
- **[Observability](../guides/observability.md)** -- Metrics and monitoring
- **[v0.5.0 Migration Guide](../migration/v0.5.0.md)** -- Upgrading from previous versions
