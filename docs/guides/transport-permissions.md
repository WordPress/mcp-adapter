# Transport Permission Callbacks

Transport permission callbacks provide custom authentication for your MCP servers. By default, servers use `is_user_logged_in()`, but you can implement custom authentication logic.

## Basic Usage

### Default Behavior (Logged-in Users)

```php
$adapter->create_server(
    'my-server',
    'my-plugin',
    'mcp',
    'My MCP Server',
    'Server description',
    '1.0.0',
    [\WP\MCP\Transport\HttpTransport::class],
    \WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
    \WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class
    ['my-plugin/tool'], // tools
    [], // resources
    [], // prompts
    // No permission callback = uses is_user_logged_in()
);
```

### Custom Permission Callback

Add a permission callback as the last parameter:

```php
// Admin-only access
$adapter->create_server(
    'admin-server',
    'my-plugin',
    'mcp-admin',
    'Admin MCP Server',
    'Admin-only server',
    '1.0.0',
    [\WP\MCP\Transport\HttpTransport::class],
    \WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
    \WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class,
    ['my-plugin/admin-tool'], // tools
    [], // resources
    [], // prompts
    function(): bool {  // Permission callback
        return current_user_can('manage_options');
    }
);
```

## Permission Callback Types

### Simple Boolean Return
```php
function(): bool {
    return current_user_can('edit_posts');
}
```

### Detailed Error Information
```php
function(): WP_Error|bool {
    if (!is_user_logged_in()) {
        return new WP_Error('not_logged_in', 'Please log in', ['status' => 401]);
    }
    
    if (!current_user_can('manage_options')) {
        return new WP_Error('insufficient_permissions', 'Admin access required', ['status' => 403]);
    }
    
    return true;
}
```

### Error Handling
- **Automatic Fallback**: Exceptions fall back to `is_user_logged_in()`
- **Error Logging**: Callback failures are logged
- **Secure Default**: Always requires authentication

## Common Patterns

### Role-Based Access
```php
// Allow editors and administrators
function(): bool {
    return current_user_can('edit_posts');
}

// Multiple roles
function(): bool {
    return current_user_can('edit_posts') || current_user_can('manage_options');
}
```

### API Key Authentication
```php
function(\WP_REST_Request $request): WP_Error|bool {
    $api_key = $request->get_header('X-API-Key');
    
    if (empty($api_key)) {
        return new WP_Error('missing_api_key', 'API key required', ['status' => 401]);
    }
    
    $valid_keys = get_option('my_plugin_api_keys', []);
    if (!in_array($api_key, $valid_keys, true)) {
        return new WP_Error('invalid_api_key', 'Invalid API key', ['status' => 403]);
    }
    
    return true;
}
```

### Time-Based Access
```php
function(): WP_Error|bool {
    if (!is_user_logged_in()) {
        return new WP_Error('not_logged_in', 'Authentication required', ['status' => 401]);
    }
    
    // Business hours (9 AM - 5 PM)
    $current_hour = (int) wp_date('H');
    
    if ($current_hour < 9 || $current_hour > 17) {
        if (!current_user_can('manage_options')) {
            return new WP_Error(
                'outside_business_hours', 
                'Access only available during business hours (9 AM - 5 PM)', 
                ['status' => 403]
            );
        }
    }
    
    return current_user_can('edit_posts');
}
```

## Two-Layer Security

MCP Adapter uses two security layers:

1. **Transport Permission** (Server-wide gatekeeper)
2. **Ability Permission** (Individual tool access)

Transport permissions act as a gatekeeper - if blocked here, users cannot access ANY abilities on that server.

### Example

```php
// Transport: Allow editors and admins
$adapter->create_server(
    'content-server',
    'my-plugin',
    'mcp-content',
    'Content Server',
    'Content management server',
    '1.0.0',
    [\WP\MCP\Transport\HttpTransport::class],
    \WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
    \WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class,
    ['my-plugin/edit-post', 'my-plugin/delete-post'],
    [], // resources
    [], // prompts
    function(): bool {
        // Transport: Allow editors and admins
        return current_user_can('edit_posts');
    }
);

// Individual abilities check specific permissions:
wp_register_ability('my-plugin/edit-post', [
    'permission_callback' => function($args) {
        // Ability: Check if user can edit THIS specific post
        return current_user_can('edit_post', $args['post_id']);
    },
    // ...
]);
```

## Best Practices

### Keep Callbacks Fast
```php
// ✅ Good: Simple and direct
function(): bool {
    return current_user_can('edit_posts');
}

// ❌ Avoid: Complex operations that slow requests
function(): bool {
    return $this->check_remote_api() && $this->complex_calculation();
}
```

### Use Broadest Capability
Set transport permissions to the **broadest capability** needed by any ability on the server:

```php
// ✅ Good: Transport allows editors, abilities decide specifics
function(): bool {
    return current_user_can('edit_posts'); // Broadest capability needed
}
```

## Testing

Test permission callbacks with different user roles:

```bash
# Test as admin
echo '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}' | wp mcp-adapter serve --user=admin --server=admin-server

# Test as editor (should fail for admin-only server)
echo '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}' | wp mcp-adapter serve --user=editor --server=admin-server
```

## Implementation Notes

### Callback Parameters
- **HttpTransport**: Receives `\WP_REST_Request $request` parameter
- **Legacy transports**: May have different signatures
- **Return types**: `bool` or `WP_Error`

### Error Handling
- Exceptions automatically fall back to `is_user_logged_in()`
- All failures are logged with context
- Secure default behavior

## Next Steps

- **[Custom Transports](custom-transports.md)** - For complex authentication needs
- **[Error Handling](error-handling.md)** - Custom error management
- **[Creating Abilities](creating-abilities.md)** - Ability-level permissions
