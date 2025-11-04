# Troubleshooting Guide

Common issues and quick solutions for the MCP Adapter.

## Quick Fixes

### MCP Adapter Not Found
```bash
# Check plugin is active
wp plugin status mcp-adapter

# If using Composer, verify Jetpack autoloader
ls vendor/autoload_packages.php
```

### REST API 404 Errors
```bash
# Check WordPress REST API works
curl "https://yoursite.com/wp-json/"

# Check permalinks (must not be "Plain")
wp option get permalink_structure
```

### Permission Denied
```bash
# Test with admin user
echo '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' | wp mcp-adapter serve --user=admin --server=mcp-adapter-default-server

# Check user capabilities
wp user list --fields=ID,user_login,roles
```

## Installation Issues

### Plugin Not Active
```bash
# Activate the plugin
wp plugin activate mcp-adapter

# Check status
wp plugin status mcp-adapter
```

### Composer Dependencies Missing
```bash
# Install dependencies including Jetpack Autoloader
cd wp-content/plugins/mcp-adapter
composer require automattic/jetpack-autoloader
composer install

# Check Jetpack autoloader exists
ls vendor/autoload_packages.php
```

### Why Use Jetpack Autoloader?

**Problem**: Multiple plugins using different versions of MCP Adapter can cause conflicts:
```
Plugin A uses MCP Adapter v1.0 → loads first
Plugin B uses MCP Adapter v1.2 → can't load, causes errors
```

**Solution**: Jetpack Autoloader automatically loads the **latest version**:
```
Plugin A uses MCP Adapter v1.0 + Jetpack Autoloader
Plugin B uses MCP Adapter v1.2 + Jetpack Autoloader
→ Both plugins use v1.2 (latest), no conflicts
```

**Benefits**:
- ✅ **Prevents version conflicts** between plugins
- ✅ **Automatic latest version** loading
- ✅ **WordPress optimized** for plugin environments
- ✅ **Zero configuration** needed

### Class Not Found
```php
// For Composer projects, check Jetpack autoloader
if ( ! class_exists( 'WP\MCP\Core\McpAdapter' ) ) {
    // Load Jetpack autoloader
    if ( is_file( __DIR__ . '/vendor/autoload_packages.php' ) ) {
        require_once __DIR__ . '/vendor/autoload_packages.php';
    }
}

// For plugin usage, check plugin is active
if ( ! class_exists( 'WP\MCP\Core\McpAdapter' ) ) {
    add_action( 'admin_notices', function() {
        echo '<div class="notice notice-error"><p>MCP Adapter plugin must be active.</p></div>';
    });
    return;
}
```

### Abilities API Missing
```php
// Check in your plugin
if ( ! function_exists( 'wp_register_ability' ) ) {
    add_action( 'admin_notices', function() {
        echo '<div class="notice notice-error"><p>WordPress Abilities API is required.</p></div>';
    });
    return;
}
```

## Server Issues

### Server Not Creating
```php
// Debug server creation
add_action( 'mcp_adapter_init', function( $adapter ) {
    error_log( 'MCP Adapter init fired' );
    
    try {
        $adapter->create_server(
            'test-server',
            'test',
            'mcp',
            'Test Server',
            'Testing server creation',
            '1.0.0',
            [ \WP\MCP\Transport\HttpTransport::class ],
            \WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
            []
        );
        error_log( 'Server created successfully' );
    } catch ( Exception $e ) {
        error_log( 'Server creation failed: ' . $e->getMessage() );
    }
});
```

### Check Registered Servers
```php
// List all servers
add_action( 'init', function() {
    if ( class_exists( 'WP\MCP\Core\McpAdapter' ) ) {
        $adapter = \WP\MCP\Core\McpAdapter::instance();
        $servers = $adapter->get_servers();
        error_log( 'MCP servers: ' . implode( ', ', array_keys( $servers ) ) );
    }
}, 999 );
```

### REST API Not Working
```bash
# Check permalinks (must not be "Plain")
wp option get permalink_structure

# Test basic REST API
curl "https://yoursite.com/wp-json/"

# List MCP routes
wp rest list | grep mcp
```

### Routes Not Found
```php
// Check registered routes
add_action( 'rest_api_init', function() {
    $routes = rest_get_server()->get_routes();
    $mcp_routes = array_filter( array_keys( $routes ), function( $route ) {
        return strpos( $route, '/mcp' ) !== false;
    });
    error_log( 'MCP routes: ' . implode( ', ', $mcp_routes ) );
}, 999 );
```

## Permission Issues

### 401 Unauthorized
```bash
# Test with authentication
curl -X POST "https://yoursite.com/wp-json/mcp/mcp-adapter-default-server" \
  --user "username:application_password" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'

# Check user is logged in
wp user get admin --field=ID
```

### 403 Forbidden
```php
// Debug user capabilities
add_action( 'wp_loaded', function() {
    $user = wp_get_current_user();
    if ( $user->ID ) {
        error_log( sprintf( 'User %d capabilities: %s', 
            $user->ID, 
            implode( ', ', array_keys( $user->allcaps ) ) 
        ));
    }
});
```

### Test Permission Callback
```php
// Temporarily allow all users for testing
function(): bool {
    error_log( 'Permission check for user: ' . get_current_user_id() );
    return is_user_logged_in(); // Very permissive
}
```

## Ability Issues

### Ability Not Found
```bash
# Check ability is registered
wp eval "var_dump(wp_get_ability('my-plugin/my-ability'));"

# List all abilities
wp eval "var_dump(array_keys(wp_get_abilities()));"
```

### Execution Errors
```php
// Debug ability execution
'execute_callback' => function( $input ) {
    error_log( 'Ability input: ' . wp_json_encode( $input ) );
    
    try {
        $result = your_operation( $input );
        error_log( 'Ability output: ' . wp_json_encode( $result ) );
        return $result;
    } catch ( Exception $e ) {
        error_log( 'Ability error: ' . $e->getMessage() );
        throw $e;
    }
}
```

### Schema Validation Errors
```php
// Test your input schema
$test_input = ['title' => 'Test'];
$ability = wp_get_ability('my-plugin/my-ability');
if ( $ability ) {
    $schema = $ability->get_input_schema();
    error_log( 'Input schema: ' . wp_json_encode( $schema ) );
}
```

## Debugging

### Enable Debug Logging
```php
// Add to wp-config.php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
```

### Check System Status
```php
// Quick system check
add_action( 'wp_loaded', function() {
    if ( current_user_can( 'manage_options' ) ) {
        $adapter = \WP\MCP\Core\McpAdapter::instance();
        error_log( sprintf(
            'MCP Status - Adapter: %s, Abilities API: %s, Servers: %d',
            class_exists( 'WP\MCP\Core\McpAdapter' ) ? 'OK' : 'MISSING',
            function_exists( 'wp_register_ability' ) ? 'OK' : 'MISSING',
            count( $adapter->get_servers() )
        ));
    }
});
```

### Log Analysis
```bash
# Watch debug log for MCP issues
tail -f wp-content/debug.log | grep MCP

# Search for recent errors
grep "MCP.*Error" wp-content/debug.log | tail -10

# Count error types
grep "MCP.*Error" wp-content/debug.log | cut -d']' -f2 | sort | uniq -c
```

### Performance Monitoring
```php
// Simple timing for abilities
'execute_callback' => function( $input ) {
    $start = microtime( true );
    
    try {
        $result = your_operation( $input );
        $duration = microtime( true ) - $start;
        
        if ( $duration > 1.0 ) {
            error_log( sprintf( 'Slow ability: %.2fs', $duration ) );
        }
        
        return $result;
    } catch ( Exception $e ) {
        error_log( 'Ability failed: ' . $e->getMessage() );
        throw $e;
    }
}
```

## Error Handler Issues

### Handler Not Working
```php
// Verify error handler interface
class MyErrorHandler implements \WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface {
    public function log(string $message, array $context = [], string $type = 'error'): void {
        error_log( "[MCP {$type}] {$message}" );
    }
}

// Check implementation
if ( ! in_array( 
    \WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface::class, 
    class_implements( MyErrorHandler::class ) 
)) {
    error_log( 'Error handler missing interface' );
}
```

### Use Error Factory
```php
// Create proper error responses
use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;

if ( empty( $params['name'] ) ) {
    return [
        'error' => McpErrorFactory::missing_parameter( $request_id, 'name' )['error']
    ];
}
```

## Common Fixes

### Permalink Issues
```bash
# Check permalink structure
wp option get permalink_structure

# If empty, set to post name
wp option update permalink_structure "/%postname%/"
```

### Memory Limits
```php
// Increase memory for MCP operations
add_action( 'mcp_adapter_init', function() {
    if ( defined( 'REST_REQUEST' ) ) {
        ini_set( 'memory_limit', '512M' );
        ini_set( 'max_execution_time', 300 );
    }
});
```

### Cache Issues
```php
// Clear object cache if using persistent caching
wp_cache_flush();

// Or via WP-CLI
wp cache flush
```

## Next Steps

- **[Installation Guide](../getting-started/installation.md)** - Setup verification
- **[Creating Abilities](../guides/creating-abilities.md)** - Working examples
- **[Error Handling](../guides/error-handling.md)** - Custom error management
