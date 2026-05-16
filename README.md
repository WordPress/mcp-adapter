# MCP Adapter

Part of the [**AI Building Blocks for WordPress** initiative](https://make.wordpress.org/ai/2025/07/17/ai-building-blocks)

The official WordPress package for MCP integration that exposes WordPress abilities as [Model Context Protocol (MCP)](https://modelcontextprotocol.io) tools, resources, and prompts for AI agents.

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/WordPress/mcp-adapter)

## Overview

This adapter bridges WordPress's Abilities API with the [MCP specification](https://modelcontextprotocol.io/specification/2025-11-25/), providing a standardized way for AI agents to interact with WordPress functionality. It includes HTTP and STDIO transport support, comprehensive error handling, and an extensible architecture for custom integrations.

## Features

### Core Functionality

- **Ability-to-MCP Conversion**: Automatically converts WordPress abilities into MCP tools, resources, and prompts
- **Multi-Server Management**: Create and manage multiple MCP servers with unique configurations
- **Extensible Transport Layer**:
    - **HTTP Transport**: Unified transport implementing [MCP 2025-11-25 specification](https://modelcontextprotocol.io/specification/2025-11-25/basic/transports) for HTTP-based communication
    - **STDIO Transport**: Process-based communication via standard input/output for local development and CLI integration
    - **Custom Transport Support**: Implement `McpTransportInterface` to create specialized communication protocols
    - **Multi-Transport Configuration**: Configure servers with multiple transport methods simultaneously
- **Flexible Error Handling**:
    - **Built-in Error Handler**: Default WordPress-compatible error logging included
    - **Custom Error Handlers**: Implement `McpErrorHandlerInterface` for custom logging, monitoring, or notification
      systems
    - **Server-specific Handlers**: Different error handling strategies per MCP server
- **Observability**:
    - **Built-in Observability**: Default zero-overhead metrics tracking with configurable handlers
    - **Custom Observability Handlers**: Implement `McpObservabilityHandlerInterface` for integration with monitoring
      systems
- **Validation**: Built-in validation for tools, resources, and prompts with extensible validation rules
- **Permission Control**: Granular permission checking for all exposed functionality with configurable [transport permissions](docs/guides/transport-permissions.md)

### MCP Component Support

- **[Tools](https://modelcontextprotocol.io/specification/2025-06-18/server/tools.md)**: Convert WordPress abilities into executable MCP tools for AI agent interactions
- **[Resources](https://modelcontextprotocol.io/specification/2025-06-18/server/resources.md)**: Expose WordPress data as MCP resources for contextual information access
- **[Prompts](https://modelcontextprotocol.io/specification/2025-06-18/server/prompts.md)**: Transform abilities into structured MCP prompts for AI guidance and templates
- **Server Discovery**: Automatic registration and discovery of MCP servers following MCP protocol standards
- **Built-in Abilities**: Core WordPress abilities for system introspection and ability management
- **CLI Integration**: WP-CLI commands supporting STDIO transport as defined in MCP specification

## Architecture

For a full breakdown of the component structure, see the [Architecture Overview](docs/architecture/overview.md).

## Dependencies

- **PHP**: >= 7.4
- **WordPress**: >= 6.9
- **[php-mcp-schema](https://github.com/WordPress/php-mcp-schema)** (`^0.1.0`): Typed DTOs for MCP protocol types — installed automatically via Composer

## Installation

### As a WordPress Plugin (Recommended)

MCP Adapter is designed to be installed as a WordPress plugin. To install you should download the latest stable release from the [GitHub Releases page](https://github.com/WordPress/mcp-adapter/releases/latest) and install it like any other WordPress plugin.

#### With WP-CLI

```bash
wp plugin install https://github.com/WordPress/mcp-adapter/releases/latest/download/mcp-adapter.zip --activate
```

#### With WP-Env

```jsonc
// .wp-env.json
{
  "$schema": "https://schemas.wp.org/trunk/wp-env.json",
  "plugins": [
    "https://github.com/WordPress/mcp-adapter/releases/latest/download/mcp-adapter.zip"
  ]
}
```

### As a Composer Library (for plugin developers)

Plugin developers may wish to install MCP Adapter as a Composer dependency to integrate MCP functionality into their own plugins.

```bash
composer require wordpress/mcp-adapter
```

#### Using Jetpack Autoloader (Highly Recommended)

When multiple plugins use the MCP Adapter, it's highly recommended to use the [Jetpack Autoloader](https://github.com/Automattic/jetpack-autoloader) to prevent version conflicts. The Jetpack Autoloader ensures that only the latest version of shared packages is loaded, eliminating conflicts when different plugins use different versions of the same dependency.

```bash
composer require automattic/jetpack-autoloader
```

Then load it in your main plugin file instead of the standard Composer autoloader:

```php
<?php
require_once plugin_dir_path( __FILE__ ) . 'vendor/autoload_packages.php';
```

### Using MCP Adapter in Your Plugin

Check availability and initialize on `plugins_loaded` so all plugins are available before the adapter starts:

```php
add_action( 'plugins_loaded', function() {
    if ( ! class_exists( 'WP\MCP\Core\McpAdapter' ) ) {
        // MCP Adapter is not active — show an admin notice or return early.
        return;
    }

    \WP\MCP\Core\McpAdapter::instance();
} );
```

## Basic Usage

The MCP Adapter automatically creates a default server that exposes registered WordPress abilities through a layered architecture. This provides immediate MCP functionality without requiring manual server configuration.

**How it works:**
- WordPress abilities registered via `wp_register_ability()` with the `meta.mcp.public` flag set to `true` are discoverable and executable on the default server via its built-in adapter tools
- On the default server, public abilities are accessed through `mcp-adapter/discover-abilities`, `mcp-adapter/get-ability-info`, and `mcp-adapter/execute-ability` rather than being auto-registered individually in `tools/list`
- Alternatively, abilities can be explicitly listed when creating a [custom MCP server](#creating-custom-mcp-servers); in that case, they can be exposed directly as MCP tools, resources, or prompts without requiring the `meta.mcp.public` flag
- The default server supports both HTTP and STDIO transports and supports multiple MCP protocol versions
- Built-in error handling and observability are included
- Access via HTTP: `/wp-json/mcp/mcp-adapter-default-server`
- Access via STDIO: `wp mcp-adapter serve --server=mcp-adapter-default-server`

### Settings page

Administrators can opt registered abilities into the default MCP server without writing PHP at **Settings → MCP Adapter**. The page lists every ability currently registered on the site and persists the selection in the `mcp_adapter_public_abilities` option. The same `wp_register_ability_args` filter is applied behind the scenes, so the developer flow above (setting `meta.mcp.public = true` directly at registration) remains the canonical path. The settings page is positioned for site owners who don't write PHP.

<details>
<summary><strong>Create a new ability (click to expand)</strong></summary>

```php
// Simply register a WordPress ability
add_action( 'wp_abilities_api_init', function() {
    wp_register_ability( 'my-plugin/get-posts', [
        'label' => 'Get Posts',
        'description' => 'Retrieve WordPress posts with optional filtering',
        'category' => 'site',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'numberposts' => [
                    'type' => 'integer',
                    'description' => 'Number of posts to retrieve',
                    'default' => 5,
                    'minimum' => 1,
                    'maximum' => 100
                ],
                'post_status' => [
                    'type' => 'string',
                    'description' => 'Post status to filter by',
                    'enum' => ['publish', 'draft', 'private'],
                    'default' => 'publish'
                ]
            ]
        ],
        'output_schema' => [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'ID' => ['type' => 'integer'],
                    'post_title' => ['type' => 'string'],
                    'post_content' => ['type' => 'string'],
                    'post_date' => ['type' => 'string'],
                    'post_author' => ['type' => 'string']
                ]
            ]
        ],
        'execute_callback' => function( $input ) {
            $args = [
                'numberposts' => $input['numberposts'] ?? 5,
                'post_status' => $input['post_status'] ?? 'publish'
            ];
            return get_posts( $args );
        },
        'permission_callback' => function() {
            return current_user_can( 'read' );
        },
        'meta' => [
            'mcp' => [
                'public' => true, // Required for default MCP server access
            ],
        ],
    ]);
});

// With the meta.mcp.public flag, the ability is exposed through the default MCP server.
// In the default server configuration, discover it via `discover-abilities`
// and invoke it via `mcp-adapter/execute-ability` rather than expecting
// it to appear as its own entry in `tools/list`.
// Without the meta.mcp.public flag, abilities are only accessible
// through custom MCP servers that explicitly list them.
```

</details>

For detailed information about creating WordPress abilities, see the [Abilities API developer documentation](https://developer.wordpress.org/news/2025/11/introducing-the-wordpress-abilities-api/).

### Connecting to MCP Servers

The MCP Adapter supports multiple connection methods. Here are examples for connecting with MCP clients:

#### STDIO Transport (Local Development)

For local development and testing, you can interact directly with MCP servers using WP-CLI commands:

```bash
# List all available MCP servers
wp mcp-adapter list

# Test the discover abilities tool to see all available WordPress abilities
echo '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"mcp-adapter-discover-abilities","arguments":{}}}' | wp mcp-adapter serve --user=admin --server=mcp-adapter-default-server

# Test listing available tools
echo '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}' | wp mcp-adapter serve --user=admin --server=mcp-adapter-default-server
```

#### MCP Client Configuration

Configure MCP clients (Claude Desktop, Claude Code, VS Code, Cursor, etc.) to connect to your WordPress MCP servers.

<details>
<summary><strong>STDIO Transport Configuration for local sites (click to expand)</strong></summary>

```json
{
  "mcpServers": {
    "wordpress-default": {
      "command": "wp",
      "args": [
        "--path=/path/to/your/wordpress/site",
        "mcp-adapter",
        "serve",
        "--server=mcp-adapter-default-server",
        "--user=admin"
      ]
    },
    "wordpress-custom": {
      "command": "wp",
      "args": [
        "--path=/path/to/your/wordpress/site",
        "mcp-adapter",
        "serve",
        "--server=your-custom-server-id",
        "--user=admin"
      ]
    }
  }
}
```

</details>

<details>
<summary><strong>HTTP Transport via Proxy (click to expand)</strong></summary>

The [`@automattic/mcp-wordpress-remote`](https://www.npmjs.com/package/@automattic/mcp-wordpress-remote) proxy runs locally and translates STDIO-based MCP communication from AI clients into HTTP REST API calls that WordPress understands. Authentication uses [WordPress Application Passwords](https://make.wordpress.org/core/2020/11/05/application-passwords-integration-guide/).

```json
{
  "mcpServers": {
    "wordpress-http-default": {
      "command": "npx",
      "args": [
        "-y",
        "@automattic/mcp-wordpress-remote@latest"
      ],
      "env": {
        "WP_API_URL": "http://your-site.test/wp-json/mcp/mcp-adapter-default-server",
        "LOG_FILE": "/path/to/logs/mcp-adapter.log",
        "WP_API_USERNAME": "your-username",
        "WP_API_PASSWORD": "your-application-password"
      }
    },
    "wordpress-http-custom": {
      "command": "npx",
      "args": [
        "-y",
        "@automattic/mcp-wordpress-remote@latest"
      ],
      "env": {
        "WP_API_URL": "http://your-site.test/wp-json/your-namespace/your-route",
        "LOG_FILE": "/path/to/logs/mcp-adapter.log",
        "WP_API_USERNAME": "your-username",
        "WP_API_PASSWORD": "your-application-password"
      }
    }
  }
}
```

</details>

## Advanced Usage

### Creating Custom MCP Servers

For advanced use cases, you can create custom MCP servers with specific configurations:

```php
add_action( 'mcp_adapter_init', function( $adapter ) {
    $adapter->create_server(
        'my-server-id',                    // Unique server identifier
        'my-namespace',                    // REST API namespace
        'mcp',                             // REST API route
        'My MCP Server',                   // Server name
        'Description of my server',        // Server description
        'v1.0.0',                          // Server version
        array(                             // Transport methods
            \WP\MCP\Transport\HttpTransport::class,
        ),
        \WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,        // Error handler
        \WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class,    // Observability handler
        array( 'my-plugin/my-ability' ),   // Abilities to expose as tools
        array(),                           // Resources (optional)
        array()                            // Prompts (optional)
    );
} );
```

### Custom Transport Implementation

The MCP Adapter includes production-ready HTTP transports. For specialized requirements like custom authentication, message queues, or enterprise integrations, you can create custom transport protocols.

See the [Custom Transports Guide](docs/guides/custom-transports.md) for detailed implementation instructions.


### Custom Transport Permissions

The MCP Adapter supports custom authentication logic through transport permission callbacks. Instead of the default `is_user_logged_in()` check, you can implement custom authentication for your MCP servers.

See the [Transport Permissions Guide](docs/guides/transport-permissions.md) for detailed authentication patterns.

### Custom Error Handler

The MCP Adapter includes a default WordPress-compatible error handler, but you can implement custom error handling to integrate with existing logging systems, monitoring tools, or meet specific requirements.

See the [Error Handling Guide](docs/guides/error-handling.md) for detailed implementation instructions.

### Custom Observability Handler

The MCP Adapter includes built-in observability for tracking metrics and events. You can implement custom observability handlers to integrate with monitoring systems, analytics platforms, or performance tracking tools.

See the [Observability Guide](docs/guides/observability.md) for detailed metrics tracking and custom handler implementation.

## Migration

- [Migration Guide: v0.5.0](docs/migration/v0.5.0.md) — Breaking changes and upgrade instructions
- [Migration Guide: v0.3.0](docs/migration/v0.3.0.md) — Transport, observability, and hook name changes

## License
[GPL-2.0-or-later](https://spdx.org/licenses/GPL-2.0-or-later.html)
