# MCP Adapter Demo Plugin

A demonstration plugin showcasing the MCP Adapter functionality with practical examples and an admin interface for testing MCP server and client capabilities.

## Overview

This demo plugin provides:

- **Admin Interface**: WordPress admin page for managing and testing MCP servers and clients
- **Working Examples**: Practical implementation examples in the `examples/` directory
- **Demo Abilities**: Sample WordPress abilities that demonstrate MCP integration patterns
- **Real-world Usage**: Production-ready code patterns you can use in your own plugins

## Features

### Admin Interface

The demo plugin adds an admin page under **Settings > MCP Settings** that provides:

- **Server Management**: Create and configure MCP servers
- **Client Management**: Connect to external MCP servers
- **Testing Tools**: Interactive testing of MCP functionality
- **Real-time Monitoring**: View server status and connection details

### Demo Abilities

The plugin registers several example abilities that demonstrate different MCP integration patterns:

- **Tool Examples**: Interactive abilities that perform actions
- **Resource Examples**: Data-providing abilities for information access
- **Prompt Examples**: Template abilities for generating recommendations

### Examples Directory

The `examples/` directory contains standalone PHP files demonstrating:

- Server setup and configuration
- Client connection and usage
- Error handling and observability
- Transport customization
- Real-world integration patterns

## Installation

### Requirements

- WordPress 6.8+
- MCP Adapter plugin (installed and activated)
- PHP 7.4+

### Installation Steps

1. **Install MCP Adapter**: Ensure the main MCP Adapter plugin is installed and activated first
2. **Install Demo Plugin**: Install this demo plugin 
3. **Activate Plugin**: Activate the MCP Adapter Demo plugin
4. **Access Admin Interface**: Navigate to **Settings > MCP Settings** in your WordPress admin

## Usage

### Testing MCP Functionality

1. Navigate to **Settings > MCP Settings** in WordPress admin
2. Use the interface to create test servers and clients
3. Monitor connections and test MCP operations
4. Review the examples directory for code implementation details

### Using as Reference

The demo plugin serves as a reference implementation for:

- **Plugin Structure**: How to structure an MCP-enabled plugin
- **Ability Registration**: Proper patterns for registering WordPress abilities
- **Server Configuration**: Best practices for MCP server setup
- **Client Integration**: How to connect to external MCP servers
- **Error Handling**: Robust error handling patterns
- **Admin Integration**: Creating WordPress admin interfaces for MCP management

## Examples Directory

See the [examples README](examples/README.md) for detailed information about the included code examples and how to use them in your own projects.

## Code Structure

```
demo/
├── mcp-adapter-demo.php    # Main plugin file
├── includes/
│   ├── DemoPlugin.php      # Core plugin class
│   ├── Autoloader.php      # PSR-4 autoloader
│   └── Admin/
│       └── McpTestPage.php # Admin interface implementation
└── examples/               # Standalone usage examples
```

## Development

This demo plugin follows WordPress coding standards and best practices:

- **PSR-4 Autoloading**: Proper namespace and class loading
- **WordPress Hooks**: Correct use of WordPress action and filter hooks
- **Security**: Proper nonce handling and capability checks
- **Error Handling**: Graceful error handling and user feedback

## Contributing

This demo plugin is part of the MCP Adapter project. For contributing guidelines, see the main [MCP Adapter repository](https://github.com/WordPress/mcp-adapter).

## License

GPL-2.0-or-later - same as WordPress and the main MCP Adapter plugin.