# MCP Adapter

[*Part of the **AI Building Blocks for WordPress** initiative*](https://make.wordpress.org/ai/2025/07/17/ai-building-blocks)

A canonical plugin for WordPress that provides the adapter for the WordPress Abilities API, enabling WordPress abilities to be exposed as
MCP (Model Context Protocol) tools, resources, and prompts. This adapter serves as the foundation for integrating
WordPress abilities with AI agents through the MCP specification.

## Overview

The MCP Adapter bridges the gap between WordPress's Abilities API and the Model Context Protocol (MCP), allowing
WordPress applications to expose their functionality to AI agents in a standardized, secure, and extensible way. It
provides a clean abstraction layer that converts WordPress abilities into MCP-compatible interfaces.

**Built for Extensibility**: The adapter ships with production-ready REST API and streaming transport protocols, plus a
default error handling system. However, it's designed to be easily extended - create custom transport protocols for
specialized communication needs or implement custom error handlers for advanced logging, monitoring, and notification
systems.

## Features

### Core Functionality

- **Ability-to-MCP Conversion**: Automatically converts WordPress abilities into MCP tools, resources, and prompts
- **Multi-Server Management**: Create and manage multiple MCP servers with unique configurations
- **Extensible Transport Layer**:
    - **Built-in Transports**: REST API (`RestTransport`) and Streaming (`StreamableTransport`) protocols included
    - **Custom Transport Support**: Implement `McpTransportInterface` to create custom communication protocols
    - **Multiple Transport per Server**: Configure servers with multiple transport methods simultaneously
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

- **Tools**: Convert abilities into executable MCP tools
- **Resources**: Expose abilities as MCP resources for data access
- **Prompts**: Transform abilities into structured MCP prompts
- **Server Discovery**: Automatic registration and discovery of MCP servers

## Understanding Abilities as MCP Components

The MCP Adapter's core strength lies in its ability to transform WordPress abilities into different MCP component types,
each serving distinct interaction patterns with AI agents.

### Abilities as Tools

**Purpose**: Interactive, action-oriented functionality that AI agents can execute with specific parameters.

**When to Use**:

- Operations that modify data or state (creating posts, updating settings)
- Search and query operations that require dynamic parameters
- Actions that return computed results based on input parameters
- Functions that perform business logic or data processing

**Characteristics**:

- Accept input parameters defined by the ability's input schema
- Execute the ability's callback function with provided arguments
- Return structured results based on the ability's output schema
- Respect permission callbacks for access control
- Can have side effects (create, update, delete operations)

### Abilities as Resources

**Purpose**: Static or semi-static data access that provides information without requiring complex input parameters.

**When to Use**:

- Providing current user information or site metadata
- Exposing configuration data or system status
- Offering read-only access to data collections
- Sharing contextual information that doesn't change frequently

**Characteristics**:

- Primarily data retrieval operations with minimal or no input parameters
- Focus on providing information rather than performing actions
- Results are typically cacheable and may not change frequently
- Often used for context gathering by AI agents
- Generally read-only operations without side effects

### Abilities as Prompts

**Purpose**: Structured templates that guide AI agents in generating contextually appropriate responses or suggestions.

**When to Use**:

- Providing advisory content (SEO recommendations, content strategy)
- Generating analysis reports (performance assessments, security audits)
- Offering structured prompts for content generation or optimization

**Characteristics**:

- Focus on generating human-readable guidance and recommendations
- May incorporate data from other abilities or WordPress APIs
- Designed to provide actionable insights and suggestions
- Often combine multiple data sources to create comprehensive advice
- Results are typically formatted for direct presentation to users

### Component Selection Strategy

The choice between tools, resources, and prompts depends on the intended interaction pattern:

- **Choose Tools** for operations requiring user input and dynamic execution
- **Choose Resources** for providing contextual data and system information
- **Choose Prompts** for generating guidance, analysis, and recommendations

The same WordPress ability can potentially be exposed through multiple component types, allowing different interaction
patterns for various use cases.

## Architecture

### Component Overview

```
./includes/
│   # Core system components
├── Core/
│   ├── McpAdapter.php # Main registry and server management
│   └── McpServer.php  # Individual server configuration
│
│   # Business logic and MCP components
├── Domain/
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
│   ├── Initialize/  # Initialization handlers
│   ├── Tools/       # Tool request handlers
│   ├── Resources/   # Resource request handlers
│   ├── Prompts/     # Prompt request handlers
│   └── System/      # System request handlers
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
│       └── McpObservabilityHelperTrait.php           # Helper trait
│
│   # Transport layer implementations
├─── Transport/
│   ├── Contracts/
│   │   └── McpTransportInterface.php  # Transport interface
│   │   # HTTP-based transports
│   │   # Transport interfaces
│   ├── Http/
│   │   ├── RestTransport.php        # REST API transport
│   │   └── StreamableTransport.php  # Streaming transport
│   │   # Transport infrastructure
│   └── Infrastructure/
│       ├── McpRequestRouter.php         # Request routing
│       ├── McpTransportContext.php      # Transport context
│       └── McpTransportHelperTrait.php  # Helper trait
│
│   # Plugin Wrapper - these won't be needed if/when merged into core.
├── Autoloader.php  # PSR-4 autoloader.
└── Plugin.php      # Plugin entrypoint.
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
- **[WordPress Abilities API](https://github.com/WordPress/abilities-api)**: For ability registration and management

### WordPress Abilities API Integration

This adapter requires the [WordPress Abilities API](https://github.com/WordPress/abilities-api), which provides:

- Standardized ability registration (`wp_register_ability()`)
- Ability retrieval and management (`wp_get_ability()`)
- Schema definition for inputs and outputs
- Permission callback system
- Execute callback system

## Installation

### As a plugin

The best and easiest way to try and use the MCP Adapter is to install it as a plugin by downloading the latest release from the [GitHub Releases page](https://github.com/WordPress/mcp-adapter/releases/latest).

#### With WP-CLI

```bash
wp plugin install https://github.com/WordPress/mcp-adapter/releases/latest/download/mcp-adapter.zip
```

#### With WP-Env

```jsonc
// .wp-env.json
{
  "$schema": "https://schemas.wp.org/trunk/wp-env.json",
  // ... other config ...
  "plugins": [
    "WordPress/abilities-api",
    "WordPress/mcp-adapter",
    // ... other plugins ...
  ],
  // ... more config ...
}
```

#### With Composer

Until the plugin is available on Packagist, you will need to add the repository to your `composer.json` file.


```jsonc
{
  "repositories": [
    {
      "type": "vcs",
      "url": "https://github.com/WordPress/abilities-api.git"
    },
    {
      "type": "vcs",
      "url": "https://github.com/WordPress/mcp-adapter.git"
    }
    // ... other repositories.
  ],
  "extra": {
    "installer-paths": {
      // This should match your WordPress+Composer setup.
      "wp-content/plugins/{$name}/": [
          "type:wordpress-plugin"
      ]
      // .. other paths.
    }
  }
  // ... rest of your composer.json.
}
```

Then, require the package in your project:

```bash
composer require wordpress/abilities-api wordpress/mcp-adapter
```



### As a dependency

Plugin authors and developers may wish to rely on the MCP Adapter as a dependency in their own projects, before it is merged into core. You can do that in one of the following ways.

#### As a Plugin Dependency (Recommended)

The best way to ensure the MCP Adapter is available for your plugins is to include it as one of your `Requires Plugins` in your [Plugin header](https://developer.wordpress.org/plugins/plugin-basics/header-requirements/). For example:

```diff
# my-plugin.php
/*
 *
 * Plugin Name:       My Plugin
 * Plugin URI:        https://example.com/plugins/the-basics/
 * Description:       Handle the basics with this plugin.
 * {all the other plugin header fields...}
 * Requires Plugins:  mcp-adapter
 */
```

While this is enough to ensure the MCP Adapter is loaded before your plugin, if you need to ensure specific version requirements or provide users guidance on installing the plugin, you can use the methods described [later on](#checking-availability-with-code)

#### As a Composer dependency

Until the plugin is available on WordPress, you will need to add the repository to your `composer.json` file.

```jsonc
{
  "repositories": [
    {
      "type": "vcs",
      "url": "https://github.com/WordPress/mcp-adapter.git"
    }
    // ... other repositories.
  ],
  // ... rest of your composer.json.
}
```

Then, require the package in your project:

```bash
composer require wordpress/mcp-adapter
```

### Checking availability with code

To ensure the MCP Adapter is loaded in your plugin:

```php
if ( ! class_exists( 'WP\MCP\Plugin' ) ) {
  // E.g. add an admin notice about the missing dependency.
  add_action( 'admin_notices', static function() {
    wp_admin_notice(
      esc_html__( 'This plugin requires the MCP Adapter to use. Please install and activate it.', 'my-plugin' ),
      'error'
    );
  } );
  return;
}
```

You can also check for specific versions of the MCP Adapter using the `WP_MCP_VERSION` constant:

```php
if ( ! defined( 'WP_MCP_VERSION' ) || version_compare( WP_MCP_VERSION, '0.1.0', '<' ) ) {
  // E.g. add an admin notice about the required version.
  add_action( 'admin_notices', static function() {
    wp_admin_notice(
      esc_html__( 'This plugin requires MCP Adapter version 0.1.0 or higher. Please update the plugin dependency.', 'my-plugin' ),
      'error'
    );
  } );
  return;
}
```

## Basic Usage

### Creating an MCP Server

To create an MCP server, register a callback function to the `mcp_adapter_init` action hook. This callback function can accept one parameter, `$adapter`, which is an instance of the McpAdapter class that is used to create the MCP server.

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
            \WP\MCP\Transport\Http\RestTransport::class,
        ],
        \WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class, // Error handler
        \WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class, // Observability handler
        ['my-plugin/my-ability'],         // Abilities to expose as tools
        [],                              // Resources (optional)
        []                               // Prompts (optional)
    );
});
```

## Advanced Usage

### Custom Transport Implementation

While the MCP Adapter includes production-ready REST API and streaming transports, you may need to create custom
transport protocols to meet specific infrastructure requirements or integration needs.

**Why Create Custom Transports:**

- **Product-Specific Requirements**: Different products may need unique authentication, routing, or response formats
  that don't fit the standard REST transport
- **Integration with Existing Systems**: Connect with your product's existing APIs, message queues, or internal
  communication protocols
- **Performance Needs**: Optimize for high-traffic scenarios or specific latency requirements your product demands
- **Security & Compliance**: Implement custom authentication, request signing, or meet specific security standards your
  product requires
- **Environment-Specific Behavior**: Handle different configurations for development, staging, and production
  environments
- **Custom Monitoring**: Integrate with your product's existing logging and analytics infrastructure

```php
use WP\MCP\Transport\Contracts\McpTransportInterface;
use WP\MCP\Transport\Infrastructure\McpTransportContext;
use WP\MCP\Transport\Infrastructure\McpTransportHelperTrait;

class MyCustomTransport implements McpTransportInterface {
    use McpTransportHelperTrait;
    
    private McpTransportContext $context;
    
    public function __construct(McpTransportContext $context) {
        $this->context = $context;
        add_action('rest_api_init', [$this, 'register_routes']);
    }
    
    public function register_routes(): void {
        // Register custom REST API routes
        register_rest_route(
            $this->context->mcp_server->get_server_route_namespace(), 
            $this->context->mcp_server->get_server_route() . '/custom', 
            [
                'methods' => 'POST',
                'callback' => [$this, 'handle_request'],
                'permission_callback' => [$this, 'check_permission']
            ]
        );
    }
    
    public function check_permission() {
        return is_user_logged_in();
    }
    
    public function handle_request($request) {
        // Custom request handling logic
        return rest_ensure_response(['status' => 'success']);
    }
}
```

### Custom Error Handler

While the MCP Adapter includes a default WordPress-compatible error handler, your product may need custom error handling
to integrate with existing systems or meet specific requirements.

**Why Create Custom Error Handlers:**

- **Integration with Existing Logging**: Connect with your product's current logging systems (Logstash, Sentry, DataDog,
  etc.)
- **Product-Specific Context**: Add custom fields like user IDs, product versions, or feature flags to error logs
- **Alert Integration**: Trigger notifications, Slack alerts, or incident management workflows when errors occur
- **Error Routing**: Send different types of errors to different systems (critical errors to on-call, debug info to
  development logs)
- **Compliance Requirements**: Meet specific logging standards or data retention policies your product requires
- **Performance Monitoring**: Track error rates and patterns in your product's analytics dashboard

```php
use WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface;

class MyErrorHandler implements McpErrorHandlerInterface {
    public function log(string $message, array $context = [], string $type = 'error'): void {
        // Custom error logging implementation
        error_log(sprintf(
            '[MCP Error] %s - Context: %s',
            $message,
            json_encode($context)
        ));
    }
}
```

## License
[GPL-2.0-or-later](https://spdx.org/licenses/GPL-2.0-or-later.html)
