# Getting Started with MCP Adapter

This guide will help you quickly set up the WordPress MCP Adapter to expose your WordPress abilities as MCP (Model Context Protocol) tools, resources, and prompts.

## Quick Overview

The MCP Adapter transforms WordPress abilities into AI-accessible interfaces, allowing AI agents to interact with your WordPress functionality through standardized protocols.

## Prerequisites

- **PHP 7.4 or higher**
- **WordPress 6.8 or higher**
- **WordPress Abilities API**
- **Composer** (recommended)

## Quick Start

### Step 1: Install MCP Adapter

**Recommended: Composer Package**
```bash
composer require wordpress/abilities-api wordpress/mcp-adapter
```

**Alternative: WordPress Plugin**
```bash
# Clone to plugins directory
git clone https://github.com/WordPress/mcp-adapter.git wp-content/plugins/mcp-adapter
cd wp-content/plugins/mcp-adapter
composer install
wp plugin activate mcp-adapter
```

### Step 2: Register a Simple Ability

Create a WordPress ability that will be exposed via MCP:

```php
// Register a simple ability to get site information
add_action( 'wp_abilities_api_init', function() {
    wp_register_ability( 'my-plugin/get-site-info', [
        'label' => 'Get Site Information',
        'description' => 'Retrieves basic information about the current WordPress site',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'include_stats' => [
                    'type' => 'boolean',
                    'description' => 'Whether to include post/page statistics',
                    'default' => false
                ]
            ]
        ],
        'execute_callback' => function( $input ) {
            $result = [
                'site_name' => get_bloginfo( 'name' ),
                'site_url' => get_site_url(),
                'description' => get_bloginfo( 'description' )
            ];
            
            if ( $input['include_stats'] ?? false ) {
                $result['stats'] = [
                    'post_count' => wp_count_posts( 'post' )->publish,
                    'page_count' => wp_count_posts( 'page' )->publish
                ];
            }
            
            return $result;
        },
        'permission_callback' => function() {
            return current_user_can( 'read' );
        }
    ]);
});
```

### Step 3: Initialize MCP Adapter

**If using Composer with Jetpack Autoloader (Recommended):**
```php
<?php
// Load Jetpack autoloader (handles version conflicts)
require_once __DIR__ . '/vendor/autoload_packages.php';

use WP\MCP\Core\McpAdapter;

// Initialize the adapter
McpAdapter::instance();
```

**If using standard Composer autoloader:**
```php
<?php
// Load standard Composer autoloader
require_once __DIR__ . '/vendor/autoload.php';

use WP\MCP\Core\McpAdapter;

// Initialize the adapter
McpAdapter::instance();
```

**If using WordPress Plugin:**
The adapter initializes automatically when the plugin is activated.

### Step 4: Create Your MCP Server (Optional)

The adapter creates a default server automatically, but you can create custom servers:

```php
add_action( 'mcp_adapter_init', function( $adapter ) {
    $adapter->create_server(
        'my-first-server',                          // Unique server ID
        'my-plugin',                                // REST API namespace
        'mcp',                                      // REST API route
        'My First MCP Server',                      // Human-readable name
        'A simple MCP server for demonstration',    // Description
        '1.0.0',                                    // Version
        [ \WP\MCP\Transport\HttpTransport::class ], // Transport methods
        \WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class, // Error handler
        [ 'my-plugin/get-site-info' ]              // Abilities to expose as tools
    );
});
```

### Step 5: Test Your Setup

Test your MCP server:

**Using WP-CLI (STDIO transport):**
```bash
# List available tools
echo '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}' | wp mcp-adapter serve --user=admin --server=mcp-adapter-default-server

# Execute the site info tool
echo '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"my-plugin-get-site-info","arguments":{"include_stats":true}}}' | wp mcp-adapter serve --user=admin --server=mcp-adapter-default-server
```

**Using HTTP REST API:**
```bash
# Test basic connectivity
curl "https://yoursite.com/wp-json/"

# Test MCP endpoint (requires authentication)
curl -X POST "https://yoursite.com/wp-json/mcp/mcp-adapter-default-server" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}'
```

## What Just Happened?

1. **Ability Registration**: You created a WordPress ability that retrieves site information
2. **Automatic Exposure**: The MCP Adapter automatically exposes your ability as an MCP tool
3. **REST API Integration**: The adapter created REST endpoints for MCP communication
4. **AI Agent Access**: AI agents can now discover and use your functionality

## Next Steps

### Learn More
- **[Creating Abilities](../guides/creating-abilities.md)** - Build tools, resources, and prompts
- **[Installation Guide](installation.md)** - Detailed installation options
- **[Architecture Overview](../architecture/overview.md)** - Understand system design

### Advanced Topics
- **[Error Handling](../guides/error-handling.md)** - Custom logging and monitoring
- **[Transport Permissions](../guides/transport-permissions.md)** - Authentication and authorization
- **[CLI Usage](../guides/cli-usage.md)** - Command-line MCP server management

## Troubleshooting

**MCP Adapter not found?**
- Verify installation method (Composer vs Plugin)
- Check autoloader is loaded correctly
- Ensure WordPress Abilities API is available

**REST API not responding?**
- Test basic REST API: `curl "https://yoursite.com/wp-json/"`
- Verify permalink structure is not "Plain"
- Check WordPress user authentication

**Tool not appearing?**
- Confirm ability is registered during `wp_abilities_api_init`
- Verify ability name matches exactly in server configuration
- Check permission callback allows current user

For detailed troubleshooting, see the [Installation Guide](installation.md#troubleshooting).
