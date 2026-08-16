# Installation Guide

MCP Adapter is distributed as a WordPress plugin. Install and activate it like any other plugin. Requires WordPress 6.9 or newer.

## Installing the plugin

Download the latest release from [GitHub](https://github.com/WordPress/mcp-adapter/releases/latest) and install it like any other WordPress plugin, or use WP-CLI:

```bash
wp plugin install https://github.com/WordPress/mcp-adapter/releases/latest/download/mcp-adapter.zip --activate
```

The plugin automatically initializes and creates a default MCP server at `/wp-json/mcp/mcp-adapter-default-server`.

## Depending on MCP Adapter from your own plugin

The `WP\MCP` classes are provided by the MCP Adapter plugin. Declare it as a plugin dependency using the `Requires Plugins` field in your [plugin header](https://developer.wordpress.org/plugins/plugin-basics/header-requirements/).

If you currently bundle MCP Adapter in your plugin's `vendor/` directory, see [Composer library to canonical plugin](../migration/composer-to-plugin.md) for how to move off it.

```php
<?php
/**
 * Plugin Name:      My MCP Plugin
 * Description:      Demonstrates MCP Adapter integration
 * Version:          1.0.0
 * Requires Plugins: mcp-adapter
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
                'category' => 'site',
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
            null, // observability handler (null = use default)
            [ 'my-plugin/get-posts' ] // tools
        );
    }
    
    public function missing_mcp_adapter_notice() {
        echo '<div class="notice notice-error"><p>';
        echo 'My MCP Plugin requires the MCP Adapter plugin to be active.';
        echo '</p></div>';
    }
    
    public function missing_abilities_api_notice() {
        echo '<div class="notice notice-error"><p>';
        echo 'My MCP Plugin requires WordPress 6.9 or newer (Abilities API is included in core).';
        echo '</p></div>';
    }
}

new MyMcpPlugin();
```

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

**"The Composer autoloader was not found"**
- Only affects source checkouts; the release zip ships with its `vendor/` directory
- Run `composer install` in the plugin directory
- Check `vendor/autoload_packages.php` exists

**"WordPress Abilities API not available"**
- Requires WordPress 6.9 or higher. The Abilities API is part of core — there is no separate plugin to install.

**REST API not responding**
- Check WordPress REST API is enabled
- Verify permalink structure is not "Plain"
- Test basic REST API: `curl "https://yoursite.com/wp-json/"`

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
- **WordPress**: >= 6.9

### Optional
- **WP-CLI**: For command-line MCP server testing
- **Composer**: Only needed to build from a source checkout; release builds ship with their dependencies

The MCP Adapter automatically handles initialization and creates a default server when activated.
