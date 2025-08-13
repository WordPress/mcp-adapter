# Installation Guide

This guide covers different installation methods for the MCP Adapter, from simple manual installation to advanced
Composer-based workflows.

## Installation Methods

### Method 1: Manual Installation (Recommended for Most Users)

The MCP Adapter works perfectly without Composer by using the included Jetpack autoloader. This is the simplest method
and works in any WordPress environment.

#### Download and Setup

1. **Download the library** to your WordPress installation:
   ```bash
   # Navigate to your WordPress wp-content directory
   cd /path/to/your/wordpress/wp-content/lib/
   
   # Clone or download the MCP adapter
   git clone https://github.com/your-org/mcp-adapter.git
   ```

2. **Load the autoloader** in your plugin or theme:
   ```php
   <?php
   // In your main plugin file or theme's functions.php
   
   // Check if the class isn't already loaded by another plugin
   if ( ! class_exists( 'WP\MCP\Core\McpAdapter' ) ) {
       // Load the Jetpack autoloader
       if ( is_file( ABSPATH . 'wp-content/lib/mcp-adapter/vendor/autoload_packages.php' ) ) {
           require_once ABSPATH . 'wp-content/lib/mcp-adapter/vendor/autoload_packages.php';
       }
   }
   ```

3. **Verify the installation**:
   ```php
   // Test that the adapter is available
   if ( class_exists( 'WP\MCP\Core\McpAdapter' ) ) {
       error_log( 'MCP Adapter loaded successfully' );
   } else {
       error_log( 'MCP Adapter failed to load' );
   }
   ```

#### Manual Installation in a Plugin

Here's a complete example of integrating the MCP Adapter into your plugin:

```php
<?php
/**
 * Plugin Name: My MCP Plugin
 * Description: Demonstrates MCP Adapter integration
 * Version: 1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MyMcpPlugin {
    
    public function __construct() {
        add_action( 'plugins_loaded', [ $this, 'init' ] );
    }
    
    public function init() {
        // Load MCP Adapter
        $this->load_mcp_adapter();
        
        // Ensure WordPress Abilities API is available
        if ( ! function_exists( 'wp_register_ability' ) ) {
            add_action( 'admin_notices', [ $this, 'missing_abilities_api_notice' ] );
            return;
        }
        
        // Initialize your MCP functionality
        $this->register_abilities();
        $this->setup_mcp_server();
    }
    
    private function load_mcp_adapter() {
        if ( ! class_exists( 'WP\MCP\Core\McpAdapter' ) ) {
            $autoloader_path = ABSPATH . 'wp-content/lib/mcp-adapter/vendor/autoload_packages.php';
            if ( is_file( $autoloader_path ) ) {
                require_once $autoloader_path;
            }
        }
    }
    
    private function register_abilities() {
        // Your ability registration code here
    }
    
    private function setup_mcp_server() {
        add_action( 'mcp_adapter_init', [ $this, 'create_mcp_server' ] );
    }
    
    public function create_mcp_server( $adapter ) {
        // Your server creation code here
    }
    
    public function missing_abilities_api_notice() {
        echo '<div class="notice notice-error"><p>';
        echo 'My MCP Plugin requires the WordPress Abilities API to be loaded.';
        echo '</p></div>';
    }
}

new MyMcpPlugin();
```

### Method 2: Composer Installation (For Advanced Users)

If your project uses Composer, you can install the adapter for enhanced dependency management.

#### Using Composer

1. **Install via Composer**:
   ```bash
   composer require wordpress/mcp-adapter
   ```

2. **Load in your code**:
   ```php
   <?php
   // If using Composer autoloading
   require_once __DIR__ . '/vendor/autoload.php';
   
   use WP\MCP\Core\McpAdapter;
   
   // The adapter is now available
   $adapter = McpAdapter::instance();
   ```

#### Composer Benefits

- **Automatic dependency resolution and updates**
- **Version constraint management** across your project
- **Integration with existing Composer-based workflows**
- **Simplified dependency tracking** in `composer.json`

#### Example composer.json

```json
{
    "name": "my-company/my-wordpress-plugin",
    "description": "WordPress plugin with MCP integration",
    "require": {
        "php": ">=8.1",
        "wordpress/mcp-adapter": "^1.0"
    },
    "autoload": {
        "psr-4": {
            "MyCompany\\MyPlugin\\": "src/"
        }
    }
}
```

### Method 3: Must-Use Plugin Environment

For system-wide implementations, the adapter can be installed as part of the mu-plugins structure:

```php
<?php
// In wp-content/mu-plugins/my-mcp-plugin/my-mcp-plugin.php

// Load the MCP adapter from the shared library location
if ( ! class_exists( 'WP\MCP\Core\McpAdapter' ) ) {
    $autoloader_path = ABSPATH . 'wp-content/lib/mcp-adapter/vendor/autoload_packages.php';
    if ( is_file( $autoloader_path ) ) {
        require_once $autoloader_path;
    }
}

// Your system-wide MCP implementation
```

## Verifying Installation

### Quick Verification Script

Create a simple test to ensure everything is working:

```php
<?php
// Add this to a plugin or theme temporarily
add_action( 'wp_loaded', function() {
    if ( ! class_exists( 'WP\MCP\Core\McpAdapter' ) ) {
        wp_die( 'MCP Adapter not loaded' );
    }
    
    $adapter = \WP\MCP\Core\McpAdapter::instance();
    error_log( 'MCP Adapter version: ' . $adapter->get_version() );
    
    // Test basic functionality
    try {
        $adapter->create_server(
            'test-server',
            'test',
            'mcp',
            'Test Server',
            'Testing installation',
            '1.0.0',
            [],
            \WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
            []
        );
        error_log( 'MCP Server creation successful' );
    } catch ( Exception $e ) {
        error_log( 'MCP Server creation failed: ' . $e->getMessage() );
    }
});
```

### REST API Verification

After installation, you should be able to access MCP endpoints:

```bash
# Check if REST API is working
curl "https://yoursite.com/wp-json/"

# Once you create a server, test MCP endpoints
curl -X POST "https://yoursite.com/wp-json/your-namespace/mcp" \
  -H "Content-Type: application/json" \
  -d '{"method": "tools/list"}'
```

## Troubleshooting Installation

### Common Issues

**"Class 'WP\MCP\Core\McpAdapter' not found"**

- Verify the autoloader path is correct
- Check file permissions on the library directory
- Ensure the adapter files are properly downloaded

**"WordPress Abilities API not available"**

- Confirm the Abilities API is loaded before MCP Adapter
- Check plugin loading order
- Verify Abilities API installation

**REST API not responding**

- Check WordPress REST API is enabled
- Verify permalink structure is set (not "Plain")
- Test basic REST API functionality: `/wp-json/`

**Permission denied errors**

- Check file permissions on the adapter directory
- Verify web server has read access to the files
- Ensure WordPress can write to necessary directories

### Debug Mode

Enable debug logging to troubleshoot issues:

```php
// Add to wp-config.php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );

// Add debug logging to your MCP setup
add_action( 'mcp_adapter_init', function( $adapter ) {
    error_log( 'MCP Adapter initialized with ' . count( $adapter->get_servers() ) . ' servers' );
});
```

## Next Steps

Once installation is complete:

1. **Follow the [Quick Start Guide](README.md)** to create your first MCP server
2. **Explore [Creating Abilities](../guides/creating-abilities.md)** to build your MCP tools
3. **Review [Architecture Overview](../architecture/overview.md)** for implementation patterns

## Production Considerations

### Performance

- Use object caching (Redis, Memcached) for better performance
- Consider CDN integration for static MCP resources
- Monitor REST API response times

### Security

- Implement proper permission callbacks for all abilities
- Use WordPress nonces for state-changing operations
- Consider rate limiting for MCP endpoints

### Monitoring

- Set up logging for MCP operations
- Monitor error rates and response times
- Implement health checks for MCP servers

For more advanced topics, see our [Architecture Guide](../architecture/overview.md)
and [Creating Abilities Guide](../guides/creating-abilities.md).
