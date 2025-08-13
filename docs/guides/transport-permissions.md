# Transport Permission Callbacks

This guide shows you how to implement custom authentication for your MCP servers using transport permission callbacks. Instead of relying on the default `is_user_logged_in()` check, you can implement admin-only access, API key authentication, role-based permissions, and more.

## Table of Contents

1. [Quick Start](#quick-start)
2. [Understanding Permission Callbacks](#understanding-permission-callbacks)
3. [Common Patterns](#common-patterns)
4. [Advanced Examples](#advanced-examples)
5. [Error Handling](#error-handling)
6. [Testing and Debugging](#testing-and-debugging)

## Quick Start

### Default Behavior

By default, MCP servers use `is_user_logged_in()` for authentication. This is secure but may be too permissive for admin-only tools:

```php
// Default: any logged-in user can access
McpAdapter::instance()->create_server(
    'my-server',
    'my-plugin/v1',
    'mcp',
    'My MCP Server',
    'Server description',
    '1.0.0',
    [RestTransport::class],
    null, // error handler
    null, // observability handler
    ['my-plugin/tool']
    // No permission callback = uses is_user_logged_in()
);
```

### Admin-Only Access

Add a permission callback to restrict access to administrators:

```php
// Admin-only: restrict to users with 'manage_options' capability
McpAdapter::instance()->create_server(
    'admin-server',
    'my-plugin/v1',
    'mcp-admin',
    'Admin MCP Server',
    'Admin-only server',
    '1.0.0',
    [RestTransport::class],
    null, // error handler
    null, // observability handler
    ['my-plugin/admin-tool'],
    [], // resources
    [], // prompts
    function(): bool {  // Permission callback
        return current_user_can('manage_options');
    }
);
```

That's it! Now only administrators can access your MCP server.

## Understanding Permission Callbacks

### How It Works

Permission callbacks are functions that run before every MCP request to determine if the user should have access. They replace the default `is_user_logged_in()` check with your custom logic.

**Key Features:**

- ✅ **Secure by default**: Falls back to `is_user_logged_in()` if callback fails
- ✅ **Per-server configuration**: Each server can have different permissions
- ✅ **Error handling**: Automatic fallback with detailed logging
- ✅ **Backward compatible**: Existing code continues to work

### Layered Security Model

MCP Adapter uses a **two-layer security model** for maximum flexibility and security:

```
1. 🚪 Transport Permission (Gatekeeper)
   ↓ (If allowed)
2. 🔐 Ability Permission (Individual Tool Access)
```

**Important:** Transport permissions act as a **gatekeeper**. If a user is blocked at the transport level, they cannot access ANY abilities on that server, regardless of their individual ability permissions.

#### Example: Admin-Only Transport vs. Ability Permissions

```php
// Server with admin-only transport
McpAdapter::instance()->create_server(
    'admin-server',
    'my-plugin/v1',
    'mcp-admin',
    'Admin Server',
    'Admin-only server',
    '1.0.0',
    [RestTransport::class],
    null,
    null,
    ['my-plugin/edit-post'], // This ability checks edit_posts capability
    [],
    [],
    function(): bool {
        return current_user_can('manage_options'); // ADMIN ONLY!
    }
);

// What happens:
// ❌ Editor with 'edit_posts' capability: BLOCKED at transport level
// ❌ Cannot access 'my-plugin/edit-post' ability despite having edit_posts
// ✅ Admin with 'manage_options': ALLOWED, can access all abilities
```

#### When to Use Each Layer

**Transport Layer (Gatekeeper):**
- Broad access control for the entire server
- Examples: "Admin only", "API key required", "Business hours only"
- Applied to ALL abilities on the server

**Ability Layer (Individual Tools):**
- Fine-grained permissions for specific functionality  
- Examples: "Can edit this specific post", "Can manage this category"
- Applied to individual abilities

#### Example: Proper Layering

```php
// ✅ GOOD: Transport allows editors, abilities decide specifics
McpAdapter::instance()->create_server(
    'content-server',
    'my-plugin/v1',
    'mcp-content',
    'Content Server',
    'Content management server',
    '1.0.0',
    [RestTransport::class],
    null,
    null,
    ['my-plugin/edit-post', 'my-plugin/delete-post'],
    [],
    [],
    function(): bool {
        // Transport: Allow editors and admins
        return current_user_can('edit_posts');
    }
);

// Individual abilities still check their own permissions:
wp_register_ability('my-plugin/edit-post', [
    'permission_callback' => function($args) {
        // Ability: Check if user can edit THIS specific post
        return current_user_can('edit_post', $args['post_id']);
    },
    // ...
]);

wp_register_ability('my-plugin/delete-post', [
    'permission_callback' => function($args) {
        // Ability: Check if user can delete posts
        return current_user_can('delete_posts');
    },
    // ...
]);
```

#### Common Mistake: Overly Restrictive Transport

```php
// ❌ BAD: Transport too restrictive
McpAdapter::instance()->create_server(
    'content-server',
    'my-plugin/v1',
    'mcp-content',
    'Content Server',
    'Content management',
    '1.0.0',
    [RestTransport::class],
    null,
    null,
    ['my-plugin/edit-post'],
    [],
    [],
    function(): bool {
        return current_user_can('manage_options'); // ADMIN ONLY!
    }
);

// Result: Even though edit-post ability checks edit_posts,
// editors are blocked at transport level and can't access it!
```

### Callback Signature

Your permission callback can be simple or return detailed error information:

```php
// Simple boolean return
function(): bool {
    return current_user_can('edit_posts');
}

// Detailed error information
function(): WP_Error|bool {
    if (!is_user_logged_in()) {
        return new WP_Error('not_logged_in', 'Please log in', ['status' => 401]);
    }
    
    if (!current_user_can('manage_options')) {
        return new WP_Error('insufficient_permissions', 'Admin access required', ['status' => 403]);
    }
    
    return true;
}

// Access to request object (useful for API keys)
function(?WP_REST_Request $request = null): WP_Error|bool {
    if (!$request) {
        $request = rest_get_server()->get_request();
    }
    
    $api_key = $request->get_header('X-API-Key');
    return !empty($api_key) && $this->validate_api_key($api_key);
}
```

## Common Patterns

### 1. Role-Based Access

Restrict access to specific WordPress roles:

```php
// Allow editors and administrators
$permission_callback = function(): bool {
    return current_user_can('edit_posts') || current_user_can('manage_options');
};

// Allow specific roles only
$permission_callback = function(): bool {
    $user = wp_get_current_user();
    $allowed_roles = ['administrator', 'editor', 'custom_role'];
    
    return !empty(array_intersect($user->roles, $allowed_roles));
};
```

### 2. Capability-Based Access

Use WordPress capabilities for fine-grained control:

```php
// Single capability
$permission_callback = function(): bool {
    return current_user_can('manage_options');
};

// Multiple capabilities (any one required)
$permission_callback = function(): bool {
    return current_user_can('manage_options') || 
           current_user_can('edit_others_posts') ||
           current_user_can('publish_posts');
};

// Multiple capabilities (all required)
$permission_callback = function(): bool {
    return current_user_can('edit_posts') && 
           current_user_can('upload_files') &&
           current_user_can('manage_categories');
};
```

### 3. Custom User Meta

Check custom user metadata for access control:

```php
$permission_callback = function(): bool {
    if (!is_user_logged_in()) {
        return false;
    }
    
    $user_id = get_current_user_id();
    $access_level = get_user_meta($user_id, 'mcp_access_level', true);
    
    return $access_level === 'full' || current_user_can('manage_options');
};
```

### 4. Time-Based Restrictions

Implement business hours or time-based access:

```php
$permission_callback = function(): WP_Error|bool {
    if (!is_user_logged_in()) {
        return new WP_Error('not_logged_in', 'Authentication required', ['status' => 401]);
    }
    
    // Check business hours (9 AM - 5 PM)
    $current_hour = (int) wp_date('H');
    
    if ($current_hour < 9 || $current_hour > 17) {
        // Allow admins outside business hours
        if (!current_user_can('manage_options')) {
            return new WP_Error(
                'outside_business_hours', 
                'Access only available during business hours (9 AM - 5 PM)', 
                ['status' => 403]
            );
        }
    }
    
    return current_user_can('edit_posts');
};
```

## Advanced Examples

### API Key Authentication

Implement API key-based authentication for headless access:

```php
$api_key_callback = function(?WP_REST_Request $request = null): WP_Error|bool {
    // Get request object if not provided
    if (!$request) {
        $request = rest_get_server()->get_request();
    }
    
    // Check for API key in header
    $api_key = $request ? $request->get_header('X-MCP-API-Key') : null;
    
    if (empty($api_key)) {
        return new WP_Error(
            'missing_api_key', 
            'API key required in X-MCP-API-Key header', 
            ['status' => 401]
        );
    }
    
    // Validate against stored keys
    $valid_keys = get_option('my_plugin_api_keys', []);
    if (!in_array($api_key, $valid_keys, true)) {
        return new WP_Error(
            'invalid_api_key', 
            'Invalid API key', 
            ['status' => 403]
        );
    }
    
    return true;
};

McpAdapter::instance()->create_server(
    'api-server',
    'my-plugin/v1',
    'mcp-api',
    'API MCP Server',
    'API key authentication',
    '1.0.0',
    [RestTransport::class],
    null,
    null,
    ['my-plugin/api-tool'],
    [],
    [],
    $api_key_callback
);
```

### Rate Limiting

Implement basic rate limiting:

```php
$rate_limited_callback = function(): WP_Error|bool {
    if (!is_user_logged_in()) {
        return false;
    }
    
    $user_id = get_current_user_id();
    $cache_key = "mcp_rate_limit_user_{$user_id}";
    
    // Get current request count
    $current_count = wp_cache_get($cache_key, 'mcp_rate_limits');
    
    if ($current_count === false) {
        // First request this hour
        wp_cache_set($cache_key, 1, 'mcp_rate_limits', 3600); // 1 hour
        return true;
    }
    
    // Check limits (higher for admins)
    $max_requests = current_user_can('manage_options') ? 1000 : 100;
    
    if ($current_count >= $max_requests) {
        return new WP_Error(
            'rate_limit_exceeded', 
            "Rate limit exceeded. Maximum {$max_requests} requests per hour.", 
            ['status' => 429]
        );
    }
    
    // Increment counter
    wp_cache_set($cache_key, $current_count + 1, 'mcp_rate_limits', 3600);
    return true;
};
```

### Permission Manager Class

For complex scenarios, use a class-based approach:

```php
class McpPermissionManager {
    private array $config;
    
    public function __construct(array $config = []) {
        $this->config = wp_parse_args($config, [
            'require_login' => true,
            'allowed_capabilities' => ['manage_options'],
            'allowed_roles' => [],
            'business_hours_only' => false,
            'rate_limit_per_hour' => null,
        ]);
    }
    
    public function check_permission(?WP_REST_Request $request = null): WP_Error|bool {
        // Login requirement
        if ($this->config['require_login'] && !is_user_logged_in()) {
            return new WP_Error('login_required', 'Authentication required', ['status' => 401]);
        }
        
        // Capability check
        if (!empty($this->config['allowed_capabilities'])) {
            $has_capability = false;
            foreach ($this->config['allowed_capabilities'] as $capability) {
                if (current_user_can($capability)) {
                    $has_capability = true;
                    break;
                }
            }
            
            if (!$has_capability) {
                return new WP_Error('insufficient_capabilities', 'Required capability missing', ['status' => 403]);
            }
        }
        
        // Role check
        if (!empty($this->config['allowed_roles'])) {
            $user = wp_get_current_user();
            if (empty(array_intersect($user->roles, $this->config['allowed_roles']))) {
                return new WP_Error('insufficient_role', 'Required role missing', ['status' => 403]);
            }
        }
        
        // Business hours check
        if ($this->config['business_hours_only']) {
            $hour = (int) wp_date('H');
            if ($hour < 9 || $hour > 17) {
                return new WP_Error('outside_business_hours', 'Business hours only', ['status' => 403]);
            }
        }
        
        // Rate limiting
        if ($this->config['rate_limit_per_hour']) {
            $rate_check = $this->check_rate_limit($this->config['rate_limit_per_hour']);
            if (is_wp_error($rate_check)) {
                return $rate_check;
            }
        }
        
        return true;
    }
    
    private function check_rate_limit(int $max_requests): WP_Error|bool {
        $user_id = get_current_user_id();
        $cache_key = "mcp_rate_limit_user_{$user_id}";
        
        $count = wp_cache_get($cache_key, 'mcp_rate_limits') ?: 0;
        
        if ($count >= $max_requests) {
            return new WP_Error('rate_limited', 'Rate limit exceeded', ['status' => 429]);
        }
        
        wp_cache_set($cache_key, $count + 1, 'mcp_rate_limits', 3600);
        return true;
    }
}

// Usage
$permission_manager = new McpPermissionManager([
    'allowed_capabilities' => ['edit_posts', 'manage_options'],
    'business_hours_only' => true,
    'rate_limit_per_hour' => 100,
]);

McpAdapter::instance()->create_server(
    'managed-server',
    'my-plugin/v1',
    'mcp-managed',
    'Managed Server',
    'Using permission manager class',
    '1.0.0',
    [RestTransport::class],
    null,
    null,
    ['my-plugin/tool'],
    [],
    [],
    [$permission_manager, 'check_permission']
);
```

## Error Handling

### Built-in Safety Features

The system includes automatic safety mechanisms:

1. **Automatic Fallback**: If your callback throws an exception, it falls back to `is_user_logged_in()`
2. **Error Logging**: All callback failures are logged with detailed context
3. **Generic Client Errors**: Clients see generic errors; detailed errors only appear in logs

### Graceful Error Handling

Always handle errors gracefully in your callbacks:

```php
$robust_callback = function(?WP_REST_Request $request = null): WP_Error|bool {
    try {
        // Your permission logic here
        if (!is_user_logged_in()) {
            return new WP_Error('auth_required', 'Please log in', ['status' => 401]);
        }
        
        // Complex logic that might fail
        $result = $this->check_external_service($request);
        
        return $result;
        
    } catch (Exception $e) {
        // Log the error for debugging
        error_log('Permission callback error: ' . $e->getMessage());
        
        // Return a safe fallback
        return is_user_logged_in() && current_user_can('manage_options');
    }
};
```

### Error Response Examples

Return informative errors to help users understand access requirements:

```php
$informative_callback = function(): WP_Error|bool {
    if (!is_user_logged_in()) {
        return new WP_Error(
            'not_logged_in',
            'Please log in to access this MCP server',
            ['status' => 401]
        );
    }
    
    if (!current_user_can('manage_options')) {
        return new WP_Error(
            'insufficient_permissions',
            'Administrator access required for this server',
            [
                'status' => 403,
                'required_capability' => 'manage_options'
            ]
        );
    }
    
    return true;
};
```

## Testing and Debugging

### Manual Testing

Test your permission callbacks with different user types:

```bash
# Test as admin
curl -X POST "https://yoursite.com/wp-json/my-plugin/v1/mcp-admin" \
  --user "admin:password" \
  -H "Content-Type: application/json" \
  -d '{"method": "tools/list"}'

# Test as regular user (should fail)
curl -X POST "https://yoursite.com/wp-json/my-plugin/v1/mcp-admin" \
  --user "user:password" \
  -H "Content-Type: application/json" \
  -d '{"method": "tools/list"}'

# Test API key authentication
curl -X POST "https://yoursite.com/wp-json/my-plugin/v1/mcp-api" \
  -H "X-MCP-API-Key: your-api-key" \
  -H "Content-Type: application/json" \
  -d '{"method": "tools/list"}'
```

### Debug Logging

Enable debug logging to troubleshoot permission issues:

```php
$debug_callback = function(): WP_Error|bool {
    $user = wp_get_current_user();
    
    // Log permission check for debugging
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log(sprintf(
            'MCP Permission Check - User: %d (%s), Roles: %s, Capabilities: %s',
            $user->ID,
            $user->user_login,
            implode(', ', $user->roles),
            implode(', ', array_keys($user->allcaps))
        ));
    }
    
    $has_permission = current_user_can('manage_options');
    
    if (!$has_permission) {
        error_log('MCP Permission Denied - User lacks manage_options capability');
    }
    
    return $has_permission;
};
```

### Unit Testing

Test your permission callbacks in unit tests:

```php
class TestPermissionCallbacks extends WP_UnitTestCase {
    public function test_admin_permission_callback() {
        $admin_callback = function(): bool {
            return current_user_can('manage_options');
        };
        
        // Test with admin user
        $admin_user = $this->factory->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_user);
        $this->assertTrue($admin_callback());
        
        // Test with regular user
        $user = $this->factory->user->create(['role' => 'subscriber']);
        wp_set_current_user($user);
        $this->assertFalse($admin_callback());
        
        // Test with no user
        wp_set_current_user(0);
        $this->assertFalse($admin_callback());
    }
    
    public function test_role_based_callback() {
        $role_callback = function(): bool {
            $user = wp_get_current_user();
            $allowed_roles = ['administrator', 'editor'];
            return !empty(array_intersect($user->roles, $allowed_roles));
        };
        
        // Test editor access
        $editor = $this->factory->user->create(['role' => 'editor']);
        wp_set_current_user($editor);
        $this->assertTrue($role_callback());
        
        // Test subscriber denial
        $subscriber = $this->factory->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber);
        $this->assertFalse($role_callback());
    }
}
```

## Migration from Custom Transports

If you previously created custom transport classes for authentication, you can now use permission callbacks instead:

### Before (Custom Transport)

```php
class AdminOnlyTransport extends RestTransport {
    public function check_permission(): WP_Error|bool {
        return current_user_can('manage_options');
    }
}

// Usage
McpAdapter::instance()->create_server(
    'admin-server',
    'my-plugin/v1',
    'mcp',
    'Admin Server',
    'Description',
    '1.0.0',
    [AdminOnlyTransport::class], // Custom transport class
    // ...
);
```

### After (Permission Callback)

```php
// No custom transport class needed!
McpAdapter::instance()->create_server(
    'admin-server',
    'my-plugin/v1',
    'mcp',
    'Admin Server',
    'Description',
    '1.0.0',
    [RestTransport::class], // Standard transport
    null,
    null,
    ['tool'],
    [],
    [],
    function(): bool {  // Permission callback
        return current_user_can('manage_options');
    }
);
```

**Benefits of the new approach:**
- ✅ No custom classes needed
- ✅ Cleaner, more focused code
- ✅ Better error handling and logging
- ✅ Easier to test and maintain

## Best Practices

### 1. Keep Callbacks Simple and Fast

```php
// Good: Simple and direct
$callback = function(): bool {
    return current_user_can('edit_posts');
};

// Avoid: Complex operations that slow down every request
$callback = function(): bool {
    // Don't do this: expensive API calls, database queries, etc.
    return $this->check_remote_api() && $this->complex_calculation();
};
```

### 2. Use Caching for Expensive Operations

```php
$cached_callback = function(): bool {
    $user_id = get_current_user_id();
    $cache_key = "user_mcp_permission_{$user_id}";
    
    $result = wp_cache_get($cache_key, 'permissions');
    if ($result !== false) {
        return $result;
    }
    
    // Expensive permission check
    $result = $this->expensive_permission_check();
    
    // Cache for 5 minutes
    wp_cache_set($cache_key, $result, 'permissions', 300);
    
    return $result;
};
```

### 3. Provide Clear Error Messages

```php
$clear_errors_callback = function(): WP_Error|bool {
    if (!is_user_logged_in()) {
        return new WP_Error(
            'not_logged_in',
            'Please log in to access this MCP server',
            ['status' => 401]
        );
    }
    
    if (!current_user_can('manage_options')) {
        return new WP_Error(
            'admin_required',
            'Administrator access is required for this server. Please contact your site administrator.',
            ['status' => 403]
        );
    }
    
    return true;
};
```

### 4. Consider Security Implications

```php
// Good: Principle of least privilege
$secure_callback = function(): bool {
    // Start with most restrictive check
    if (!is_user_logged_in()) {
        return false;
    }
    
    // Only allow specific capabilities
    return current_user_can('specific_capability_needed');
};

// Avoid: Overly permissive checks
$permissive_callback = function(): bool {
    // Don't do this: too broad
    return is_user_logged_in(); // Any logged-in user
};
```

### 5. Design for Your User Roles

**Consider your abilities' permission requirements when setting transport permissions:**

```php
// ✅ GOOD: Transport permission matches broadest ability permission
// Abilities: edit-post (needs edit_posts), delete-post (needs delete_posts)
// Transport: Allow users who can edit posts (delete_posts implies edit_posts)
$transport_callback = function(): bool {
    return current_user_can('edit_posts');
};

// ❌ BAD: Transport too restrictive for abilities
// Abilities: edit-post (needs edit_posts) 
// Transport: Admin only (blocks editors who should be able to edit)
$too_restrictive_callback = function(): bool {
    return current_user_can('manage_options'); // Too restrictive!
};

// ❌ BAD: Transport too permissive
// Abilities: sensitive-admin-operation (needs manage_options)
// Transport: Any logged-in user (relies entirely on ability permissions)
$too_permissive_callback = function(): bool {
    return is_user_logged_in(); // Too permissive for admin operations!
};
```

**Rule of thumb:** Set transport permissions to the **broadest capability** needed by any ability on the server, then let individual abilities handle specific permissions.

## Quick Reference: Two-Layer Security

### Security Flow

```
User Request → Transport Permission → Ability Permission → Execution
     ↓              ↓                    ↓                 ↓
   Authenticated   Gatekeeper         Fine-grained       Action
                  (Server-wide)      (Per-ability)
```

### Permission Levels

| Layer | Purpose | Example | Scope |
|-------|---------|---------|-------|
| **Transport** | Gatekeeper for entire server | `current_user_can('edit_posts')` | ALL abilities on server |
| **Ability** | Specific functionality access | `current_user_can('edit_post', $post_id)` | Individual ability |

### Common Scenarios

| Server Type | Transport Permission | Ability Examples | Result |
|-------------|---------------------|------------------|---------|
| **Admin Tools** | `manage_options` | Any admin abilities | Only admins can access |
| **Content Management** | `edit_posts` | `edit_post`, `delete_post` | Editors and admins can access |
| **Public API** | API key validation | Varies by ability | API users can access based on individual permissions |
| **Mixed Server** | `edit_posts` | Admin + editor abilities | Editors blocked from admin abilities, allowed for editor abilities |

### Remember

- 🚪 **Transport = Gatekeeper**: Blocks access to entire server
- 🔐 **Ability = Fine Control**: Controls individual tool access  
- ⚠️ **Transport blocks override ability permissions**: Editor with `edit_posts` can't access abilities on admin-only server
- ✅ **Design transport for broadest capability**: Let abilities handle specifics

## Conclusion

Transport permission callbacks provide a powerful, flexible way to implement custom authentication for your MCP servers. They integrate seamlessly with WordPress's permission system while providing excellent error handling and performance.

**Key takeaways:**

- ✅ Use permission callbacks instead of creating custom transport classes
- ✅ Start with simple capability checks like `current_user_can('manage_options')`
- ✅ Return `WP_Error` objects for detailed error information
- ✅ Keep callbacks fast and cache expensive operations
- ✅ Test with different user roles and scenarios
- ✅ Provide clear, helpful error messages

For more advanced scenarios, explore the [Custom Transports](custom-transports.md) guide or check out [Error Handling](error-handling.md) for comprehensive error management strategies.
