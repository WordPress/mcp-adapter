# Architecture Overview

This document explains how the MCP Adapter transforms WordPress abilities into MCP components and handles requests from AI agents.

## System Architecture

The MCP Adapter uses a layered architecture with clear separation of concerns:

1. **Transport Layer**: Handles communication protocols (HTTP, STDIO)
2. **Core Layer**: Manages servers, routing, and component registration  
3. **Component Layer**: Tools, resources, and prompts
4. **WordPress Layer**: Abilities API integration

## Core Components

### McpAdapter (Singleton Registry)
- **Purpose**: Central registry managing multiple MCP servers
- **Key Methods**: `create_server()`, `get_servers()`, `instance()`
- **Initialization**: Hooks into `rest_api_init` and fires `mcp_adapter_init` action

### McpServer (Server Instance)  
- **Purpose**: Individual MCP server with specific configuration
- **Components**: Uses `McpComponentRegistry` to manage tools, resources, prompts
- **Dependencies**: Error handler, observability handler, transport permission callback

### McpTransportFactory
- **Purpose**: Creates transport instances with dependency injection
- **Context Creation**: Builds `McpTransportContext` with all required handlers
- **Validation**: Ensures transport classes implement `McpTransportInterface`

### RequestRouter
- **Purpose**: Routes MCP method calls to appropriate handlers
- **Methods**: Maps method names to handler functions
- **Observability**: Tracks request metrics and timing

## Request Flow

Simple request flow through the system:

```
AI Agent → Transport → RequestRouter → Handler → WordPress Ability → Response
```

### Detailed Flow
1. **Transport** receives MCP request and authenticates
2. **RequestRouter** maps method to appropriate handler
3. **Handler** finds component (tool/resource/prompt) and validates input
4. **WordPress Ability** executes with permission checks
5. **Response** formatted and returned through transport

### Method Routing

The `RequestRouter` maps MCP methods to handlers:

```php
$handlers = [
    'initialize'          => InitializeHandler,
    'tools/list'          => ToolsHandler::list_tools(),
    'tools/call'          => ToolsHandler::call_tool(),
    'resources/list'      => ResourcesHandler::list_resources(),
    'resources/read'      => ResourcesHandler::read_resource(),
    'prompts/list'        => PromptsHandler::list_prompts(),
    'prompts/get'         => PromptsHandler::get_prompt(),
    'ping'                => SystemHandler::ping(),
    'logging/setLevel'    => SystemHandler::set_logging_level(),
    'completion/complete' => SystemHandler::complete(),
    'roots/list'          => SystemHandler::list_roots(),
];
```

## Component Creation

### Ability to MCP Component Conversion

WordPress abilities are converted to MCP components using factory classes:

```php
// Tools
$tool = RegisterAbilityAsMcpTool::make($ability, $server);

// Resources (require 'uri' in ability meta)
$resource = RegisterAbilityAsMcpResource::make($ability, $server);

// Prompts (support 'arguments' and 'annotations' in ability meta)
$prompt = RegisterAbilityAsMcpPrompt::make($ability, $server);
```

### Component Registry

The `McpComponentRegistry` manages component registration:

```php
class McpComponentRegistry {
    public function register_ability_as_tool(string $ability_name): void;
    public function register_ability_as_resource(string $ability_name): void;
    public function register_ability_as_prompt(string $ability_name): void;
    
    // Automatic observability tracking for registration events
}
```

## Transport Layer

### Transport Interfaces

```php
interface McpTransportInterface {
    public function __construct(McpTransportContext $context);
    public function register_routes(): void;
}

interface McpRestTransportInterface extends McpTransportInterface {
    public function check_permission(WP_REST_Request $request);
    public function handle_request(WP_REST_Request $request): WP_REST_Response;
}
```

### Built-in Transports

- **HttpTransport**: Recommended (MCP 2025-06-18 compliant)
- **STDIO Transport**: Via WP-CLI commands

### Dependency Injection

Transports receive all dependencies through `McpTransportContext`:

```php
class McpTransportContext {
    public McpServer $mcp_server;
    public InitializeHandler $initialize_handler;
    public ToolsHandler $tools_handler;
    public ResourcesHandler $resources_handler;
    public PromptsHandler $prompts_handler;
    public SystemHandler $system_handler;
    public RequestRouter $request_router;
    public string $observability_handler;
    public McpErrorHandlerInterface $error_handler;
    public $transport_permission_callback;
}
```

## Error Handling

### Two-Part System

1. **Error Response Creation**: `McpErrorFactory` creates JSON-RPC error responses
2. **Error Logging**: `McpErrorHandlerInterface` implementations log errors

```php
// Error response (for clients)
$error_response = McpErrorFactory::tool_not_found($request_id, $tool_name);

// Error logging (for monitoring)
$error_handler->log('Tool not found', [
    'tool_name' => $tool_name,
    'user_id' => get_current_user_id(),
    'server_id' => $server_id
], 'error');
```

### Built-in Error Handlers

- **ErrorLogMcpErrorHandler**: Logs to PHP error log
- **NullMcpErrorHandler**: No-op handler (default)

## Observability

### Event Emission Pattern

The system emits events rather than storing counters:

```php
interface McpObservabilityHandlerInterface {
    public static function record_event(string $event, array $tags = []): void;
    public static function record_timing(string $metric, float $duration_ms, array $tags = []): void;
}
```

### Tracked Events

- **Request events**: `mcp.request.count`, `mcp.request.success`, `mcp.request.error`
- **Component events**: `mcp.component.registered`, `mcp.component.registration_failed`
- **Tool events**: `mcp.tool.execution_success`, `mcp.tool.execution_failed`
- **Timing events**: `mcp.request.duration`

## Design Patterns

### Singleton Pattern (McpAdapter)

```php
class McpAdapter {
    private static self $instance;
    
    public static function instance(): self {
        if (!isset(self::$instance)) {
            self::$instance = new self();
            add_action('rest_api_init', [self::$instance, 'init'], 15);
        }
        return self::$instance;
    }
}
```

### Factory Pattern (Component Creation)

```php
class RegisterAbilityAsMcpTool {
    public static function make(WP_Ability $ability, McpServer $server): McpTool {
        // Convert WordPress ability to MCP tool
        return McpTool::from_array($tool_data, $server);
    }
}
```

### Strategy Pattern (Transport Layer)

Different transport implementations share the same interface:

```php
class HttpTransport implements McpRestTransportInterface {
    public function __construct(McpTransportContext $context) {
        // Dependency injection
    }
    
    public function handle_request(WP_REST_Request $request): WP_REST_Response {
        // HTTP-specific handling
    }
}
```

## Extension Points

### Custom Transport

```php
class MyTransport implements McpRestTransportInterface {
    use McpTransportHelperTrait;
    
    private McpTransportContext $context;
    
    public function __construct(McpTransportContext $context) {
        $this->context = $context;
        $this->register_routes();
    }
    
    public function check_permission(WP_REST_Request $request) {
        // Custom authentication logic
        return current_user_can('manage_options');
    }
    
    public function handle_request(WP_REST_Request $request): WP_REST_Response {
        // Route through the injected router
        $body = $request->get_json_params();
        $result = $this->context->request_router->route_request(
            $body['method'],
            $body['params'] ?? [],
            $body['id'] ?? 0,
            $this->get_transport_name()
        );
        
        return rest_ensure_response($result);
    }
}
```

### Custom Error Handler

```php
class MyErrorHandler implements McpErrorHandlerInterface {
    public function log(string $message, array $context = [], string $type = 'error'): void {
        // Send to your monitoring system
        MyMonitoringSystem::send($message, $context, $type);
        
        // Fallback to local logging
        error_log("[MCP {$type}] {$message}");
    }
}
```

### Custom Observability Handler

```php
class MyObservabilityHandler implements McpObservabilityHandlerInterface {
    use McpObservabilityHelperTrait;
    
    public static function record_event(string $event, array $tags = []): void {
        $formatted_event = self::format_metric_name($event);
        $merged_tags = self::merge_tags($tags);
        
        // Send to your metrics system
        MyMetricsSystem::counter($formatted_event, 1, $merged_tags);
    }
    
    public static function record_timing(string $metric, float $duration_ms, array $tags = []): void {
        $formatted_metric = self::format_metric_name($metric);
        $merged_tags = self::merge_tags($tags);
        
        // Send timing data
        MyMetricsSystem::timing($formatted_metric, $duration_ms, $merged_tags);
    }
}
```

## Key Architectural Decisions

### Dependency Injection
- All transports receive dependencies through `McpTransportContext`
- No global state or static dependencies
- Easy testing and mocking

### Interface-Based Design
- All major components implement interfaces
- Swappable implementations (error handlers, observability, transports)
- Clean separation of concerns

### Event Emission
- System emits events rather than storing local counters
- External systems handle aggregation and analysis
- Zero memory overhead when observability is disabled

### WordPress Integration
- Leverages WordPress Abilities API for component definition
- Uses WordPress REST API for HTTP transport
- Integrates with WordPress permission system

## Performance Considerations

### Lazy Loading
- Components created only when needed
- Validation can be disabled for performance
- Null object pattern for disabled features

### Caching
- WordPress object cache integration
- Component registry caching
- Ability lookup optimization

### Memory Management
- No persistent state storage
- Event emission pattern prevents memory leaks
- Configurable validation to reduce overhead

## Next Steps

- **[Creating Abilities](../guides/creating-abilities.md)** - Build MCP components
- **[Custom Transports](../guides/custom-transports.md)** - Specialized protocols
- **[Error Handling](../guides/error-handling.md)** - Custom error management
- **[Observability](../guides/observability.md)** - Metrics and monitoring