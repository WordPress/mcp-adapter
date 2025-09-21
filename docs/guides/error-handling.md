# Error Handling

The MCP Adapter uses a two-part error handling system that separates error logging from error response creation.

## System Overview

The error handling system has two main components:

- **Error Logging**: `McpErrorHandlerInterface` implementations log errors for monitoring
- **Error Response Creation**: `McpErrorFactory` creates standardized JSON-RPC error responses

```php
use WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface;
use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;

// Error logging
interface McpErrorHandlerInterface {
    public function log(string $message, array $context = [], string $type = 'error'): void;
}

// Error response creation
class McpErrorFactory {
    public static function tool_not_found(int $id, string $tool): array;
    public static function missing_parameter(int $id, string $parameter): array;
    // ... other error types
}
```

## Error Handler Interface

Error handlers implement `McpErrorHandlerInterface`:

```php
interface McpErrorHandlerInterface {
    public function log(string $message, array $context = [], string $type = 'error'): void;
}
```

The `log()` method receives:
- `$message`: Error description
- `$context`: Additional data (tool name, user ID, etc.)
- `$type`: Log level ('error', 'info', 'debug')

## Error Factory

`McpErrorFactory` creates standardized JSON-RPC error responses:

### Common Error Methods

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
McpErrorFactory::ability_not_found(int $id, string $ability): array
McpErrorFactory::resource_not_found(int $id, string $resource_uri): array
McpErrorFactory::prompt_not_found(int $id, string $prompt): array
McpErrorFactory::permission_denied(int $id, string $details = ''): array
McpErrorFactory::unauthorized(int $id, string $details = ''): array
McpErrorFactory::mcp_disabled(int $id): array
McpErrorFactory::validation_error(int $id, string $details): array
```

### Error Response Format

All methods return JSON-RPC 2.0 error responses:

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

Standard JSON-RPC and MCP-specific error codes:

```php
// Standard JSON-RPC codes
const PARSE_ERROR      = -32700;
const INVALID_REQUEST  = -32600;
const METHOD_NOT_FOUND = -32601;
const INVALID_PARAMS   = -32602;
const INTERNAL_ERROR   = -32603;

// MCP-specific codes (-32000 to -32099)
const SERVER_ERROR       = -32000; // Generic server error (includes MCP disabled)
const TIMEOUT_ERROR      = -32001; // Request timeout
const RESOURCE_NOT_FOUND = -32002; // Resource not found
const TOOL_NOT_FOUND     = -32003; // Tool not found
const PROMPT_NOT_FOUND   = -32004; // Prompt not found
const PERMISSION_DENIED  = -32008; // Access denied
const UNAUTHORIZED       = -32010; // Authentication required
```

### HTTP Status Mapping

The factory includes methods to map JSON-RPC error codes to HTTP status codes:

```php
// Get HTTP status for error response
$http_status = McpErrorFactory::get_http_status_for_error($error_response);

// Direct mapping
$http_status = McpErrorFactory::mcp_error_to_http_status(-32003); // Returns 404
```

## Built-in Error Handlers

### ErrorLogMcpErrorHandler

Logs errors to PHP error log with structured context and user information:

```php
$handler = new ErrorLogMcpErrorHandler();
$handler->log('Tool execution failed', ['tool_name' => 'my-tool'], 'error');
// Logs: [ERROR] Tool execution failed | Context: {"tool_name":"my-tool"} | User ID: 123
```

### NullMcpErrorHandler

No-op handler that ignores all errors (useful for testing or when logging is disabled):

```php
$handler = new NullMcpErrorHandler();
$handler->log('This will not be logged', [], 'error'); // Does nothing
```

## Creating Custom Error Handlers

Implement the `McpErrorHandlerInterface` to create custom error handlers:

### File-based Handler

```php
use WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface;

class FileErrorHandler implements McpErrorHandlerInterface {
    public function log(string $message, array $context = [], string $type = 'error'): void {
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
    }
}
```

### External Service Handler

```php
class ExternalServiceErrorHandler implements McpErrorHandlerInterface {
    public function log(string $message, array $context = [], string $type = 'error'): void {
        wp_remote_post('https://your-monitoring-service.com/api/errors', [
            'body' => wp_json_encode([
                'message' => $message,
                'context' => $context,
                'level' => $type,
                'site' => get_site_url()
            ]),
            'headers' => ['Content-Type' => 'application/json'],
            'timeout' => 5
        ]);
        
        // Fallback to local logging
        error_log("[MCP {$type}] {$message}");
    }
}
```

## Usage in Practice

### Handler Helper Trait

Most handlers use the `HandlerHelperTrait` which provides convenience methods:

```php
use WP\MCP\Handlers\HandlerHelperTrait;

class MyHandler {
    use HandlerHelperTrait;
    
    public function handle_request($request) {
        // Create error responses easily
        if (!$this->validate_params($request)) {
            return $this->missing_parameter_error('required_param', $request['id']);
        }
        
        // Handle other errors
        if (!$this->check_permissions()) {
            return $this->permission_denied_error('resource_access', $request['id']);
        }
    }
}
```

### HTTP Transport Integration

The HTTP transport automatically maps error codes to HTTP status codes:

```php
// In transport handlers
$error_response = McpErrorFactory::tool_not_found(123, 'missing-tool');
$http_status = McpErrorFactory::get_http_status_for_error($error_response); // Returns 404

return new WP_REST_Response($error_response, $http_status);
```

### JSON-RPC Message Validation

The factory includes message validation for proper JSON-RPC structure:

```php
$validation_result = McpErrorFactory::validate_jsonrpc_message($request);
if (is_array($validation_result)) {
    // Validation failed, $validation_result contains error response
    return new WP_REST_Response($validation_result, 400);
}
// Validation passed
```