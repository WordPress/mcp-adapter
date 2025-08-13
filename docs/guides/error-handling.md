# Error Handling

This guide covers the error handling system in the MCP Adapter. The system uses an interface-based approach that separates error logging from error response creation, making it easy to integrate with existing monitoring systems.

## Table of Contents

1. [System Overview](#system-overview)
2. [Error Handler Interface](#error-handler-interface)
3. [Error Factory for Response Creation](#error-factory-for-response-creation)
4. [Built-in Error Handlers](#built-in-error-handlers)
5. [Creating Custom Error Handlers](#creating-custom-error-handlers)
6. [Advanced Integration Examples](#advanced-integration-examples)

## System Overview

The MCP Adapter uses an interface-based error handling system that separates concerns:

- **Error Logging**: Handled by implementations of `McpErrorHandlerInterface`
- **Error Response Creation**: Handled by the static `McpErrorFactory` class
- **Error Response Formatting**: Handled directly by transport classes

### Key Benefits

✅ **Flexible Architecture**: Interface allows multiple implementations  
✅ **Clean Separation**: Error creation vs. error logging separated  
✅ **Easy Testing**: Can mock interfaces easily  
✅ **Dependency Injection**: Can inject different handlers based on environment  
✅ **SOLID Principles**: Follows Interface Segregation and Dependency Inversion  

### Architecture Overview

```php
use WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface;
use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;

// Error logging (for monitoring/debugging)
interface McpErrorHandlerInterface {
    public function log(string $message, array $context = [], string $type = 'error'): void;
}

// Error response creation (for clients)
class McpErrorFactory {
    public static function missing_parameter(int $id, string $parameter): array;
    public static function tool_not_found(int $id, string $tool): array;
    // ... other error types
}
```

## Error Handler Interface

All error handlers implement the simple `McpErrorHandlerInterface`:

```php
use WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface;

interface McpErrorHandlerInterface {
    /**
     * Log an error message with optional context and type.
     *
     * @param string $message The log message.
     * @param array  $context Additional context data.
     * @param string $type The log type (e.g., 'error', 'info', 'debug').
     */
    public function log(string $message, array $context = [], string $type = 'error'): void;
}
```

### Context Information

Error handlers receive rich context information:

```php
$context = [
    'tool_name' => 'my-plugin/create-post',
    'user_id' => 123,
    'request_id' => 'req_12345',
    'ability_name' => 'my-plugin/create-post',
    'exception' => 'Exception message...',
    'timestamp' => '2024-01-15T10:30:00Z',
    'server_id' => 'content-server'
];
```

## Error Factory for Response Creation

The `McpErrorFactory` class provides static methods to create standardized JSON-RPC error responses:

### Available Error Types

```php
// Standard JSON-RPC errors
McpErrorFactory::parse_error(int $id, string $details = ''): array
McpErrorFactory::invalid_request(int $id, string $details = ''): array
McpErrorFactory::method_not_found(int $id, string $method): array
McpErrorFactory::invalid_params(int $id, string $details = ''): array
McpErrorFactory::internal_error(int $id, string $details = ''): array

// MCP-specific errors
McpErrorFactory::missing_parameter(int $id, string $parameter): array
McpErrorFactory::tool_not_found(int $id, string $tool): array
McpErrorFactory::resource_not_found(int $id, string $resource): array
McpErrorFactory::prompt_not_found(int $id, string $prompt): array
McpErrorFactory::permission_denied(int $id, string $details = ''): array
McpErrorFactory::unauthorized(int $id, string $details = ''): array
McpErrorFactory::mcp_disabled(int $id): array
```

### Error Response Format

All factory methods return standardized JSON-RPC 2.0 error responses:

```php
$error = McpErrorFactory::tool_not_found(123, 'missing-tool');
// Returns:
[
    'jsonrpc' => '2.0',
    'id' => 123,
    'error' => [
        'code' => -32003,
        'message' => 'Tool not found: missing-tool'
    ]
]
```

### Error Codes

The system uses standard JSON-RPC error codes:

```php
// Standard JSON-RPC codes (-32768 to -32000)
const PARSE_ERROR      = -32700;
const INVALID_REQUEST  = -32600;
const METHOD_NOT_FOUND = -32601;
const INVALID_PARAMS   = -32602;
const INTERNAL_ERROR   = -32603;

// MCP-specific codes (-32000 to -32099)
const MCP_DISABLED       = -32000;
const MISSING_PARAMETER  = -32001;
const RESOURCE_NOT_FOUND = -32002;
const TOOL_NOT_FOUND     = -32003;
const PROMPT_NOT_FOUND   = -32004;
const PERMISSION_DENIED  = -32008;
const UNAUTHORIZED       = -32010;
```

## Built-in Error Handlers

### ErrorLogMcpErrorHandler

Logs errors to PHP's error log with structured context:

```php
use WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface;
use WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler;

class ErrorLogMcpErrorHandler implements McpErrorHandlerInterface {
    public function log(string $message, array $context = [], string $type = 'error'): void {
        $user_id = function_exists('get_current_user_id') ? get_current_user_id() : 0;
        $log_message = sprintf(
            '[%s] %s | Context: %s | User ID: %d',
            strtoupper($type),
            $message,
            wp_json_encode($context),
            $user_id
        );
        error_log($log_message);
    }
}
```

### NullMcpErrorHandler

No-op handler for environments where logging is not desired:

```php
use WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface;
use WP\MCP\Infrastructure\ErrorHandling\NullMcpErrorHandler;

class NullMcpErrorHandler implements McpErrorHandlerInterface {
    public function log(string $message, array $context = [], string $type = 'error'): void {
        // Do nothing
    }
}
```

### Using Built-in Handlers

```php
add_action('mcp_adapter_init', function($adapter) {
    $adapter->create_server(
        'my-server',
        'my-plugin',
        'mcp',
        'My MCP Server',
        'Description',
        '1.0.0',
        [\WP\MCP\Transport\Http\RestTransport::class],
        \WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class, // Error handler
        [\WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class],
        ['my-plugin/my-tool']
    );
});
```

## Creating Custom Error Handlers

### Simple Custom Handler

Create a custom handler by implementing the interface:

```php
<?php
use WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface;

class MyCustomErrorHandler implements McpErrorHandlerInterface {
    
    public function log(string $message, array $context = [], string $type = 'error'): void {
        // Log to custom file
        $log_entry = sprintf(
            '[%s] %s | Context: %s',
            strtoupper($type),
            $message,
            wp_json_encode($context)
        );
        
        file_put_contents(
            WP_CONTENT_DIR . '/mcp-errors.log',
            $log_entry . "\n",
            FILE_APPEND | LOCK_EX
        );
        
        // Also send to external service (optional)
        if ( function_exists( 'my_monitoring_service' ) ) {
            my_monitoring_service( $message, $context, $type );
        }
    }
}
```

### External Service Integration

Simple integration with external monitoring:

```php
<?php
use WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface;

class ExternalServiceErrorHandler implements McpErrorHandlerInterface {
    
    public function log(string $message, array $context = [], string $type = 'error'): void {
        // Send to external service
        $this->send_to_service($message, $context, $type);
        
        // Fallback to local logging
        error_log("[MCP {$type}] {$message}");
    }
    
    private function send_to_service(string $message, array $context, string $type): void {
        $data = [
            'message' => $message,
            'context' => $context,
            'level' => $type,
            'timestamp' => time(),
            'site' => get_site_url()
        ];
        
        wp_remote_post('https://your-monitoring-service.com/api/errors', [
            'body' => wp_json_encode($data),
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . get_option('monitoring_api_key')
            ],
            'timeout' => 5
        ]);
    }
}
```

## Advanced Integration Examples

### Multi-Handler Setup

Route different error types to different handlers:

```php
class MultiErrorHandler implements McpErrorHandlerInterface {
    
    public function log(string $message, array $context = [], string $type = 'error'): void {
        // Always log to file
        error_log("[MCP {$type}] {$message}");
        
        // Send critical errors to external service
        if ($type === 'critical') {
            $this->send_to_external_service($message, $context);
        }
        
        // Send errors to email for important tools
        if (isset($context['tool_name']) && $this->is_important_tool($context['tool_name'])) {
            $this->send_email_alert($message, $context);
        }
    }
    
    private function send_to_external_service(string $message, array $context): void {
        wp_remote_post('https://monitoring.example.com/api/alerts', [
            'body' => wp_json_encode(['message' => $message, 'context' => $context]),
            'headers' => ['Content-Type' => 'application/json']
        ]);
    }
    
    private function send_email_alert(string $message, array $context): void {
        wp_mail(
            get_option('admin_email'),
            'MCP Error Alert',
            "Error: {$message}\nContext: " . wp_json_encode($context)
        );
    }
    
    private function is_important_tool(string $tool_name): bool {
        $important_tools = ['payment-processor', 'user-manager', 'security-scanner'];
        return in_array($tool_name, $important_tools);
    }
}
```



## Testing Your Error Handlers

### Simple Testing

Test your error handler with basic verification:

```php
// Test that your handler logs correctly
$handler = new MyCustomErrorHandler();
$handler->log('Test error message', ['tool_name' => 'test-tool'], 'error');

// Check that the log file was created
$log_file = WP_CONTENT_DIR . '/mcp-errors.log';
if (file_exists($log_file)) {
    echo "Error handler working correctly";
}
```

### Error Factory Testing

Verify error responses have the correct format:

```php
// Test error factory output
$error = McpErrorFactory::tool_not_found(123, 'missing-tool');

// Should return proper JSON-RPC error format
assert($error['jsonrpc'] === '2.0');
assert($error['id'] === 123);
assert($error['error']['code'] === -32003);
assert(strpos($error['error']['message'], 'missing-tool') !== false);
```

This error handling system provides a clean, flexible foundation for building robust MCP integrations.

## Next Steps

- **Configure [Transport Permissions](transport-permissions.md)** for custom authentication with robust error handling
- **Review [Architecture Overview](../architecture/overview.md)** to understand how error handling fits into the overall system
- **Explore [Testing Guide](testing.md)** for comprehensive testing strategies
- **Check [Troubleshooting Guide](../troubleshooting/common-issues.md)** for debugging help
- **See [Creating Abilities](creating-abilities.md)** for complete implementations