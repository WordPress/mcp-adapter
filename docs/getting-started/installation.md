# Installation Guide

This guide covers different installation methods for the MCP Adapter.

## Installation Methods

### Method 1: Composer Package (Recommended)

The MCP Adapter is designed to be installed as a Composer package. This is the primary and recommended installation method:

```bash
composer require wordpress/abilities-api wordpress/mcp-adapter
```

#### Using Jetpack Autoloader (Highly Recommended)

When multiple plugins use the MCP Adapter, use the [Jetpack Autoloader](https://github.com/Automattic/jetpack-autoloader) to prevent version conflicts:

```bash
composer require automattic/jetpack-autoloader
```

Then load it in your plugin:

```php
<?php
// Load the Jetpack autoloader instead of vendor/autoload.php
require_once plugin_dir_path( __FILE__ ) . 'vendor/autoload_packages.php';

use WP\MCP\Core\McpAdapter;

// Initialize the adapter
McpAdapter::instance();
```

#### Benefits
- Version conflict resolution
- Plugin compatibility 
- WordPress optimized
- Automatic dependency management

#### Using MCP Adapter in Your Plugin

Once the MCP Adapter plugin is active, you can use it in your own plugins:

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
        // Check if MCP Adapter is available
        if ( ! class_exists( 'WP\MCP\Core\McpAdapter' ) ) {
            add_action( 'admin_notices', [ $this, 'missing_mcp_adapter_notice' ] );
            return;
        }
        
        // Check if Abilities API is available
        if ( ! function_exists( 'wp_register_ability' ) ) {
            add_action( 'admin_notices', [ $this, 'missing_abilities_api_notice' ] );
            return;
        }
        
        // Register your abilities and MCP server
        $this->register_abilities();
        $this->setup_mcp_server();
    }
    
    private function register_abilities() {
        add_action( 'wp_abilities_api_init', function() {
            wp_register_ability( 'my-plugin/get-posts', [
                'label' => 'Get Posts',
                'description' => 'Retrieve WordPress posts',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'numberposts' => [
                            'type' => 'integer',
                            'default' => 5,
                            'minimum' => 1,
                            'maximum' => 100
                        ]
                    ]
                ],
                'execute_callback' => function( $input ) {
                    return get_posts( [ 'numberposts' => $input['numberposts'] ?? 5 ] );
                },
                'permission_callback' => function() {
                    return current_user_can( 'read' );
                }
            ]);
        });
    }
    
    private function setup_mcp_server() {
        add_action( 'mcp_adapter_init', [ $this, 'create_mcp_server' ] );
    }
    
    public function create_mcp_server( $adapter ) {
        $adapter->create_server(
            'my-plugin-server',
            'my-plugin',
            'mcp',
            'My Plugin MCP Server',
            'Custom MCP server for my plugin',
            '1.0.0',
            [ \WP\MCP\Transport\HttpTransport::class ],
            \WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
            [ 'my-plugin/get-posts' ]
        );
    }
    
    public function missing_mcp_adapter_notice() {
        echo '<div class="notice notice-error"><p>';
        echo 'My MCP Plugin requires the MCP Adapter plugin to be active.';
        echo '</p></div>';
    }
    
    public function missing_abilities_api_notice() {
        echo '<div class="notice notice-error"><p>';
        echo 'My MCP Plugin requires the WordPress Abilities API to be loaded.';
        echo '</p></div>';
    }
}

new MyMcpPlugin();
```

### Method 2: WordPress Plugin (Alternative)

Alternatively, you can install the MCP Adapter as a traditional WordPress plugin:

#### From GitHub

1. **Download or clone** the plugin:
   ```bash
   # Clone to your plugins directory
   cd /path/to/your/wordpress/wp-content/plugins/
   git clone https://github.com/WordPress/mcp-adapter.git
   ```

2. **Install dependencies**:
   ```bash
   cd mcp-adapter
   composer install
   ```

3. **Activate the plugin** in WordPress admin or via WP-CLI:
   ```bash
   wp plugin activate mcp-adapter
   ```

The plugin automatically initializes and creates a default MCP server at `/wp-json/mcp/mcp-adapter-default-server`.

## Verifying Installation

### Check Plugin Status

1. **WordPress Admin**: Go to Plugins → Installed Plugins and verify "MCP Adapter" is active

2. **WP-CLI**: Check plugin status:
   ```bash
   wp plugin status mcp-adapter
   ```

3. **REST API**: Test the default MCP server:
   ```bash
   # Test basic connectivity
   curl "https://yoursite.com/wp-json/"
   
   # Test MCP endpoint (requires authentication)
   curl -X POST "https://yoursite.com/wp-json/mcp/mcp-adapter-default-server" \
     -H "Content-Type: application/json" \
     -d '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}'
   ```

### Quick Test

Add this to a plugin or theme temporarily:

```php
add_action( 'wp_loaded', function() {
    if ( class_exists( 'WP\MCP\Core\McpAdapter' ) ) {
        error_log( 'MCP Adapter is loaded and ready' );
    } else {
        error_log( 'MCP Adapter not found' );
    }
});
```

## Troubleshooting

### Common Issues

**MCP Adapter plugin not found**
- Verify the plugin is installed in `wp-content/plugins/mcp-adapter/`
- Check the plugin is activated in WordPress admin
- Run `composer install` in the plugin directory

**"WordPress Abilities API not available"**
- Install and activate the WordPress Abilities API plugin
- Verify `wp_register_ability()` function exists

**REST API not responding**
- Check WordPress REST API is enabled
- Verify permalink structure is not "Plain"
- Test basic REST API: `curl "https://yoursite.com/wp-json/"`

**Composer autoloader missing**
- Run `composer install` in the plugin directory
- Check `vendor/autoload.php` exists

### Debug Mode

Enable debug logging:

```php
// Add to wp-config.php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
```

Check debug log for MCP Adapter messages.

## Next Steps

Once installation is complete:

1. **Read the [README](../../README.md)** for basic usage examples
2. **Follow [Creating Abilities](../guides/creating-abilities.md)** to build your MCP tools
3. **Review [Architecture Overview](../architecture/overview.md)** for system design

## Dependencies

### Required
- **PHP**: >= 7.4
- **WordPress**: >= 6.8
- **WordPress Abilities API**: For ability registration

### Optional
- **Composer**: For dependency management
- **WP-CLI**: For command-line MCP server testing

The MCP Adapter automatically handles initialization and creates a default server when activated.
