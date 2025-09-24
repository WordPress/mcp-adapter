# Observability

The MCP Adapter tracks metrics and events throughout the request lifecycle using an interface-based observability system.

## System Overview

The observability system has two main components:

- **Event Tracking**: `McpObservabilityHandlerInterface` implementations track events and metrics
- **Helper Utilities**: `McpObservabilityHelperTrait` provides tag management and error categorization

```php
use WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface;

interface McpObservabilityHandlerInterface {
    public static function record_event(string $event, array $tags = []): void;
    public static function record_timing(string $metric, float $duration_ms, array $tags = []): void;
}
```

### Event Emission Pattern

- **MCP Adapter**: Emits individual events as they happen to handlers
- **Handlers**: Send events to external systems (logs, StatsD, Prometheus, etc.)
- **External Systems**: Aggregate and analyze events

## Built-in Handlers

### NullMcpObservabilityHandler

No-op handler that ignores all events (zero overhead when observability is disabled):

```php
$handler = new NullMcpObservabilityHandler();
$handler::record_event('test.event', []); // Does nothing
$handler::record_timing('test.metric', 123.45, []); // Does nothing
```

### ErrorLogMcpObservabilityHandler

Logs events and metrics to PHP error log with structured formatting:

```php
$handler = new ErrorLogMcpObservabilityHandler();
$handler::record_event('mcp.tool.execution_success', ['tool_name' => 'my-tool']);
// Logs: [MCP Observability] EVENT mcp.tool.execution_success [tool_name=my-tool,site_id=1,user_id=123,timestamp=1234567890]

$handler::record_timing('mcp.request.duration', 45.23, ['method' => 'tools/call']);
// Logs: [MCP Observability] TIMING mcp.request.duration 45.23ms [method=tools/call,site_id=1,user_id=123,timestamp=1234567890]
```

## Events Tracked

### Request Events
- `mcp.request.count` - Total requests processed
- `mcp.request.success` - Successful requests  
- `mcp.request.error` - Failed requests
- `mcp.request.duration` - Request processing time (timing metric)

### Component Events
- `mcp.component.registered` - Component registration success
- `mcp.component.registration_failed` - Component registration failures
- `mcp.server.created` - MCP server creation

### Tool Events
- `mcp.tool.not_found` - Tool lookup failures
- `mcp.tool.permission_denied` - Permission denied for tool access
- `mcp.tool.permission_check_failed` - Permission validation errors
- `mcp.tool.execution_success` - Successful tool executions
- `mcp.tool.execution_failed` - Tool execution failures
- `mcp.tool.ability_wp_error` - WordPress ability errors

### Common Tags

All events include these tags:
- `method` - MCP method (e.g., `tools/call`, `resources/list`)
- `transport` - Transport type (e.g., `rest`, `streamable`)
- `site_id` - WordPress site ID
- `user_id` - WordPress user ID
- `timestamp` - Unix timestamp
- `tool_name` - Tool name (for tool events)
- `component_type` - Component type (tool, resource, prompt)
- `server_id` - MCP server ID

### Error Event Tags

Error events include additional categorization:
- `error_type` - Exception class name
- `error_category` - General category (validation, execution, logic, system, type, arguments, unknown)
- `error_message_hash` - Hash for grouping similar errors

## Helper Trait

`McpObservabilityHelperTrait` provides utility methods for handlers:

### Tag Management
- `get_default_tags()` - Default tags (site_id, user_id, timestamp)
- `sanitize_tags()` - Remove sensitive data and limit tag length
- `merge_tags()` - Combine user tags with defaults
- `format_metric_name()` - Ensure consistent metric naming with 'mcp.' prefix

### Error Handling
- `record_error_event()` - Emit error events with standardized categorization
- `categorize_error()` - Classify exceptions into standard categories

```php
use WP\MCP\Infrastructure\Observability\McpObservabilityHelperTrait;

class MyHandler implements McpObservabilityHandlerInterface {
    use McpObservabilityHelperTrait;
    
    public static function record_event(string $event, array $tags = []): void {
        $formatted_event = self::format_metric_name($event);
        $merged_tags = self::merge_tags($tags);
        // ... send to your system
    }
}
```

## Creating Custom Handlers

Implement `McpObservabilityHandlerInterface` to create custom handlers:

### File-based Handler

```php
use WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface;
use WP\MCP\Infrastructure\Observability\McpObservabilityHelperTrait;

class FileObservabilityHandler implements McpObservabilityHandlerInterface {
    use McpObservabilityHelperTrait;
    
    public static function record_event(string $event, array $tags = []): void {
        $formatted_event = self::format_metric_name($event);
        $merged_tags = self::merge_tags($tags);
        
        $log_entry = sprintf('[MCP Event] %s | Tags: %s', 
            $formatted_event, 
            wp_json_encode($merged_tags)
        );
        
        file_put_contents(WP_CONTENT_DIR . '/mcp-metrics.log', 
            $log_entry . "\n", FILE_APPEND | LOCK_EX);
    }
    
    public static function record_timing(string $metric, float $duration_ms, array $tags = []): void {
        $formatted_metric = self::format_metric_name($metric);
        $merged_tags = self::merge_tags($tags);
        
        $log_entry = sprintf('[MCP Timing] %s: %.2fms | Tags: %s',
            $formatted_metric, 
            $duration_ms, 
            wp_json_encode($merged_tags)
        );
        
        file_put_contents(WP_CONTENT_DIR . '/mcp-metrics.log', 
            $log_entry . "\n", FILE_APPEND | LOCK_EX);
    }
}
```

### External Service Handler

```php
class ExternalServiceObservabilityHandler implements McpObservabilityHandlerInterface {
    use McpObservabilityHelperTrait;
    
    public static function record_event(string $event, array $tags = []): void {
        wp_remote_post('https://metrics.example.com/api/events', [
            'body' => wp_json_encode([
                'type' => 'event',
                'name' => self::format_metric_name($event),
                'tags' => self::merge_tags($tags),
                'site' => get_site_url()
            ]),
            'headers' => ['Content-Type' => 'application/json'],
            'timeout' => 5
        ]);
    }
    
    public static function record_timing(string $metric, float $duration_ms, array $tags = []): void {
        wp_remote_post('https://metrics.example.com/api/timings', [
            'body' => wp_json_encode([
                'type' => 'timing',
                'name' => self::format_metric_name($metric),
                'duration' => $duration_ms,
                'tags' => self::merge_tags($tags),
                'site' => get_site_url()
            ]),
            'headers' => ['Content-Type' => 'application/json'],
            'timeout' => 5
        ]);
    }
}
```

## Using Custom Handlers

Once you've created custom observability handlers, you can configure them for use in your MCP Adapter setup.

### Replacing the Default Server's Observability Handler

The default MCP server created by the adapter can have its observability handler replaced using the `mcp_adapter_default_server_config` filter:

```php
// Replace the default server's observability handler
add_filter('mcp_adapter_default_server_config', function($config) {
    $config['observability_handler'] = FileObservabilityHandler::class;
    return $config;
});

// Or disable observability entirely
add_filter('mcp_adapter_default_server_config', function($config) {
    $config['observability_handler'] = NullMcpObservabilityHandler::class;
    return $config;
});
```

### Configuring Observability for Custom Servers

When creating custom servers, you can specify the observability handler directly:

```php
// In your plugin's initialization
add_action('mcp_adapter_init', function($adapter) {
    $adapter->create_server(
        'my-custom-server',
        'my-namespace',
        'my-route',
        'My Custom Server',
        'A custom MCP server with file-based observability',
        '1.0.0',
        [MyCustomTransport::class],
        null, // Use default error handler
        FileObservabilityHandler::class, // Custom observability handler
        ['my-tool'], // tools
        [], // resources
        [], // prompts
        null // transport permission callback
    );
});
```
