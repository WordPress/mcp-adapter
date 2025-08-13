# Getting Started with MCP Adapter

Welcome to the MCP Adapter! This guide will help you quickly set up and start using the WordPress MCP Adapter to expose
your WordPress abilities as MCP (Model Context Protocol) tools, resources, and prompts.

## Quick Overview

The MCP Adapter transforms WordPress abilities into AI-accessible interfaces, allowing AI agents to interact with your
WordPress functionality through standardized protocols. In just a few steps, you'll have a working MCP server that can
expose your WordPress capabilities to AI systems.

## Prerequisites

Before you begin, ensure you have:

- **PHP 8.1 or higher**
- **WordPress with Abilities API** loaded
- **Basic understanding of WordPress plugins/themes**
- **Optional**: Composer (for enhanced dependency management)

## 5-Minute Quick Start

### Step 1: Load the MCP Adapter

Add this to your plugin or theme's main file:

```php
<?php
// Check if the class isn't already loaded by another plugin
if ( ! class_exists( 'WP\MCP\Core\McpAdapter' ) ) {
    // Load the Jetpack autoloader
    if ( is_file( ABSPATH . 'wp-content/lib/mcp-adapter/vendor/autoload_packages.php' ) ) {
        require_once ABSPATH . 'wp-content/lib/mcp-adapter/vendor/autoload_packages.php';
    }
}
```

### Step 2: Register a Simple Ability

First, let's create a simple WordPress ability that we'll expose via MCP:

```php
// Register a simple ability to get site information
add_action( 'abilities_api_init', function() {
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
        'output_schema' => [
            'type' => 'object',
            'properties' => [
                'site_name' => ['type' => 'string'],
                'site_url' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'stats' => [
                    'type' => 'object',
                    'properties' => [
                        'post_count' => ['type' => 'integer'],
                        'page_count' => ['type' => 'integer']
                    ]
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
            // Allow any authenticated user
            return is_user_logged_in();
        }
    ]);
});
```

### Step 3: Create Your MCP Server

Now let's create an MCP server that exposes this ability:

```php
use WP\MCP\Core\McpAdapter;

// Hook into the MCP adapter initialization
add_action( 'mcp_adapter_init', function( $adapter ) {
    $adapter->create_server(
        'my-first-server',                          // Unique server ID
        'my-plugin',                                // REST API namespace
        'mcp',                                      // REST API route
        'My First MCP Server',                      // Human-readable name
        'A simple MCP server for demonstration',    // Description
        '1.0.0',                                    // Version
        [                                           // Transport methods
            \WP\MCP\Transport\Http\RestTransport::class,
        ],
        \WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class, // Error handler
        [                                           // Abilities to expose as tools
            'my-plugin/get-site-info'
        ]
    );
});
```

### Step 4: Test Your Setup

That's it! Your MCP server is now running. You can test it by making a REST API request:

```bash
# List available tools
curl -X POST "https://yoursite.com/wp-json/my-plugin/mcp" \
  -H "Content-Type: application/json" \
  -d '{
    "method": "tools/list"
  }'

# Execute the site info tool
curl -X POST "https://yoursite.com/wp-json/my-plugin/mcp" \
  -H "Content-Type: application/json" \
  -d '{
    "method": "tools/call",
    "params": {
      "name": "my-plugin--get-site-info",
      "arguments": {
        "include_stats": true
      }
    }
  }'
```

## What Just Happened?

1. **Ability Registration**: You created a WordPress ability that can retrieve site information
2. **MCP Server Creation**: You set up an MCP server that exposes this ability as a tool
3. **REST API Integration**: The adapter automatically created REST endpoints for your MCP server
4. **Tool Availability**: AI agents can now discover and use your `get-site-info` functionality

## Next Steps

Now that you have a working setup, explore these areas:

### **Expand Your Tools**

- [Creating More Abilities](../guides/creating-abilities.md) - Learn to build complex abilities
- [Working with Resources](../guides/creating-abilities.md#resources) - Expose data as MCP resources
- [Building Prompts](../guides/creating-abilities.md#prompts) - Create AI guidance systems

### **Customize Your Setup**

- [Custom Transport Layers](../guides/custom-transports.md) - Build specialized communication protocols
- [Error Handling](../guides/error-handling.md) - Implement custom logging and monitoring
- [Multi-Server Configurations](../guides/multi-server-setup.md) - Manage multiple MCP endpoints

### **Real-World Examples**

- [Creating Abilities](../guides/creating-abilities.md) - Complete implementation guide
- [Architecture Overview](../architecture/overview.md) - Understand the system design

## Common Issues

**Server not responding?**

- Verify the autoloader is loaded correctly
- Check that WordPress Abilities API is available
- Ensure your REST API is working (`/wp-json/`)

**Permission errors?**

- Review your ability's `permission_callback`
- Check WordPress user authentication
- Verify REST API permissions

**Tool not appearing?**

- Confirm ability is registered before `mcp_adapter_init`
- Check server configuration
- Verify ability name matches exactly

For more troubleshooting help, see our [Troubleshooting Guide](../troubleshooting/common-issues.md).

---

**Ready to dive deeper?** Explore the [Creating Abilities guide](../guides/creating-abilities.md) or check
the [Architecture Overview](../architecture/overview.md).
