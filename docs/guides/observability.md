# Observability

This guide covers basic observability functionality in the MCP Adapter, focusing on transport layer monitoring. The
observability system tracks request metrics, performance data, and error patterns to provide insights into MCP adapter
usage and performance.

## Table of Contents

1. [Overview](#overview)
2. [Available Handlers](#available-handlers)
3. [Metrics Tracked](#metrics-tracked)
4. [Configuration](#configuration)
5. [Creating Custom Handlers](#creating-custom-handlers)
6. [Integration Examples](#integration-examples)

## Overview

The MCP Adapter includes a comprehensive observability system that tracks metrics and events throughout the MCP request lifecycle. This system follows the same interface pattern as the error handling system, providing a clean abstraction that can be implemented with custom observability handlers.

### Key Features

- **Zero-overhead when disabled**: The `NullMcpObservabilityHandler` provides no-op implementations
- **Built-in logging**: The `ErrorLogMcpObservabilityHandler` logs events to PHP error log
- **Extensible design**: Implement the interface for integration with monitoring systems
- **Comprehensive tracking**: Tracks requests, component lifecycle, errors, and performance
- **Enhanced error tracking**: Detailed error categorization and failure analysis
- **Event emission pattern**: Emits structured events for external aggregation systems

## Available Handlers

### NullMcpObservabilityHandler

The default observability handler that provides zero-overhead no-op implementations. Use this when observability
tracking is not needed.

```php
use WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler;

// This handler does nothing - zero overhead
$observability_handler = NullMcpObservabilityHandler::class;
```

### ErrorLogMcpObservabilityHandler

A simple handler that logs observability metrics to the PHP error log. Useful for development and basic production
monitoring.

```php
use WP\MCP\Infrastructure\Observability\ErrorLogMcpObservabilityHandler;

// This handler logs metrics to error_log()
$observability_handler = ErrorLogMcpObservabilityHandler::class;
```

This handler implements `McpObservabilityHandlerInterface` and uses the `McpObservabilityHelperTrait` for shared utility methods.

Example log output:

```
[MCP Observability] EVENT mcp.request.count [method=tools/call,transport=rest,site_id=1,user_id=123]
[MCP Observability] EVENT mcp.tool.execution_success [tool_name=get-posts,server_id=blog-tools]
[MCP Observability] EVENT mcp.tool.execution_failed [tool_name=bad-tool,error_type=RuntimeException,error_category=execution]
[MCP Observability] EVENT mcp.component.registered [component_type=tool,component_name=my-plugin/get-posts]
[MCP Observability] TIMING mcp.request.duration 45.23ms [method=tools/call,transport=rest,site_id=1,user_id=123]
```

## Events and Metrics Tracked

The observability system currently tracks the following metrics at the transport layer:

### Request Metrics

- **mcp.request.count** - Total number of requests processed
- **mcp.request.success** - Number of successful requests
- **mcp.request.error** - Number of failed requests with error details

### Performance Metrics

- **mcp.request.duration** - Request processing time in milliseconds

### Component Lifecycle Events (New)

- **mcp.component.registered** - Successful component (tool/resource/prompt) registration
- **mcp.component.registration_failed** - Failed component registration attempts
- **mcp.server.created** - MCP server creation events

### Tool Operation Events (New)

- **mcp.tool.not_found** - Tool lookup failures
- **mcp.tool.permission_denied** - Permission denied for tool access
- **mcp.tool.permission_check_failed** - Errors during permission validation
- **mcp.tool.execution_success** - Successful tool executions
- **mcp.tool.execution_failed** - Tool execution failures

### Enhanced Error Tracking (New)

All error events include standardized categorization:
- **error_type** - Specific exception class name (e.g., RuntimeException, InvalidArgumentException)
- **error_category** - General category (validation, execution, logic, system, type, arguments, unknown)
- **error_message_hash** - Hash for grouping similar errors

### Metric Tags

All metrics include the following tags for filtering and aggregation:

- **method** - The MCP method being called (e.g., `tools/call`, `resources/list`)
- **transport** - The transport type (e.g., `rest`, `streamable`)
- **site_id** - WordPress site ID (for multisite environments)
- **user_id** - WordPress user ID making the request
- **timestamp** - Unix timestamp when the metric was recorded
- **error_type** - Exception class name (for error events only)
- **tool_name** - Name of the tool being accessed (for tool events)
- **component_type** - Type of component (tool, resource, prompt)
- **component_name** - Name of the component being registered
- **server_id** - ID of the MCP server handling the request

## Configuration

### Transport-Level Configuration

Currently, observability handlers are configured at the transport level. By default, all transports use the
`NullMcpObservabilityHandler` (disabled).

```php
// The observability handler is set as a property on AbstractMcpTransport
protected string $observability_handler = NullMcpObservabilityHandler::class;
```

### Future Configuration Options

Future versions will include WordPress options for easier configuration:

```php
// Planned configuration options
add_option('mcp_observability_enabled', false);
add_option('mcp_observability_handler', 'NullMcpObservabilityHandler');
```

## Creating Custom Handlers

To create custom observability handlers, implement the `McpObservabilityHandlerInterface` interface:

```php
<?php
use WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface;
use WP\MCP\Infrastructure\Observability\McpObservabilityHelperTrait;

class CustomMcpObservabilityHandler implements McpObservabilityHandlerInterface {
    
    use McpObservabilityHelperTrait;
    
    public static function record_event(string $event, array $tags = []): void {
        // Log to custom file
        $log_entry = sprintf(
            '[MCP Event] %s | Tags: %s',
            $event,
            wp_json_encode($tags)
        );
        
        file_put_contents(
            WP_CONTENT_DIR . '/mcp-metrics.log',
            $log_entry . "\n",
            FILE_APPEND | LOCK_EX
        );
    }
    
    public static function record_timing(string $metric, float $duration_ms, array $tags = []): void {
        // Log timing data
        $log_entry = sprintf(
            '[MCP Timing] %s: %.2fms | Tags: %s',
            $metric,
            $duration_ms,
            wp_json_encode($tags)
        );
        
        file_put_contents(
            WP_CONTENT_DIR . '/mcp-metrics.log',
            $log_entry . "\n",
            FILE_APPEND | LOCK_EX
        );
    }
}
```

### Helper Methods Available

The `McpObservabilityHelperTrait` provides several helpful methods:

- `format_metric_name()` - Ensures consistent metric naming with 'mcp.' prefix
- `sanitize_tags()` - Removes sensitive data from tags and limits length
- `get_default_tags()` - Provides default tags (site_id, user_id, timestamp)
- `merge_tags()` - Combines user tags with default tags and sanitizes them
- `record_error_event()` - Standardized error event emission with categorization
- `categorize_error()` - Classifies exceptions into standard categories

### Interface Methods

The `McpObservabilityHandlerInterface` defines these required methods:

- `record_event(string $event, array $tags = [])` - Emit a countable event
- `record_timing(string $metric, float $duration_ms, array $tags = [])` - Record timing measurement

## Event Emission Pattern

The MCP Adapter uses an **event emission pattern** rather than local aggregation:

### How It Works

1. **MCP Adapter Role**: Emits structured events to handlers
2. **Handler Role**: Sends events to external systems (logs, StatsD, Prometheus, etc.)
3. **External System Role**: Aggregates, counts, and analyzes events

### Benefits

- ✅ **Zero Memory Overhead**: No local counters or persistent state
- ✅ **Flexible Backends**: Works with any observability system
- ✅ **WordPress-Friendly**: No database writes or resource concerns
- ✅ **Industry Standard**: Follows StatsD/OpenTelemetry patterns
- ✅ **Scalable**: Handles high-volume event emission efficiently

### Important Notes

- `record_event()` emits an event, it doesn't store a local counter
- `record_timing()` emits a timing measurement, it doesn't store data locally
- Aggregation and analysis happen in your external observability system
- The adapter focuses on **what happened**, your system determines **how many times**

## Integration Examples

### External Service Integration

Send metrics to an external monitoring service:

```php
use WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface;
use WP\MCP\Infrastructure\Observability\McpObservabilityHelperTrait;

class ExternalServiceObservabilityHandler implements McpObservabilityHandlerInterface {
    
    use McpObservabilityHelperTrait;
    
    public static function record_event(string $event, array $tags = []): void {
        $data = [
            'type' => 'event',
            'name' => $event,
            'tags' => $tags,
            'timestamp' => time(),
            'site' => get_site_url()
        ];
        
        wp_remote_post('https://metrics.example.com/api/events', [
            'body' => wp_json_encode($data),
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . get_option('metrics_api_key')
            ],
            'timeout' => 5
        ]);
    }
    
    public static function record_timing(string $metric, float $duration_ms, array $tags = []): void {
        $data = [
            'type' => 'timing',
            'name' => $metric,
            'duration' => $duration_ms,
            'tags' => $tags,
            'timestamp' => time(),
            'site' => get_site_url()
        ];
        
        wp_remote_post('https://metrics.example.com/api/timings', [
            'body' => wp_json_encode($data),
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . get_option('metrics_api_key')
            ],
            'timeout' => 5
        ]);
    }
}
```

### Database Storage

Store metrics in WordPress database:

```php
class DatabaseObservabilityHandler implements McpObservabilityHandlerInterface {
    
    public static function record_event(string $event, array $tags = []): void {
        global $wpdb;
        
        $wpdb->insert(
            $wpdb->prefix . 'mcp_events',
            [
                'event_name' => $event,
                'tags' => wp_json_encode($tags),
                'timestamp' => current_time('mysql')
            ]
        );
    }
    
    public static function record_timing(string $metric, float $duration_ms, array $tags = []): void {
        global $wpdb;
        
        $wpdb->insert(
            $wpdb->prefix . 'mcp_timings',
            [
                'metric_name' => $metric,
                'duration_ms' => $duration_ms,
                'tags' => wp_json_encode($tags),
                'timestamp' => current_time('mysql')
            ]
        );
    }
}
```

## Troubleshooting

### Common Issues

**Metrics not appearing**
- Check that your observability handler is properly configured
- Verify error logging is enabled in PHP
- Ensure MCP requests are actually being processed

**Performance concerns**
- Use `NullMcpObservabilityHandler` to disable observability
- Keep custom handlers lightweight
- Use async processing for external API calls

### Testing Your Handler

Simple test to verify your observability handler works:

```php
// Test your custom handler
$handler = new CustomMcpObservabilityHandler();
$handler::record_event('test.event', ['test' => 'value']);
$handler::record_timing('test.timing', 123.45, ['test' => 'value']);

// Check your log file or external service for the data
```

### Debug Output

Enable the built-in error log handler to see what metrics are being tracked:

```bash
# Watch the error log for MCP observability entries
tail -f /path/to/error.log | grep "MCP Observability"
```
