# Troubleshooting Guide

This guide covers common issues, solutions, and debugging techniques for the MCP Adapter. Use this as your first
resource when encountering problems.

## Table of Contents

1. [Installation Issues](#installation-issues)
2. [Server Configuration Problems](#server-configuration-problems)
3. [Authentication and Permission Errors](#authentication-and-permission-errors)
4. [Transport and Connectivity Issues](#transport-and-connectivity-issues)
5. [Ability Execution Problems](#ability-execution-problems)
6. [Error Handling Issues](#error-handling-issues)
7. [Performance Issues](#performance-issues)
8. [Debugging Techniques](#debugging-techniques)

## Installation Issues

### MCP Adapter Not Loading

**Symptoms:**

- `Class 'WP\MCP\Core\McpAdapter' not found` error
- MCP endpoints returning 404 errors
- No MCP functionality available

**Common Causes & Solutions:**

#### 1. Autoloader Not Loaded

```php
// Check if autoloader path is correct
$autoloader_path = ABSPATH . 'wp-content/lib/mcp-adapter/vendor/autoload_packages.php';
if ( ! is_file( $autoloader_path ) ) {
    error_log( 'MCP Adapter autoloader not found at: ' . $autoloader_path );
}

// Verify loading
if ( ! class_exists( 'WP\MCP\Core\McpAdapter' ) ) {
    error_log( 'MCP Adapter classes not available after loading autoloader' );
}
```

#### 2. File Permissions

```bash
# Check file permissions
ls -la wp-content/lib/mcp-adapter/
# Should show read permissions for web server user

# Fix permissions if needed
chmod -R 644 wp-content/lib/mcp-adapter/
find wp-content/lib/mcp-adapter/ -type d -exec chmod 755 {} \;
```

#### 3. Plugin Loading Order

```php
// Ensure MCP Adapter loads before your plugin
add_action( 'plugins_loaded', function() {
    // Load MCP Adapter first
    if ( ! class_exists( 'WP\MCP\Core\McpAdapter' ) ) {
        require_once ABSPATH . 'wp-content/lib/mcp-adapter/vendor/autoload_packages.php';
    }
    
    // Then initialize your MCP functionality
    if ( class_exists( 'WP\MCP\Core\McpAdapter' ) ) {
        // Your MCP setup code
    } else {
        error_log( 'MCP Adapter failed to load' );
    }
}, 5 ); // Early priority
```

### WordPress Abilities API Missing

**Symptoms:**

- `Function 'wp_register_ability' not found` error
- Abilities not registering properly

**Solutions:**

```php
// Check if Abilities API is loaded
if ( ! function_exists( 'wp_register_ability' ) ) {
    // Load Abilities API or show error
    add_action( 'admin_notices', function() {
        echo '<div class="notice notice-error"><p>';
        echo 'WordPress Abilities API is required for MCP functionality.';
        echo '</p></div>';
    });
    return;
}
```

## Server Configuration Problems

### MCP Server Not Creating

**Symptoms:**

- `mcp_adapter_init` action fires but server doesn't appear
- REST endpoints not available
- Server not listed in adapter

**Debugging Steps:**

#### 1. Check Hook Timing

```php
// Verify the action is firing
add_action( 'mcp_adapter_init', function( $adapter ) {
    error_log( 'MCP Adapter init fired with adapter: ' . get_class( $adapter ) );
    
    try {
        $server = $adapter->create_server(
            'test-server',
            'test',
            'mcp',
            'Test Server',
            'Testing server creation',
            '1.0.0',
            [ \WP\MCP\Transport\Http\RestTransport::class ],
            \WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
            []
        );
        
        error_log( 'Server created successfully: ' . $server->get_id() );
        
    } catch ( Exception $e ) {
        error_log( 'Server creation failed: ' . $e->getMessage() );
        error_log( 'Stack trace: ' . $e->getTraceAsString() );
    }
});
```

#### 2. Verify Server Registration

```php
// Check if server was registered
add_action( 'init', function() {
    if ( class_exists( 'WP\MCP\Core\McpAdapter' ) ) {
        $adapter = \WP\MCP\Core\McpAdapter::instance();
        $servers = $adapter->get_servers();
        
        error_log( 'Registered MCP servers: ' . implode( ', ', array_keys( $servers ) ) );
    }
}, 999 ); // Late priority
```

### REST Routes Not Registering

**Symptoms:**

- MCP endpoints return 404
- `wp-json/your-namespace/mcp/tools` not found

**Solutions:**

#### 1. Check Permalink Structure

```php
// Verify permalinks are not set to "Plain"
$permalink_structure = get_option( 'permalink_structure' );
if ( empty( $permalink_structure ) ) {
    add_action( 'admin_notices', function() {
        echo '<div class="notice notice-warning"><p>';
        echo 'MCP requires permalinks to be set to something other than "Plain". ';
        echo '<a href="' . admin_url( 'options-permalink.php' ) . '">Update permalinks</a>';
        echo '</p></div>';
    });
}
```

#### 2. Check REST API Functionality

```php
// Test basic REST API
add_action( 'wp_loaded', function() {
    $rest_url = rest_url();
    $response = wp_remote_get( $rest_url );
    
    if ( is_wp_error( $response ) ) {
        error_log( 'REST API not working: ' . $response->get_error_message() );
    } else {
        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            error_log( 'REST API returned status: ' . $code );
        }
    }
});
```

#### 3. Manual Route Verification

```php
// List all registered routes
add_action( 'rest_api_init', function() {
    $routes = rest_get_server()->get_routes();
    $mcp_routes = array_filter( array_keys( $routes ), function( $route ) {
        return strpos( $route, '/mcp/' ) !== false;
    });
    
    error_log( 'MCP routes found: ' . implode( ', ', $mcp_routes ) );
}, 999 );
```

## Authentication and Permission Errors

### 401 Unauthorized Errors

**Symptoms:**

- All MCP requests return 401
- Authentication seems to fail

**Solutions:**

#### 1. Check Authentication Method

```php
// Debug authentication in your transport
class DebugTransport extends \WP\MCP\Transport\Http\RestTransport {
    public function check_permissions( \WP_REST_Request $request ): bool {
        $auth_header = $request->get_header( 'authorization' );
        $user_id = get_current_user_id();
        
        error_log( sprintf(
            'MCP Auth Check - User: %d, Auth Header: %s',
            $user_id,
            $auth_header ? 'Present' : 'Missing'
        ));
        
        return parent::check_permissions( $request );
    }
}
```

#### 2. Test with Different Authentication

```bash
# Test with basic authentication
curl -X GET "https://yoursite.com/wp-json/your-namespace/mcp/tools" \
  --user "username:password"

# Test with application password
curl -X GET "https://yoursite.com/wp-json/your-namespace/mcp/tools" \
  --user "username:application_password"

# Test with JWT token
curl -X GET "https://yoursite.com/wp-json/your-namespace/mcp/tools" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"
```

### 403 Forbidden Errors

**Symptoms:**

- User is authenticated but can't access MCP functionality
- Permission callback returning false

**Solutions:**

#### 1. Check User Capabilities

```php
// Debug user permissions
add_action( 'mcp_adapter_init', function( $adapter ) {
    add_action( 'wp_loaded', function() {
        $user = wp_get_current_user();
        if ( $user->ID ) {
            error_log( sprintf(
                'User %d (%s) capabilities: %s',
                $user->ID,
                $user->user_login,
                implode( ', ', array_keys( $user->allcaps ) )
            ));
        }
    });
});
```

#### 2. Simplify Permission Callbacks for Testing

```php
// Temporarily allow all authenticated users
add_action( 'abilities_api_init', function() {
    wp_register_ability( 'test/debug-ability', [
    'label' => 'Debug Ability',
    'description' => 'Test ability for debugging permissions',
    'execute_callback' => function() {
        return ['debug' => 'success', 'user_id' => get_current_user_id()];
    },
    'permission_callback' => function() {
        error_log( 'Permission check for user: ' . get_current_user_id() );
        return is_user_logged_in(); // Very permissive for testing
    }
    ]);
});
```

## Transport and Connectivity Issues

### Network Timeouts

**Symptoms:**

- Long response times or timeouts
- Intermittent connection failures

**Solutions:**

#### 1. Increase Timeout Values

```php
// Increase PHP timeouts for MCP operations
add_action( 'mcp_adapter_init', function() {
    if ( defined( 'DOING_AJAX' ) || defined( 'REST_REQUEST' ) ) {
        ini_set( 'max_execution_time', 300 ); // 5 minutes
        ini_set( 'memory_limit', '512M' );
    }
});
```

#### 2. Add Connection Debugging

```php
class DebuggingTransport extends \WP\MCP\Transport\Http\RestTransport {
    public function handle_request( \WP_REST_Request $request ) {
        $start_time = microtime( true );
        
        try {
            $result = parent::handle_request( $request );
            
            $execution_time = microtime( true ) - $start_time;
            error_log( sprintf(
                'MCP request completed in %.2f seconds - Method: %s, Endpoint: %s',
                $execution_time,
                $request->get_method(),
                $request->get_route()
            ));
            
            return $result;
            
        } catch ( Exception $e ) {
            error_log( sprintf(
                'MCP request failed after %.2f seconds: %s',
                microtime( true ) - $start_time,
                $e->getMessage()
            ));
            throw $e;
        }
    }
}
```

### CORS Issues

**Symptoms:**

- Browser requests failing with CORS errors
- Preflight requests not handled

**Solutions:**

#### 1. Add CORS Headers

```php
// Add CORS support to your transport
class CorsEnabledTransport extends \WP\MCP\Transport\Http\RestTransport {
    public function register_routes(): void {
        parent::register_routes();
        
        // Add OPTIONS handler for CORS preflight
        register_rest_route( $this->namespace, $this->route . '/.*', [
            'methods' => 'OPTIONS',
            'callback' => [ $this, 'handle_cors_preflight' ],
            'permission_callback' => '__return_true'
        ]);
    }
    
    public function handle_cors_preflight() {
        $response = rest_ensure_response( null );
        $response->header( 'Access-Control-Allow-Origin', '*' );
        $response->header( 'Access-Control-Allow-Methods', 'GET, POST, OPTIONS' );
        $response->header( 'Access-Control-Allow-Headers', 'Content-Type, Authorization' );
        $response->header( 'Access-Control-Max-Age', '86400' );
        return $response;
    }
}
```

## Ability Execution Problems

### Validation Errors

**Symptoms:**

- Input validation failing unexpectedly
- Schema validation errors

**Debugging:**

#### 1. Log Input Data

```php
// Add input logging to your abilities
'execute_callback' => function( $input ) {
    error_log( 'Ability input: ' . wp_json_encode( $input ) );
    
    try {
        // Your ability logic
        $result = perform_operation( $input );
        
        error_log( 'Ability output: ' . wp_json_encode( $result ) );
        return $result;
        
    } catch ( Exception $e ) {
        error_log( 'Ability error: ' . $e->getMessage() );
        error_log( 'Stack trace: ' . $e->getTraceAsString() );
        throw $e;
    }
}
```

#### 2. Test Schema Validation Separately

```php
// Test schema validation in isolation
function test_schema_validation() {
    $schema = [
        'type' => 'object',
        'properties' => [
            'title' => ['type' => 'string', 'minLength' => 1]
        ],
        'required' => ['title']
    ];
    
    $test_inputs = [
        ['title' => 'Valid Title'],           // Should pass
        ['title' => ''],                      // Should fail
        [],                                   // Should fail
        ['title' => 123]                      // Should fail
    ];
    
    foreach ( $test_inputs as $input ) {
        $validator = new \WP\MCP\Domain\Tools\McpToolValidator();
        $result = $validator->validate_input( $input, $schema );
        
        error_log( sprintf(
            'Input %s: %s',
            wp_json_encode( $input ),
            $result->is_valid() ? 'VALID' : 'INVALID - ' . implode( ', ', $result->get_errors() )
        ));
    }
}
```

### WordPress Database Errors

**Symptoms:**

- Database connection errors
- SQL errors in logs
- Inconsistent data

**Solutions:**

#### 1. Check Database Connection

```php
// Test database connectivity
function test_database_connection() {
    global $wpdb;
    
    $result = $wpdb->get_var( "SELECT 1" );
    
    if ( $result !== '1' ) {
        error_log( 'Database connection test failed' );
        if ( $wpdb->last_error ) {
            error_log( 'Database error: ' . $wpdb->last_error );
        }
    } else {
        error_log( 'Database connection working' );
    }
}
```

#### 2. Enable Query Debugging

```php
// Add to wp-config.php for debugging
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'SAVEQUERIES', true );

// Log problematic queries
add_action( 'shutdown', function() {
    global $wpdb;
    
    if ( defined( 'SAVEQUERIES' ) && SAVEQUERIES ) {
        $slow_queries = array_filter( $wpdb->queries, function( $query ) {
            return $query[1] > 1.0; // Queries taking more than 1 second
        });
        
        if ( ! empty( $slow_queries ) ) {
            error_log( 'Slow queries detected: ' . count( $slow_queries ) );
            foreach ( $slow_queries as $query ) {
                error_log( sprintf( 'Slow query (%.4f s): %s', $query[1], $query[0] ) );
            }
        }
    }
});
```

## Error Handling Issues

### Error Handler Not Working

**Symptoms:**

- Errors not being logged to your monitoring system
- Missing error logs in expected locations
- Error handler methods not being called

**Common Causes & Solutions:**

#### 1. Error Handler Not Implementing Interface

```php
// Check if your error handler implements the correct interface
class MyErrorHandler implements \WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface {
    public function log(string $message, array $context = [], string $type = 'error'): void {
        // Your implementation
    }
}

// Verify interface implementation
if (!in_array(\WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface::class, class_implements(MyErrorHandler::class))) {
    error_log('Error handler does not implement required interface');
}
```

#### 2. Error Handler Not Being Instantiated

```php
// Debug error handler instantiation
add_action('mcp_adapter_init', function($adapter) {
    $server = $adapter->create_server(
        'debug-server',
        'debug',
        'mcp',
        'Debug Server',
        'Debug Description',
        '1.0.0',
        [],
        MyErrorHandler::class, // This should instantiate your handler
    );
    
    // Check if error handler was created
    if ($server->error_handler instanceof MyErrorHandler) {
        error_log('Error handler instantiated correctly');
    } else {
        error_log('Error handler not instantiated: ' . get_class($server->error_handler));
    }
});
```

#### 3. Error Handler Class Not Found

```php
// Verify error handler class exists and is autoloaded
if (!class_exists(MyErrorHandler::class)) {
    error_log('Error handler class not found: ' . MyErrorHandler::class);
    error_log('Available classes: ' . implode(', ', get_declared_classes()));
}

// Check for typos in class name
$reflection = new ReflectionClass(\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class);
error_log('Reference implementation path: ' . $reflection->getFileName());
```

### Error Responses Not Formatted Correctly

**Symptoms:**

- Clients receiving malformed error responses
- Missing JSON-RPC error fields
- Incorrect error codes

**Solutions:**

#### 1. Use McpErrorFactory for Consistent Responses

```php
// Correct way to create error responses
use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;

// In your handler methods, use factory methods
public function handle_request($params) {
    if (empty($params['name'])) {
        // Return properly formatted error
        return array(
            'error' => McpErrorFactory::missing_parameter(
                $request_id ?? 0, 
                'name'
            )['error']
        );
    }
}

// Verify error format
$error = McpErrorFactory::tool_not_found(123, 'test-tool');
assert(isset($error['jsonrpc']));
assert(isset($error['id']));
assert(isset($error['error']['code']));
assert(isset($error['error']['message']));
```

#### 2. Debug Error Response Creation

```php
// Add debugging to error response creation
function debug_error_response($error_response) {
    $required_fields = ['jsonrpc', 'id', 'error'];
    $missing_fields = [];
    
    foreach ($required_fields as $field) {
        if (!isset($error_response[$field])) {
            $missing_fields[] = $field;
        }
    }
    
    if (!empty($missing_fields)) {
        error_log('Invalid error response missing fields: ' . implode(', ', $missing_fields));
        error_log('Error response: ' . wp_json_encode($error_response));
    }
    
    return $error_response;
}
```

### Error Logging Performance Issues

**Symptoms:**

- Slow response times during errors
- High memory usage during error handling
- Error handling causing timeouts

**Solutions:**

#### 1. Implement Async Error Logging

```php
class AsyncErrorHandler implements \WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface {
    
    public function log(string $message, array $context = [], string $type = 'error'): void {
        // Queue error for async processing
        wp_schedule_single_event(time(), 'process_mcp_error', [
            'message' => $message,
            'context' => $context,
            'type' => $type,
            'timestamp' => time()
        ]);
    }
}

// Process errors asynchronously
add_action('process_mcp_error', function($error_data) {
    // Send to monitoring system without blocking main request
    MyMonitoringSystem::send_async($error_data);
});
```

#### 2. Add Error Handler Rate Limiting

```php
class RateLimitedErrorHandler implements \WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface {
    
    private const RATE_LIMIT_KEY = 'mcp_error_rate_limit';
    private const MAX_ERRORS_PER_MINUTE = 60;
    
    public function log(string $message, array $context = [], string $type = 'error'): void {
        if (!$this->shouldLog()) {
            return; // Skip logging due to rate limit
        }
        
        // Your logging implementation
        $this->logToSystem($message, $context, $type);
        $this->updateRateLimit();
    }
    
    private function shouldLog(): bool {
        $current_count = get_transient(self::RATE_LIMIT_KEY) ?: 0;
        return $current_count < self::MAX_ERRORS_PER_MINUTE;
    }
    
    private function updateRateLimit(): void {
        $current_count = get_transient(self::RATE_LIMIT_KEY) ?: 0;
        set_transient(self::RATE_LIMIT_KEY, $current_count + 1, 60);
    }
}
```

### Incompatible Error Handler Implementations

**Symptoms:**

- Error handler classes not working as expected
- Interface implementation errors
- Method signature mismatches

**Solutions:**

#### 1. Verify Interface Implementation

```php
// Correct error handler implementation
class MyErrorHandler implements \WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface {
    public function log(string $message, array $context = [], string $type = 'error'): void {
        // Your implementation here
        error_log("[MCP {$type}] {$message} | Context: " . wp_json_encode($context));
    }
}

// Verify interface implementation
if (!in_array(\WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface::class, class_implements(MyErrorHandler::class))) {
    error_log('Error handler does not implement required interface');
}
```

#### 2. Correct Error Response Creation

```php
// Correct error response creation using McpErrorFactory
use \WP\MCP\ErrorHandlers\McpErrorFactory;

public function handle_request($params) {
    if (empty($params['name'])) {
        return array(
            'error' => McpErrorFactory::missing_parameter($request_id, 'name')['error']
        );
    }
}
```

#### 3. Debug Error Handler Method Calls

```php
// Add debugging to verify error handler methods are called correctly
class DebugErrorHandler implements \WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface {
    
    public function log(string $message, array $context = [], string $type = 'error'): void {
        // Log method call for debugging
        error_log("Error handler called: type={$type}, message={$message}");
        
        // Your actual implementation
        $this->sendToMonitoring($message, $context, $type);
    }
    
    private function sendToMonitoring(string $message, array $context, string $type): void {
        // Your monitoring system integration
    }
}
```

## Performance Issues

### Slow Response Times

**Symptoms:**

- MCP requests taking too long
- Timeouts occurring
- High server load

**Solutions:**

#### 1. Add Performance Monitoring

```php
class PerformanceMonitor {
    private static $timers = [];
    
    public static function start( string $operation ): void {
        self::$timers[ $operation ] = [
            'start_time' => microtime( true ),
            'start_memory' => memory_get_usage( true )
        ];
    }
    
    public static function end( string $operation ): void {
        if ( ! isset( self::$timers[ $operation ] ) ) {
            return;
        }
        
        $timer = self::$timers[ $operation ];
        $duration = microtime( true ) - $timer['start_time'];
        $memory_used = memory_get_usage( true ) - $timer['start_memory'];
        
        error_log( sprintf(
            'Performance: %s took %.4f seconds and used %s memory',
            $operation,
            $duration,
            size_format( $memory_used )
        ));
        
        // Alert on slow operations
        if ( $duration > 5.0 ) {
            error_log( "SLOW OPERATION ALERT: {$operation} took {$duration} seconds" );
        }
        
        unset( self::$timers[ $operation ] );
    }
}

// Use in your abilities
'execute_callback' => function( $input ) {
    PerformanceMonitor::start( 'my-ability-execution' );
    
    try {
        $result = perform_operation( $input );
        return $result;
    } finally {
        PerformanceMonitor::end( 'my-ability-execution' );
    }
}
```

#### 2. Implement Caching

```php
// Add caching to expensive operations
function cached_expensive_operation( $input ) {
    $cache_key = 'mcp_expensive_' . md5( serialize( $input ) );
    
    $cached_result = wp_cache_get( $cache_key, 'mcp_operations' );
    if ( $cached_result !== false ) {
        error_log( 'Cache hit for operation: ' . $cache_key );
        return $cached_result;
    }
    
    error_log( 'Cache miss for operation: ' . $cache_key );
    $result = perform_expensive_operation( $input );
    
    wp_cache_set( $cache_key, $result, 'mcp_operations', 300 ); // 5 minutes
    
    return $result;
}
```

### Memory Issues

**Symptoms:**

- Fatal error: Out of memory
- Increasing memory usage
- Server crashes

**Solutions:**

#### 1. Monitor Memory Usage

```php
function log_memory_usage( string $location ) {
    $current = memory_get_usage( true );
    $peak = memory_get_peak_usage( true );
    $limit = ini_get( 'memory_limit' );
    
    error_log( sprintf(
        'Memory at %s - Current: %s, Peak: %s, Limit: %s',
        $location,
        size_format( $current ),
        size_format( $peak ),
        $limit
    ));
}

// Use throughout your code
'execute_callback' => function( $input ) {
    log_memory_usage( 'ability_start' );
    
    $result = perform_operation( $input );
    
    log_memory_usage( 'ability_end' );
    return $result;
}
```

## Debugging Techniques

### Enable Debug Logging

Add to `wp-config.php`:

```php
// Core debugging
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );

// MCP-specific debugging
define( 'MCP_DEBUG', true );
define( 'MCP_DEBUG_LEVEL', 'verbose' ); // 'basic', 'verbose', 'trace'
```

### Create Debug Endpoints

```php
// Add debug endpoint (remove in production!)
add_action( 'rest_api_init', function() {
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        register_rest_route( 'mcp-debug/v1', '/info', [
            'methods' => 'GET',
            'callback' => function() {
                $adapter = \WP\MCP\Core\McpAdapter::instance();
                
                return [
                    'mcp_adapter_loaded' => class_exists( 'WP\MCP\Core\McpAdapter' ),
                    'abilities_api_loaded' => function_exists( 'wp_register_ability' ),
                    'servers' => array_keys( $adapter->get_servers() ),
                    'php_version' => PHP_VERSION,
                    'wp_version' => get_bloginfo( 'version' ),
                    'memory_limit' => ini_get( 'memory_limit' ),
                    'current_memory' => size_format( memory_get_usage( true ) )
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'manage_options' );
            }
        ]);
    }
});
```

### Log Analysis Tools

```bash
# Tail WordPress debug log
tail -f wp-content/debug.log | grep MCP

# Search for specific errors
grep -n "MCP Error" wp-content/debug.log | tail -20

# Count error types
grep "MCP Error" wp-content/debug.log | cut -d':' -f4 | sort | uniq -c | sort -nr

# Monitor log in real-time with filtering
tail -f wp-content/debug.log | grep --line-buffered "MCP\|abilities"
```

### Performance Profiling

```php
// Simple profiler for MCP operations
class McpProfiler {
    private static $profiles = [];
    
    public static function start( string $operation ): void {
        self::$profiles[ $operation ] = [
            'start' => microtime( true ),
            'memory_start' => memory_get_usage( true ),
            'queries_start' => get_num_queries()
        ];
    }
    
    public static function end( string $operation ): array {
        if ( ! isset( self::$profiles[ $operation ] ) ) {
            return [];
        }
        
        $profile = self::$profiles[ $operation ];
        $result = [
            'operation' => $operation,
            'duration' => microtime( true ) - $profile['start'],
            'memory_used' => memory_get_usage( true ) - $profile['memory_start'],
            'queries' => get_num_queries() - $profile['queries_start']
        ];
        
        unset( self::$profiles[ $operation ] );
        
        error_log( sprintf(
            'Profile %s: %.4fs, %s memory, %d queries',
            $operation,
            $result['duration'],
            size_format( $result['memory_used'] ),
            $result['queries']
        ));
        
        return $result;
    }
}
```

## Getting Help

### Community Resources

1. **GitHub Issues**: Report bugs and request features
2. **Documentation**: Check latest docs for updates


This troubleshooting guide should help you resolve most common issues. For persistent problems, use the debugging
techniques to gather detailed information before seeking help.

## Next Steps

- **Review [Architecture Overview](../architecture/overview.md)** for system understanding
- **Check [Creating Abilities](../guides/creating-abilities.md)** for working implementations
- **Explore [API Reference](../api-reference/)** for detailed documentation
- **See [Installation Guide](../getting-started/installation.md)** for setup verification
