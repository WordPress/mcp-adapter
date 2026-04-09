# MCP Adapter

Part of the [**AI Building Blocks for WordPress** initiative](https://make.wordpress.org/ai/2025/07/17/ai-building-blocks)

The official WordPress package for MCP integration that exposes WordPress abilities as [Model Context Protocol (MCP)](https://modelcontextprotocol.io) tools, resources, and prompts for AI agents.

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/WordPress/mcp-adapter)

## Overview

This adapter bridges WordPress's Abilities API with the [MCP specification](https://modelcontextprotocol.io/specification/2025-06-18/), providing a standardized way for AI agents to interact with WordPress functionality. It includes HTTP and STDIO transport support, comprehensive error handling, and an extensible architecture for custom integrations.

## Features

### Core Functionality

- **Ability-to-MCP Conversion**: Automatically converts WordPress abilities into MCP tools, resources, and prompts
- **Multi-Server Management**: Create and manage multiple MCP servers with unique configurations
- **Extensible Transport Layer**:
    - **HTTP Transport**: Unified transport implementing [MCP 2025-06-18 specification](https://modelcontextprotocol.io/specification/2025-06-18/basic/transports.md) for HTTP-based communication
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

## Understanding Abilities as MCP Components

The MCP Adapter transforms WordPress abilities into MCP components:

- **Tools**: WordPress abilities become executable MCP tools for AI agent interactions
- **Resources**: WordPress abilities expose data as MCP resources for contextual information
- **Prompts**: WordPress abilities provide structured MCP prompts for AI guidance

For detailed information about MCP components, see the [Model Context Protocol specification](https://modelcontextprotocol.io/specification/2025-06-18/).


## Architecture

### Component Overview

```
./includes/
│   # Core system components
├── Core/
│   ├── McpAdapter.php         # Main registry and server management
│   ├── McpServer.php          # Individual server configuration
│   ├── McpComponentRegistry.php # Component registration and management
│   └── McpTransportFactory.php # Transport instantiation factory
│
│   # Built-in abilities for MCP functionality
├── Abilities/
│   ├── DiscoverAbilitiesAbility.php # Ability discovery
│   ├── ExecuteAbilityAbility.php    # Ability execution
│   └── GetAbilityInfoAbility.php    # Ability introspection
│
│   # CLI and STDIO transport support
├── Cli/
│   ├── McpCommand.php         # WP-CLI commands
│   └── StdioServerBridge.php  # STDIO transport bridge
│
│   # Business logic and MCP components
├── Domain/
│   │   # Shared contracts
│   ├── Contracts/
│   │   └── McpComponentInterface.php       # Contract for MCP components
│   │   # Shared utilities
│   ├── Utils/
│   │   ├── McpNameSanitizer.php            # MCP name sanitization
│   │   ├── McpValidator.php                # MCP name/URI/schema validation
│   │   ├── McpAnnotationMapper.php         # Annotation mapping from abilities
│   │   ├── SchemaTransformer.php           # Schema format transformation
│   │   ├── ContentBlockHelper.php          # Content block DTO factory
│   │   └── AbilityArgumentNormalizer.php   # Argument normalization
│   │   # MCP Tools implementation
│   ├── Tools/
│   │   ├── McpTool.php                   # Base tool class
│   │   ├── RegisterAbilityAsMcpTool.php  # Ability-to-tool conversion
│   │   └── McpToolValidator.php          # Tool validation
│   │   # MCP Resources implementation
│   ├── Resources/
│   │   ├── McpResource.php                   # Base resource class
│   │   ├── RegisterAbilityAsMcpResource.php  # Ability-to-resource conversion
│   │   └── McpResourceValidator.php          # Resource validation
│   │   # MCP Prompts implementation
│   └── Prompts/
│       ├── Contracts/                         # Prompt interfaces
│       │   └── McpPromptBuilderInterface.php  # Prompt builder interface
│       ├── McpPrompt.php                      # Base prompt class
│       ├── McpPromptBuilder.php               # Prompt builder implementation
│       ├── McpPromptValidator.php             # Prompt validation
│       └── RegisterAbilityAsMcpPrompt.php     # Ability-to-prompt conversion
│
│   # Request processing handlers
├── Handlers/
│   ├── HandlerHelperTrait.php  # Shared handler utilities
│   ├── Initialize/              # Initialization handlers
│   ├── Tools/                   # Tool request handlers
│   ├── Resources/               # Resource request handlers
│   ├── Prompts/                 # Prompt request handlers
│   └── System/                  # System request handlers
│
│   # Infrastructure concerns
├── Infrastructure/
│   │   # Error handling system
│   ├── ErrorHandling/
│   │   ├── Contracts/                        # Error handling interfaces
│   │   │   └── McpErrorHandlerInterface.php  # Error handler interface
│   │   ├── ErrorLogMcpErrorHandler.php       # Default error handler
│   │   ├── NullMcpErrorHandler.php           # Null object pattern
│   │   └── McpErrorFactory.php               # Error response factory
│   │   # Monitoring and observability
│   └── Observability/
│       ├── Contracts/                                # Observability interfaces
│       │   └── McpObservabilityHandlerInterface.php  # Observability interface
│       ├── ErrorLogMcpObservabilityHandler.php       # Default handler
│       ├── NullMcpObservabilityHandler.php           # Null object pattern
│       ├── McpObservabilityHelperTrait.php           # Helper trait
│       ├── ConsoleObservabilityHandler.php          # Console output handler
│       └── FailureReason.php                        # Standardized failure reasons
│
│   # Transport layer implementations
├── Transport/
│   ├── Contracts/
│   │   ├── McpTransportInterface.php     # Base transport interface
│   │   └── McpRestTransportInterface.php # REST transport interface
│   ├── HttpTransport.php                 # Unified HTTP transport (MCP 2025-06-18)
│   │   # Transport infrastructure
│   └── Infrastructure/
│       ├── HttpRequestContext.php       # HTTP request context
│       ├── HttpRequestHandler.php       # HTTP request processing
│       ├── HttpSessionValidator.php     # Session validation
│       ├── JsonRpcResponseBuilder.php   # JSON-RPC response building
│       ├── McpTransportContext.php      # Transport context
│       ├── RequestRouter.php            # Request routing
│       └── SessionManager.php           # Session management
│
│   # Server factories
├── Servers/
    └── DefaultServerFactory.php  # Default server creation
```

### Key Classes

#### `McpAdapter`

The main registry class that manages multiple MCP servers:

- **Singleton Pattern**: Ensures single instance across the application
- **Server Management**: Create, configure, and retrieve MCP servers
- **Initialization**: Handles WordPress integration and action hooks
- **REST API Integration**: Automatically integrates with WordPress REST API

#### `McpServer`

Individual server management with comprehensive configuration:

- **Server Identity**: Unique ID, namespace, route, name, and description
- **Component Registration**: Tools, resources, and prompts management
- **Transport Configuration**: Multiple transport method support
- **Error Handling**: Server-specific error handling and logging
- **Validation**: Built-in validation for all registered components

## Dependencies

### Required Dependencies

- **PHP**: >= 7.4
- **WordPress**: >= 6.8 (6.9+ recommended; 7.0+ includes the MCP Adapter in core)
- **[WordPress Abilities API](https://make.wordpress.org/core/2025/11/10/abilities-api-in-wordpress-6-9/)**: Included in WordPress core since 6.9. For WordPress 6.8, install the [Abilities API plugin](https://github.com/WordPress/abilities-api) separately (note: the plugin repository was archived in February 2026).
- **[php-mcp-schema](https://github.com/WordPress/php-mcp-schema)** (^0.1.0): Typed DTOs for MCP protocol types (MCP 2025-11-25)

### WordPress Abilities API Integration

Since WordPress 6.9, the [Abilities API](https://make.wordpress.org/core/2025/11/10/abilities-api-in-wordpress-6-9/) is a core API and does not require a separate plugin. WordPress 7.0 further expands the API with a [client-side JavaScript counterpart](https://make.wordpress.org/core/2026/03/24/client-side-abilities-api-in-wordpress-7-0/) for browser-based abilities.

The Abilities API provides:

- Standardized ability registration (`wp_register_ability()`)
- Ability retrieval and management (`wp_get_ability()`)
- Schema definition for inputs and outputs
- Permission callback system
- Execute callback system

## Installation

### With Composer (Primary Installation Method)

The MCP Adapter is designed to be installed as a Composer package. This is the primary and recommended installation method:

```bash
composer require wordpress/mcp-adapter
```

> **Note:** On WordPress 6.8, you must also install the Abilities API separately: `composer require wordpress/abilities-api wordpress/mcp-adapter`. On WordPress 6.9+, the Abilities API is built into core and does not need to be installed. On WordPress 7.0+, the MCP Adapter itself is bundled in core and Composer installation is only needed if you want to pin a specific version or use it as a library dependency.

#### Using Jetpack Autoloader (Highly Recommended)

When multiple plugins use the MCP Adapter, it's highly recommended to use the [Jetpack Autoloader](https://github.com/Automattic/jetpack-autoloader) to prevent version conflicts. The Jetpack Autoloader ensures that only the latest version of shared packages is loaded, eliminating conflicts when different plugins use different versions of the same dependency.

Add the Jetpack Autoloader to your project:

```bash
composer require automattic/jetpack-autoloader
```

Then load it in your main plugin file instead of the standard Composer autoloader:

```php
<?php
// Load the Jetpack autoloader instead of vendor/autoload.php
require_once plugin_dir_path( __FILE__ ) . 'vendor/autoload_packages.php';
```

**Benefits of using Jetpack Autoloader:**
- **Version Conflict Resolution**: Automatically loads the latest version of shared packages
- **Plugin Compatibility**: Prevents errors when multiple plugins use different versions of MCP Adapter
- **WordPress Optimized**: Designed specifically for WordPress plugin development
- **Automatic Management**: No manual intervention needed when plugins update their dependencies

### As a Plugin (Alternative Method)

Alternatively, you can install the MCP Adapter as a traditional WordPress plugin, though the Composer package method is preferred for most use cases.

#### From GitHub Releases

Download the latest stable release from the [GitHub Releases page](https://github.com/WordPress/mcp-adapter/releases/latest).

#### Development Version (Git Clone)

For the latest development version or to contribute to the project:

```bash
# Clone the repository
git clone https://github.com/WordPress/mcp-adapter.git wp-content/plugins/mcp-adapter

# Navigate to the plugin directory
cd wp-content/plugins/mcp-adapter

# Install dependencies
composer install
```

This will give you the latest development version from the `trunk` branch with all dependencies installed.

#### With WP-Env

```jsonc
// .wp-env.json
{
  "$schema": "https://schemas.wp.org/trunk/wp-env.json",
  // ... other config ...
  "plugins": [
    "WordPress/mcp-adapter",
    // ... other plugins ...
  ],
  // ... more config ...
}
```

> **Note:** On WordPress 6.8, also add `"WordPress/abilities-api"` to the plugins array. On WordPress 6.9+, the Abilities API is included in core.

### Using MCP Adapter in Your Plugin

Using the MCP Adapter in your plugin is straightforward, just check availability and instantiate:

```php
use WP\MCP\Core\McpAdapter;

// 1. Check if MCP Adapter is available
if ( ! class_exists( McpAdapter::class ) ) {
    // Handle missing dependency (show admin notice, etc.)
    return;
}

// 2. Initialize the adapter
McpAdapter::instance();
// That's it!
```

## Basic Usage

The MCP Adapter automatically creates a default server that exposes registered WordPress abilities through a layered architecture. This provides immediate MCP functionality without requiring manual server configuration.

**How it works:**
- WordPress abilities registered via `wp_register_ability()` with the `meta.mcp.public` flag set to `true` are discoverable and executable on the default server via its built-in adapter tools
- On the default server, public abilities are accessed through `mcp-adapter/discover-abilities`, `mcp-adapter/get-ability-info`, and `mcp-adapter/execute-ability` rather than being auto-registered individually in `tools/list`
- Alternatively, abilities can be explicitly listed when creating a [custom MCP server](#creating-custom-mcp-servers); in that case, they can be exposed directly as MCP tools, resources, or prompts without requiring the `meta.mcp.public` flag
- The default server supports both HTTP and STDIO transports with MCP protocol version negotiation, including 2025-06-18 and 2025-11-25
- Built-in error handling and observability are included
- Access via HTTP: `/wp-json/mcp/mcp-adapter-default-server`
- Access via STDIO: `wp mcp-adapter serve --server=mcp-adapter-default-server`

<details>
<summary><strong>Create a new ability (click to expand)</strong></summary>

```php
// Simply register a WordPress ability
add_action( 'wp_abilities_api_init', function() {
    wp_register_ability( 'my-plugin/get-posts', [
        'label' => 'Get Posts',
        'description' => 'Retrieve WordPress posts with optional filtering',
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

> **WordPress.com users:** WordPress.com includes [built-in MCP support](https://developer.wordpress.com/docs/mcp/) with OAuth 2.1 authentication. No additional plugin installation or proxy configuration is required.

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
add_action('mcp_adapter_init', function($adapter) {
    $adapter->create_server(
        'my-server-id',                    // Unique server identifier
        'my-namespace',                    // REST API namespace
        'mcp',                            // REST API route
        'My MCP Server',                  // Server name
        'Description of my server',       // Server description
        'v1.0.0',                        // Server version
        [                                 // Transport methods
            \WP\MCP\Transport\HttpTransport::class,  // Recommended: MCP 2025-06-18 compliant
        ],
        \WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class, // Error handler
        \WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class, // Observability handler
        ['my-plugin/my-ability'],         // Abilities to expose as tools
        [],                              // Resources (optional)
        [],                              // Prompts (optional)
    );
});
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
