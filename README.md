# MCP Adapter

[*Part of the **AI Building Blocks for WordPress** initiative*](https://make.wordpress.org/ai/2025/07/17/ai-building-blocks)

A PHP library that provides an adapter for the WordPress Abilities API, enabling WordPress abilities to be exposed as
MCP (Model Context Protocol) tools, resources, and prompts. This adapter serves as the foundation for integrating
WordPress capabilities with AI agents through the MCP specification.

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
├── Core/                      # Core system components
│   ├── McpAdapter.php        # Main registry and server management
│   └── McpServer.php         # Individual server configuration
├── Domain/                    # Business logic and MCP components
│   ├── Tools/                # MCP Tools implementation
│   │   ├── McpTool.php       # Base tool class
│   │   ├── RegisterAbilityAsMcpTool.php  # Ability-to-tool conversion
│   │   └── McpToolValidator.php   # Tool validation
│   ├── Resources/            # MCP Resources implementation
│   │   ├── McpResource.php   # Base resource class
│   │   ├── RegisterAbilityAsMcpResource.php  # Ability-to-resource conversion
│   │   └── McpResourceValidator.php # Resource validation
│   └── Prompts/              # MCP Prompts implementation
│       ├── Contracts/        # Prompt interfaces
│       │   └── McpPromptBuilderInterface.php # Prompt builder interface
│       ├── McpPrompt.php     # Base prompt class
│       ├── McpPromptBuilder.php # Prompt builder implementation
│       ├── McpPromptValidator.php # Prompt validation
│       └── RegisterAbilityAsMcpPrompt.php  # Ability-to-prompt conversion
├── Handlers/                  # Request processing handlers
│   ├── Initialize/           # Initialization handlers
│   ├── Tools/                # Tool request handlers
│   ├── Resources/            # Resource request handlers
│   ├── Prompts/              # Prompt request handlers
│   └── System/               # System request handlers
├── Infrastructure/           # Infrastructure concerns
│   ├── ErrorHandling/        # Error handling system
│   │   ├── Contracts/        # Error handling interfaces
│   │   │   └── McpErrorHandlerInterface.php # Error handler interface
│   │   ├── ErrorLogMcpErrorHandler.php  # Default error handler
│   │   ├── NullMcpErrorHandler.php      # Null object pattern
│   │   └── McpErrorFactory.php          # Error response factory
│   └── Observability/        # Monitoring and observability
│       ├── Contracts/        # Observability interfaces
│       │   └── McpObservabilityHandlerInterface.php # Observability interface
│       ├── ErrorLogMcpObservabilityHandler.php  # Default handler
│       ├── NullMcpObservabilityHandler.php      # Null object pattern
│       └── McpObservabilityHelperTrait.php      # Helper trait
└── Transport/                # Transport layer implementations
    ├── Contracts/            # Transport interfaces
    │   └── McpTransportInterface.php # Transport interface
    ├── Http/                 # HTTP-based transports
    │   ├── RestTransport.php        # REST API transport
    │   └── StreamableTransport.php  # Streaming transport
    └── Infrastructure/       # Transport infrastructure
        ├── McpRequestRouter.php     # Request routing
        ├── McpTransportContext.php  # Transport context
        └── McpTransportHelperTrait.php # Helper trait
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

- **PHP**: >= 8.1
- **WordPress Abilities API**: For ability registration and management
- **Automattic Jetpack Autoloader**: For PSR-4 autoloading

### WordPress Abilities API Integration

This adapter requires the WordPress Abilities API, which provides:

- Standardized ability registration (`wp_register_ability()`)
- Ability retrieval and management (`wp_get_ability()`)
- Schema definition for inputs and outputs
- Permission callback system
- Execute callback system

## Installation

### Via Composer (Recommended)

The preferred way to install the MCP Adapter is through Composer for enhanced dependency management:

```bash
composer require wordpress/mcp-adapter
```

**Composer Benefits:**

- Automatic dependency resolution and updates
- Version constraint management across your project
- Integration with existing Composer-based workflows
- Simplified dependency tracking in `composer.json`

### Manual Installation (Alternative)

The adapter also works without Composer by using the included Jetpack autoloader:

1. Download the library to your WordPress installation (e.g., `wp-content/lib/mcp-adapter/`)
2. Load the Jetpack autoloader in your plugin or theme:
   ```php
   // Check if the class isn't already loaded by another plugin
   if ( ! class_exists( 'WP\MCP\Core\McpAdapter' ) ) {
       // Load the Jetpack autoloader
       if ( is_file( ABSPATH . 'wp-content/lib/mcp-adapter/vendor/autoload_packages.php' ) ) {
           require_once ABSPATH . 'wp-content/lib/mcp-adapter/vendor/autoload_packages.php';
       }
   }
   ```
3. Ensure the WordPress Abilities API is loaded before initializing the adapter

### Example Implementation

For a complete working example of MCP Adapter integration, see the [MCP Adapter Implementation Example](https://github.com/galatanovidiu/mcp-adapter-implementation-example) - a WordPress plugin demonstrating best practices for implementing MCP servers with the adapter.

## Basic Usage

### Initializing the Adapter

```php
use WP\MCP\Core\McpAdapter;

// Get the adapter instance
$adapter = McpAdapter::instance();

// Hook into the initialization
add_action('mcp_adapter_init', function($adapter) {
    // Server configuration happens here
});
```

### Creating an MCP Server

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

## Enterprise Production Implementation

The MCP Adapter has been designed with enterprise production use in mind, supporting complex, multi-server architectures and extensive customization capabilities.

**Enterprise Implementation Patterns:**

- **Custom Transport Development**: Create transport implementations tailored to your infrastructure needs, integrating
  with existing authentication systems, API gateways, or specialized communication protocols
- **Production Error Handling**: Implement custom error handlers that integrate with your organization's logging
  infrastructure (Logstash, Sentry, DataDog, etc.) with structured context data and user tracking
- **Multi-Server Architecture**: Deploy multiple MCP servers with different configurations - general functionality servers and specialized servers for specific operations, allowing you to segment functionality across endpoints
- **Custom Abilities**: Develop organization-specific abilities for cross-system integrations, content management, performance optimization, and workflow automation tailored to your environment
- **Access Control Integration**: Implement custom permission systems that integrate with your existing user verification and authorization infrastructure using [transport permission callbacks](docs/guides/transport-permissions.md)
- **Dependency Management**: Proper integration patterns with both the Abilities API and MCP Adapter, supporting conditional loading and multiple autoloader strategies

## Why as a Package

The MCP Adapter is designed as a **Composer package**, not a WordPress plugin, to provide maximum flexibility and
integration capabilities. This architectural choice leverages
the [Jetpack Autoloader](https://github.com/Automattic/jetpack-autoloader) to solve version conflicts and enable
seamless integration across multiple WordPress projects.

### Package Benefits

**Integration Flexibility**: As a Composer package, the adapter can be integrated into any WordPress plugin or theme,
rather than requiring a separate plugin installation. This allows products to bundle MCP functionality directly into
their existing codebase.

**Version Conflict Resolution**: Using the [Jetpack Autoloader](https://github.com/Automattic/jetpack-autoloader),
multiple plugins can use different versions of the MCP Adapter without conflicts. The autoloader automatically loads the
latest version available, ensuring compatibility across your WordPress ecosystem.

**Dependency Management**: The adapter works independently without external dependency managers. When Composer is
available, it can optionally handle dependency resolution and version tracking, providing enhanced workflow integration
for teams already using Composer-based development.

**Developer Experience**: Teams can add MCP functionality to their existing projects with a simple `composer require`
command, without needing to coordinate separate plugin installations or worry about plugin activation order.

**Manual Integration Support**: For environments where Composer isn't available or preferred, the adapter can be
manually included by loading the Jetpack autoloader directly, providing flexibility for various deployment scenarios.

**Enterprise Distribution**: Organizations can distribute the adapter as part of their internal plugins or themes,
maintaining control over versions and customizations without relying on external plugin repositories.

### Jetpack Autoloader Integration

The adapter leverages Automattic's [Jetpack Autoloader](https://github.com/Automattic/jetpack-autoloader) to handle
complex scenarios where multiple plugins might use the MCP Adapter:

- **Automatic Version Resolution**: When multiple plugins include different versions of the adapter, the autoloader
  ensures the latest version is used across all implementations
- **Memory Efficiency**: Prevents duplicate class loading and reduces memory overhead in multi-plugin environments
- **Conflict Prevention**: Eliminates the "fatal error" scenarios that occur when multiple plugins try to load the same
  classes
- **Performance Optimization**: Uses optimized classmaps for faster autoloading in production environments

This packaging approach ensures the MCP Adapter can be safely used across multiple products within an organization while
maintaining compatibility and performance.

## License
[GPL-2.0-or-later](https://spdx.org/licenses/GPL-2.0-or-later.html)
