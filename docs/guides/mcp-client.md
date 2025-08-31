# MCP Client Guide

This guide covers how to use the MCP Client functionality to connect WordPress to external MCP servers and consume their capabilities as local WordPress abilities.

## Table of Contents

1. [Overview](#overview)
2. [Basic Client Setup](#basic-client-setup)
3. [Authentication Methods](#authentication-methods)
4. [Client Configuration](#client-configuration)
5. [Working with Remote Abilities](#working-with-remote-abilities)
6. [Error Handling](#error-handling)
7. [Real-World Examples](#real-world-examples)
8. [Troubleshooting](#troubleshooting)

## Overview

The MCP Client enables WordPress to act as a client to external MCP servers, consuming their tools, resources, and prompts as local WordPress abilities. This creates a bidirectional integration where WordPress can both expose and consume MCP capabilities.

### Key Features

- **Automatic Ability Registration**: Remote MCP capabilities become WordPress abilities with the `mcp-{client-id}/` prefix
- **Multiple Authentication Methods**: Support for bearer tokens, API keys, and basic authentication
- **Connection Management**: Built-in connection health monitoring and error handling
- **Permission Control**: Granular permission checking for remote capability usage
- **Observability**: Integration with MCP Adapter's observability system

## Basic Client Setup

### Step 1: Create a Client Connection

Use the `mcp_client_init` action hook to create client connections:

```php
add_action( 'mcp_client_init', function( $adapter ) {
    $client = $adapter->create_client(
        'my-service',                    // Unique client identifier
        'https://api.example.com/mcp',   // External MCP server URL
        [                                // Client configuration
            'timeout' => 30,
        ]
    );
    
    if ( is_wp_error( $client ) ) {
        error_log( 'Failed to create MCP client: ' . $client->get_error_message() );
        return;
    }
    
    // Client is now connected and remote capabilities are being registered
});
```

### Step 2: Verify Connection

Check that the client connection was successful:

```php
add_action( 'init', function() {
    // Check if remote abilities were registered
    if ( wp_get_ability( 'mcp-my-service/some-tool' ) ) {
        echo 'Successfully connected to external MCP server!';
    }
}, 999 ); // Late priority to ensure abilities are registered
```

## Authentication Methods

The MCP Client supports multiple authentication methods for connecting to external servers.

### Bearer Token Authentication

```php
add_action( 'mcp_client_init', function( $adapter ) {
    $client = $adapter->create_client(
        'authenticated-service',
        'https://api.example.com/mcp',
        [
            'auth' => [
                'type'  => 'bearer',
                'token' => 'your-bearer-token-here',
            ],
            'timeout' => 30,
        ]
    );
});
```

### API Key Authentication

```php
add_action( 'mcp_client_init', function( $adapter ) {
    $client = $adapter->create_client(
        'api-key-service',
        'https://api.example.com/mcp',
        [
            'auth' => [
                'type'   => 'api_key',
                'key'    => 'your-api-key-here',
                'header' => 'X-API-Key', // Optional, defaults to X-API-Key
            ],
            'timeout' => 30,
        ]
    );
});
```

### Basic Authentication

```php
add_action( 'mcp_client_init', function( $adapter ) {
    $client = $adapter->create_client(
        'basic-auth-service',
        'https://api.example.com/mcp',
        [
            'auth' => [
                'type'     => 'basic',
                'username' => 'your-username',
                'password' => 'your-password',
            ],
            'timeout' => 30,
        ]
    );
});
```

### Environment-Based Authentication

For security, store credentials in environment variables:

```php
add_action( 'mcp_client_init', function( $adapter ) {
    $api_token = getenv( 'MCP_SERVICE_TOKEN' );
    
    if ( empty( $api_token ) ) {
        error_log( 'MCP_SERVICE_TOKEN environment variable not set' );
        return;
    }
    
    $client = $adapter->create_client(
        'env-service',
        'https://api.example.com/mcp',
        [
            'auth' => [
                'type'  => 'bearer',
                'token' => $api_token,
            ],
            'timeout' => 30,
        ]
    );
});
```

## Client Configuration

### Complete Configuration Example

```php
add_action( 'mcp_client_init', function( $adapter ) {
    $client = $adapter->create_client(
        'full-config-service',           // Client ID
        'https://api.example.com/mcp',   // Server URL
        [                                // Configuration array
            // Authentication
            'auth' => [
                'type'  => 'bearer',
                'token' => 'your-token',
            ],
            
            // Connection settings
            'timeout'     => 30,         // Request timeout in seconds
            'retry_count' => 3,          // Number of retry attempts
            'retry_delay' => 1,          // Delay between retries (seconds)
            
            // SSL settings
            'verify_ssl' => true,        // Verify SSL certificates
            
            // User agent
            'user_agent' => 'WordPress MCP Client/1.0',
        ],
        MyCustomErrorHandler::class,     // Custom error handler (optional)
        MyCustomObservabilityHandler::class // Custom observability (optional)
    );
});
```

### Configuration Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `auth` | array | `[]` | Authentication configuration |
| `timeout` | int | `30` | Request timeout in seconds |
| `retry_count` | int | `3` | Number of retry attempts on failure |
| `retry_delay` | int | `1` | Delay between retries in seconds |
| `verify_ssl` | bool | `true` | Whether to verify SSL certificates |
| `user_agent` | string | `'WordPress MCP Client/1.0'` | User agent string |

## Working with Remote Abilities

Once a client is connected, remote MCP capabilities become available as WordPress abilities.

### Using Remote Tools

```php
// Remote MCP tool becomes a WordPress ability
$result = wp_execute_ability( 'mcp-my-service/analyze-content', [
    'content' => 'Text to analyze',
    'type'    => 'sentiment'
] );

if ( ! is_wp_error( $result ) ) {
    echo "Sentiment: " . $result['sentiment'];
    echo "Confidence: " . $result['confidence'];
}
```

### Using Remote Resources

```php
// Remote MCP resource becomes a WordPress ability
$data = wp_execute_ability( 'mcp-my-service/user-profile', [] );

if ( ! is_wp_error( $data ) ) {
    foreach ( $data['contents'] as $content ) {
        if ( $content['type'] === 'text' ) {
            $profile_data = json_decode( $content['text'], true );
            echo "User: " . $profile_data['name'];
        }
    }
}
```

### Using Remote Prompts

```php
// Remote MCP prompt becomes a WordPress ability
$prompt = wp_execute_ability( 'mcp-my-service/seo-analysis', [
    'url' => 'https://example.com'
] );

if ( ! is_wp_error( $prompt ) ) {
    echo "SEO Recommendations:\n";
    echo $prompt['analysis'];
}
```

### Checking Available Remote Abilities

```php
// List all abilities from a specific client
$all_abilities = wp_get_abilities();
$remote_abilities = array_filter( $all_abilities, function( $ability_name ) {
    return strpos( $ability_name, 'mcp-my-service/' ) === 0;
} );

foreach ( $remote_abilities as $ability_name ) {
    $ability = wp_get_ability( $ability_name );
    echo "Remote ability: {$ability_name} - {$ability['label']}\n";
}
```

## Error Handling

### Client-Level Error Handling

```php
add_action( 'mcp_client_init', function( $adapter ) {
    $client = $adapter->create_client(
        'error-handled-service',
        'https://api.example.com/mcp',
        [
            'auth' => [
                'type'  => 'bearer',
                'token' => 'token',
            ],
        ],
        // Custom error handler for this client
        function( $message, $context, $type ) {
            // Log errors to a specific file for this client
            error_log( "[MCP Client error-handled-service] {$type}: {$message} | " . wp_json_encode( $context ), 3, '/var/log/mcp-client-errors.log' );
            
            // Send to monitoring system
            MyMonitoringSystem::send_error( $message, $context, $type );
        }
    );
});
```

### Ability-Level Error Handling

```php
add_action( 'init', function() {
    // Wrap remote ability calls in try-catch
    try {
        $result = wp_execute_ability( 'mcp-my-service/risky-operation', [
            'data' => 'some input'
        ] );
        
        if ( is_wp_error( $result ) ) {
            // Handle WordPress error
            error_log( 'Remote ability error: ' . $result->get_error_message() );
            return;
        }
        
        // Process successful result
        process_result( $result );
        
    } catch ( Exception $e ) {
        // Handle any other exceptions
        error_log( 'Unexpected error in remote ability: ' . $e->getMessage() );
    }
});
```

### Connection Health Monitoring

```php
// Monitor client connection health
add_action( 'wp_loaded', function() {
    $adapter = \WP\MCP\Core\McpAdapter::instance();
    $clients = $adapter->get_clients(); // Hypothetical method
    
    foreach ( $clients as $client_id => $client ) {
        if ( ! $client->is_connected() ) {
            error_log( "MCP client {$client_id} is disconnected" );
            
            // Attempt to reconnect
            $reconnected = $client->reconnect();
            if ( $reconnected ) {
                error_log( "Successfully reconnected MCP client {$client_id}" );
            } else {
                error_log( "Failed to reconnect MCP client {$client_id}" );
            }
        }
    }
});
```

## Real-World Examples

### Example 1: WordPress Domains Integration

```php
add_action( 'mcp_client_init', function( $adapter ) {
    $client = $adapter->create_client(
        'wpcom-domains',
        'https://wpcom-domains-mcp.a8cai.workers.dev/mcp',
        [
            'timeout' => 30,
        ]
    );

    if ( is_wp_error( $client ) ) {
        error_log( 'Failed to connect to WordPress Domains: ' . $client->get_error_message() );
        return;
    }

    // Remote tools are automatically registered as WordPress abilities:
    // - mcp-wpcom-domains/searchdomains
    // - mcp-wpcom-domains/checkdomainavailability
    // - mcp-wpcom-domains/getsuggestedtlds
} );

// Use the domain search functionality
add_action( 'admin_init', function() {
    if ( isset( $_GET['search_domain'] ) ) {
        $domain_query = sanitize_text_field( $_GET['search_domain'] );
        
        $results = wp_execute_ability( 'mcp-wpcom-domains/searchdomains', [
            'query' => $domain_query,
            'limit' => 10
        ] );
        
        if ( ! is_wp_error( $results ) ) {
            update_option( 'domain_search_results', $results );
        }
    }
} );
```

### Example 2: AI Content Analysis Service

```php
add_action( 'mcp_client_init', function( $adapter ) {
    $client = $adapter->create_client(
        'ai-content-analyzer',
        'https://api.contentanalyzer.com/mcp',
        [
            'auth' => [
                'type'  => 'api_key',
                'key'   => get_option( 'content_analyzer_api_key' ),
            ],
            'timeout' => 60, // Content analysis may take longer
        ]
    );
} );

// Add content analysis to post editor
add_action( 'add_meta_boxes', function() {
    add_meta_box(
        'content_analysis',
        'AI Content Analysis',
        function( $post ) {
            if ( wp_get_ability( 'mcp-ai-content-analyzer/analyze-post' ) ) {
                echo '<button type="button" id="analyze-content">Analyze Content</button>';
                echo '<div id="analysis-results"></div>';
            } else {
                echo '<p>Content analysis service not available.</p>';
            }
        },
        'post',
        'side'
    );
} );

// AJAX handler for content analysis
add_action( 'wp_ajax_analyze_content', function() {
    if ( ! current_user_can( 'edit_posts' ) ) {
        wp_die( 'Insufficient permissions' );
    }
    
    $post_id = intval( $_POST['post_id'] );
    $post = get_post( $post_id );
    
    if ( ! $post ) {
        wp_die( 'Post not found' );
    }
    
    $analysis = wp_execute_ability( 'mcp-ai-content-analyzer/analyze-post', [
        'content' => $post->post_content,
        'title'   => $post->post_title,
        'type'    => 'readability'
    ] );
    
    if ( is_wp_error( $analysis ) ) {
        wp_send_json_error( 'Analysis failed: ' . $analysis->get_error_message() );
    } else {
        wp_send_json_success( $analysis );
    }
} );
```

### Example 3: Multi-Service Integration

```php
add_action( 'mcp_client_init', function( $adapter ) {
    // Connect to multiple services
    $services = [
        'weather' => 'https://api.weather.com/mcp',
        'news'    => 'https://api.newsservice.com/mcp',
        'finance' => 'https://api.financedata.com/mcp',
    ];
    
    foreach ( $services as $service_id => $service_url ) {
        $api_key = get_option( "{$service_id}_api_key" );
        
        if ( empty( $api_key ) ) {
            error_log( "No API key configured for service: {$service_id}" );
            continue;
        }
        
        $client = $adapter->create_client(
            $service_id,
            $service_url,
            [
                'auth' => [
                    'type' => 'api_key',
                    'key'  => $api_key,
                ],
                'timeout' => 15,
            ]
        );
        
        if ( is_wp_error( $client ) ) {
            error_log( "Failed to connect to {$service_id}: " . $client->get_error_message() );
        } else {
            error_log( "Successfully connected to {$service_id} MCP service" );
        }
    }
} );

// Create a dashboard widget using multiple services
add_action( 'wp_dashboard_setup', function() {
    wp_add_dashboard_widget(
        'multi_service_widget',
        'External Data Dashboard',
        function() {
            // Weather data
            $weather = wp_execute_ability( 'mcp-weather/current-weather', [
                'location' => 'San Francisco, CA'
            ] );
            
            // News headlines
            $news = wp_execute_ability( 'mcp-news/headlines', [
                'category' => 'technology',
                'limit'    => 5
            ] );
            
            // Stock data
            $stocks = wp_execute_ability( 'mcp-finance/stock-quote', [
                'symbol' => 'AAPL'
            ] );
            
            echo '<div class="multi-service-dashboard">';
            
            if ( ! is_wp_error( $weather ) ) {
                echo "<h4>Weather</h4>";
                echo "<p>San Francisco: {$weather['temperature']}°F, {$weather['conditions']}</p>";
            }
            
            if ( ! is_wp_error( $news ) ) {
                echo "<h4>Tech News</h4>";
                echo "<ul>";
                foreach ( array_slice( $news['articles'], 0, 3 ) as $article ) {
                    echo "<li><a href='{$article['url']}'>{$article['title']}</a></li>";
                }
                echo "</ul>";
            }
            
            if ( ! is_wp_error( $stocks ) ) {
                echo "<h4>AAPL Stock</h4>";
                echo "<p>\${$stocks['price']} ({$stocks['change']})</p>";
            }
            
            echo '</div>';
        }
    );
} );
```

## Troubleshooting

### Common Issues

#### Client Not Connecting

**Symptoms:**
- No remote abilities are registered
- Error messages in logs about connection failures

**Solutions:**
1. Verify the server URL is correct and accessible
2. Check authentication credentials
3. Ensure the external server supports MCP protocol
4. Check network connectivity and firewall rules

```php
// Test connection manually
add_action( 'init', function() {
    if ( isset( $_GET['test_mcp_connection'] ) && current_user_can( 'manage_options' ) ) {
        $response = wp_remote_get( 'https://api.example.com/mcp', [
            'timeout' => 30,
        ] );
        
        if ( is_wp_error( $response ) ) {
            echo 'Connection failed: ' . $response->get_error_message();
        } else {
            echo 'Connection successful: ' . wp_remote_retrieve_response_code( $response );
        }
        exit;
    }
} );
```

#### Authentication Failures

**Symptoms:**
- 401 or 403 HTTP errors
- "Authentication failed" error messages

**Solutions:**
1. Verify authentication credentials are correct
2. Check if authentication method matches server expectations
3. Ensure credentials have necessary permissions

```php
// Debug authentication
add_action( 'mcp_client_init', function( $adapter ) {
    $client = $adapter->create_client(
        'debug-auth',
        'https://api.example.com/mcp',
        [
            'auth' => [
                'type'  => 'bearer',
                'token' => 'your-token',
            ],
        ],
        // Debug error handler
        function( $message, $context, $type ) {
            if ( strpos( $message, 'auth' ) !== false || strpos( $message, '401' ) !== false ) {
                error_log( "Authentication issue detected: {$message}" );
                error_log( "Context: " . wp_json_encode( $context ) );
            }
        }
    );
} );
```

#### Remote Abilities Not Working

**Symptoms:**
- Remote abilities are registered but return errors when executed
- Unexpected response formats

**Solutions:**
1. Check if remote server is functioning properly
2. Verify input parameters match remote server expectations
3. Check for version compatibility issues

```php
// Test remote ability execution
add_action( 'init', function() {
    if ( isset( $_GET['test_remote_ability'] ) && current_user_can( 'manage_options' ) ) {
        $result = wp_execute_ability( 'mcp-my-service/test-tool', [
            'test_param' => 'test_value'
        ] );
        
        if ( is_wp_error( $result ) ) {
            echo 'Ability execution failed: ' . $result->get_error_message();
        } else {
            echo 'Ability execution successful: ' . wp_json_encode( $result );
        }
        exit;
    }
} );
```

### Performance Issues

#### Slow Response Times

**Solutions:**
1. Increase timeout values for slow external services
2. Implement caching for frequently accessed data
3. Use async processing for non-critical operations

```php
// Implement caching for remote data
function get_cached_remote_data( $ability_name, $params, $cache_duration = 300 ) {
    $cache_key = 'mcp_' . md5( $ability_name . serialize( $params ) );
    
    $cached_result = get_transient( $cache_key );
    if ( $cached_result !== false ) {
        return $cached_result;
    }
    
    $result = wp_execute_ability( $ability_name, $params );
    
    if ( ! is_wp_error( $result ) ) {
        set_transient( $cache_key, $result, $cache_duration );
    }
    
    return $result;
}

// Usage
$weather_data = get_cached_remote_data( 'mcp-weather/current-weather', [
    'location' => 'San Francisco, CA'
], 1800 ); // Cache for 30 minutes
```

### Debugging Tools

#### Connection Status Check

```php
// Add admin page to check client connections
add_action( 'admin_menu', function() {
    add_submenu_page(
        'tools.php',
        'MCP Client Status',
        'MCP Clients',
        'manage_options',
        'mcp-client-status',
        function() {
            echo '<div class="wrap">';
            echo '<h1>MCP Client Status</h1>';
            
            $adapter = \WP\MCP\Core\McpAdapter::instance();
            // This would require implementing a get_clients() method
            
            echo '<table class="widefat">';
            echo '<thead><tr><th>Client ID</th><th>Server URL</th><th>Status</th><th>Last Check</th></tr></thead>';
            echo '<tbody>';
            
            // Display client status information
            echo '<tr><td colspan="4">Client status information would go here</td></tr>';
            
            echo '</tbody>';
            echo '</table>';
            echo '</div>';
        }
    );
} );
```

## Next Steps

- **Explore [Authentication Methods](#authentication-methods)** for secure connections
- **Review [Error Handling](#error-handling)** for robust integration
- **Check [Real-World Examples](#real-world-examples)** for practical implementations
- **Read [Troubleshooting Guide](../troubleshooting/common-issues.md)** for problem-solving

This guide should provide everything you need to successfully integrate WordPress with external MCP servers using the MCP Client functionality.