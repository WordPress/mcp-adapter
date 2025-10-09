# Observability

The MCP Adapter tracks metrics and events throughout the request lifecycle using an interface-based observability system with a unified event recording architecture.

## System Overview

The observability system has two main components:

- **Event Tracking**: `McpObservabilityHandlerInterface` implementations track events and metrics
- **Helper Utilities**: `McpObservabilityHelperTrait` provides tag management and error categorization

```php
use WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface;

interface McpObservabilityHandlerInterface {
    public function record_event(string $event, array $tags = [], ?float $duration_ms = null): void;
}
```

### Architecture: Metadata-Driven Observability

The observability system follows a **middleware pattern** where handlers return enriched metadata that flows up to the transport layer for centralized event recording:

1. **Handlers** (Business Logic Layer): Execute business logic and attach `_metadata` to responses
2. **RequestRouter** (Transport Layer): Extracts `_metadata`, merges with request context, and records events
3. **ObservabilityHandler**: Receives unified events with rich context from a single point

**Benefits:**
- **Single source of truth**: All observability flows through RequestRouter
- **Consistent timing**: Duration tracked at transport layer for ALL requests
- **DRY principle**: No duplicate event recording in handlers
- **Clean separation**: Handlers focus on business logic, not observability

### Event Emission Pattern

- **MCP Adapter**: Handlers attach metadata to responses
- **RequestRouter**: Extracts metadata and emits events with consistent structure
- **Handlers**: Send events to external systems (logs, StatsD, Prometheus, etc.)
- **External Systems**: Aggregate and analyze events

## Built-in Handlers

### NullMcpObservabilityHandler

No-op handler that ignores all events (zero overhead when observability is disabled):

```php
$handler = new NullMcpObservabilityHandler();
$handler->record_event('test.event', []); // Does nothing
$handler->record_event('test.metric', [], 123.45); // Event with timing - does nothing
```

### ErrorLogMcpObservabilityHandler

Logs events and metrics to PHP error log with structured formatting:

```php
$handler = new ErrorLogMcpObservabilityHandler();
$handler->record_event('mcp.request', ['status' => 'success', 'method' => 'tools/call'], 45.23);
// Logs: [MCP Observability] EVENT mcp.request 45.23ms [status=success,method=tools/call,site_id=1,user_id=123,timestamp=1234567890]
```

## Events Tracked

All events use a **consistent naming pattern with status tags** for easier filtering and aggregation.

### Request Events

**Event:** `mcp.request`

**Tags:**
- `status`: `success` | `error`
- `method`: MCP method (e.g., `tools/call`, `resources/list`)
- `transport`: Transport type (e.g., `http`)
- `server_id`: MCP server ID
- `request_id`: JSON-RPC request ID
- `session_id`: MCP session ID (null if no session)
- `params`: Sanitized request parameters (safe fields only)
- `error_code`: JSON-RPC error code (only for errors)
- `error_type`: Exception class name (only for exceptions)
- `error_category`: Error category (validation, execution, logic, system, type, arguments, unknown)

**Additional tags from handler metadata:**
- `component_type`: `tool` | `resource` | `prompt` | `tools` | `resources` | `prompts`
- `tool_name`: Tool name (for tool requests)
- `ability_name`: WordPress ability name (when applicable)
- `prompt_name`: Prompt name (for prompt requests)
- `resource_uri`: Resource URI (for resource requests)
- `failure_reason`: Specific failure reason (see below) - uses WP_Error code when available
- `new_session_id`: Newly created session ID (only on initialize requests)

**Includes duration timing**: Yes (in milliseconds)

**Examples:**
```php
// Successful tool execution
[
  'event' => 'mcp.request',
  'tags' => [
    'status' => 'success',
    'method' => 'tools/call',
    'transport' => 'http',
    'server_id' => 'default',
    'request_id' => 82,
    'session_id' => 'a3f2c1d4-5e6f-7890-abcd-ef1234567890',
    'params' => ['name' => 'create-post', 'arguments_count' => 2],
    'component_type' => 'tool',
    'tool_name' => 'create-post',
    'ability_name' => 'create_post',
  ],
  'duration_ms' => 45.23
]

// Failed request - tool not found
[
  'event' => 'mcp.request',
  'tags' => [
    'status' => 'error',
    'method' => 'tools/call',
    'transport' => 'http',
    'server_id' => 'default',
    'request_id' => 83,
    'session_id' => 'a3f2c1d4-5e6f-7890-abcd-ef1234567890',
    'params' => ['name' => 'invalid-tool'],
    'component_type' => 'tool',
    'tool_name' => 'invalid-tool',
    'failure_reason' => 'not_found',
    'error_code' => -32002,
  ],
  'duration_ms' => 2.15
]

// Initialize request (creates new session)
[
  'event' => 'mcp.request',
  'tags' => [
    'status' => 'success',
    'method' => 'initialize',
    'transport' => 'http',
    'server_id' => 'default',
    'request_id' => 1,
    'session_id' => null, // No session yet
    'params' => ['protocolVersion' => '2025-06-18', 'client_name' => 'Bruno'],
    'new_session_id' => 'a3f2c1d4-5e6f-7890-abcd-ef1234567890', // Newly created
  ],
  'duration_ms' => 12.34
]

// Permission denied with detailed WP_Error
[
  'event' => 'mcp.request',
  'tags' => [
    'status' => 'error',
    'method' => 'tools/call',
    'transport' => 'http',
    'server_id' => 'default',
    'request_id' => 84,
    'session_id' => 'a3f2c1d4-5e6f-7890-abcd-ef1234567890',
    'params' => ['name' => 'user-notifications', 'arguments_count' => 1],
    'component_type' => 'tool',
    'tool_name' => 'user-notifications',
    'ability_name' => 'wpcom-mcp/user-notifications',
    'failure_reason' => 'ability_invalid_input', // WP_Error code used directly
    'error_code' => -32004,
  ],
  'duration_ms' => 8.51
]
```

**Note:** When WordPress abilities return `WP_Error` objects from `has_permission()`, the error code is automatically used as the `failure_reason`, providing specific context like `ability_invalid_input`, `ability_permission_error`, etc. This makes it much easier to track specific permission failure types. If a boolean `false` is returned, the generic `permission_denied` reason is used.

### Failure Reasons

The `failure_reason` tag provides specific context for errors. When WordPress abilities return `WP_Error` objects, the error code is used directly as the failure reason.

**Standard Failure Reasons:**

**Tool-related:**
- `not_found`: Tool doesn't exist
- `permission_denied`: Permission check returned false (generic)
- `permission_check_failed`: Permission callback threw exception
- `wp_error`: WordPress ability returned WP_Error during execution
- `execution_failed`: Tool execution threw exception
- `missing_parameter`: Required parameter missing
- **Any WP_Error code**: e.g., `ability_invalid_input`, `ability_permission_error`, `ability_rate_limit`, etc.

**Prompt-related:**
- `not_found`: Prompt doesn't exist
- `permission_denied`: Permission denied (generic)
- `execution_failed`: Prompt execution threw exception
- `missing_parameter`: Required parameter missing
- **Any WP_Error code**: Specific error codes from ability permission checks

**Resource-related:**
- `not_found`: Resource doesn't exist
- `permission_denied`: Permission denied (generic)
- `execution_failed`: Resource reading threw exception
- `missing_parameter`: Required parameter missing
- **Any WP_Error code**: Specific error codes from ability permission checks

**Example WP_Error Codes as Failure Reasons:**
- `ability_invalid_input`: Invalid input validation failed
- `ability_permission_error`: Specific permission issue
- `ability_rate_limit`: Rate limit exceeded
- `ability_quota_exceeded`: Quota exceeded
- Any custom error code returned by your abilities

### Component Registration Events

**Event:** `mcp.component.registration`

**Tags:**
- `status`: `success` | `failed`
- `component_type`: `tool` | `resource` | `prompt` | `ability_tool`
- `component_name`: Name of the component
- `server_id`: MCP server ID
- `error_type`: Exception class name (only for failures)

**Includes duration timing**: No

**Default Behavior**: Component registration events are **disabled by default** to avoid polluting observability logs during server startup. Use the filter below to enable them when needed.

**Examples:**
```php
// Successful tool registration
[
  'event' => 'mcp.component.registration',
  'tags' => [
    'status' => 'success',
    'component_type' => 'tool',
    'component_name' => 'create_post',
    'server_id' => 'default',
  ]
]

// Failed resource registration
[
  'event' => 'mcp.component.registration',
  'tags' => [
    'status' => 'failed',
    'component_type' => 'resource',
    'component_name' => 'invalid_ability',
    'error_type' => 'InvalidArgumentException',
    'server_id' => 'default',
  ]
]
```

#### Controlling Component Registration Events

Component registration events are disabled by default but can be enabled using the `mcp_adapter_observability_record_component_registration` filter:

```php
// Enable component registration events globally
add_filter('mcp_adapter_observability_record_component_registration', '__return_true');

// Enable only when debugging
add_filter('mcp_adapter_observability_record_component_registration', function($should_record) {
    return defined('WP_DEBUG') && WP_DEBUG;
});

// Enable only in development environments
add_filter('mcp_adapter_observability_record_component_registration', function($should_record) {
    return wp_get_environment_type() === 'development';
});
```

This filter is particularly useful for:
- Debugging component loading issues during development
- Troubleshooting registration failures in staging environments
- Keeping production logs clean by disabling startup noise

### Server Events

**Event:** `mcp.server.created`

**Tags:**
- `status`: `success`
- `server_id`: Server ID
- `transport_count`: Number of transports
- `tools_count`: Number of tools
- `resources_count`: Number of resources
- `prompts_count`: Number of prompts

**Includes duration timing**: No

### Common Tags

All events automatically include these tags:
- `site_id`: WordPress site ID
- `user_id`: WordPress user ID
- `timestamp`: Unix timestamp

## Helper Trait

`McpObservabilityHelperTrait` provides utility methods for handlers:

### Tag Management
- `get_default_tags()`: Default tags (site_id, user_id, timestamp)
- `sanitize_tags()`: Remove sensitive data and limit tag length
- `merge_tags()`: Combine user tags with defaults
- `format_metric_name()`: Ensure consistent metric naming with 'mcp.' prefix

### Error Handling
- `categorize_error()`: Classify exceptions into standard categories

```php
use WP\MCP\Infrastructure\Observability\McpObservabilityHelperTrait;

class MyHandler implements McpObservabilityHandlerInterface {
    use McpObservabilityHelperTrait;
    
    public function record_event(string $event, array $tags = [], ?float $duration_ms = null): void {
        $formatted_event = self::format_metric_name($event);
        $merged_tags = self::merge_tags($tags);
        // ... send to your system with optional timing: $duration_ms
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
    
    public function record_event(string $event, array $tags = [], ?float $duration_ms = null): void {
        $formatted_event = self::format_metric_name($event);
        $merged_tags = self::merge_tags($tags);
        
        // Include timing if provided
        $timing_info = $duration_ms !== null ? sprintf(' %.2fms', $duration_ms) : '';
        $log_entry = sprintf('[MCP Event] %s%s | Tags: %s', 
            $formatted_event,
            $timing_info,
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
    
    public function record_event(string $event, array $tags = [], ?float $duration_ms = null): void {
        $payload = [
            'type' => 'event',
            'name' => self::format_metric_name($event),
            'tags' => self::merge_tags($tags),
            'site' => get_site_url()
        ];
        
        // Include duration if provided
        if ($duration_ms !== null) {
            $payload['duration_ms'] = $duration_ms;
        }
        
        wp_remote_post('https://metrics.example.com/api/events', [
            'body' => wp_json_encode($payload),
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

## Querying Events

With the unified event structure, you can easily query and analyze metrics:

### Success Rate by Method

```sql
SELECT 
  tags->>'method' as method,
  SUM(CASE WHEN tags->>'status' = 'success' THEN 1 ELSE 0 END) * 100.0 / COUNT(*) as success_rate
FROM mcp_events
WHERE event = 'mcp.request'
GROUP BY tags->>'method'
```

### Tool Performance

```sql
SELECT 
  tags->>'tool_name' as tool_name,
  AVG(duration_ms) as avg_duration,
  COUNT(*) as call_count
FROM mcp_events
WHERE event = 'mcp.request' 
  AND tags->>'component_type' = 'tool'
  AND tags->>'status' = 'success'
GROUP BY tags->>'tool_name'
ORDER BY call_count DESC
```

### Failure Analysis

```sql
SELECT 
  tags->>'failure_reason' as reason,
  tags->>'error_category' as category,
  COUNT(*) as count
FROM mcp_events
WHERE event = 'mcp.request' 
  AND tags->>'status' = 'error'
GROUP BY tags->>'failure_reason', tags->>'error_category'
ORDER BY count DESC
```

## Best Practices

1. **Use Status for Filtering**: Query by `status` tag to separate successes from failures
2. **Group by Event Name**: All requests use `mcp.request`, making aggregation simple
3. **Leverage Failure Reasons**: Use `failure_reason` for detailed error analysis
4. **Monitor Duration**: Track performance trends using the duration field
5. **Alert on Patterns**: Set up alerts for specific failure_reason values
6. **Context-Rich Logging**: Handler metadata provides component-specific context automatically
