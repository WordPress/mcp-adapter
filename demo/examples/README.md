# MCP Adapter Examples

This directory contains standalone PHP files demonstrating various MCP Adapter usage patterns and advanced features. Each example is self-contained and includes detailed comments explaining the implementation.

## Overview

These examples showcase practical implementation patterns for:

- **Server Configuration**: Creating and configuring MCP servers
- **Client Integration**: Connecting to external MCP services
- **Custom Transport**: Implementing specialized communication protocols
- **Observability**: Adding monitoring and metrics collection
- **Error Handling**: Robust error management strategies
- **Advanced Patterns**: Production-ready implementation techniques

## Examples

### [`server-usage.php`](server-usage.php)

Demonstrates how to create and configure MCP servers with various options:

- **Basic Server Creation**: Simple server setup with minimal configuration
- **Advanced Configuration**: Servers with custom transports and error handlers
- **Ability Exposure**: How to selectively expose WordPress abilities as MCP tools
- **Multiple Servers**: Managing multiple servers with different configurations

**Key Concepts:**
- Server registration using `mcp_adapter_init` action
- Transport method selection
- Error handler configuration
- Ability filtering and organization

### [`client-usage.php`](client-usage.php)

Shows how to connect to external MCP servers and use their capabilities:

- **Basic Client Connection**: Connecting to public MCP services
- **Authentication Patterns**: Bearer tokens, API keys, and basic auth
- **Remote Capability Usage**: Using remote tools, resources, and prompts as local abilities
- **Error Handling**: Graceful handling of connection failures

**Key Concepts:**
- Client registration using `mcp_client_init` action
- Authentication configuration options
- Automatic ability registration for remote capabilities
- Connection monitoring and error recovery

**Real Example:** Connects to WordPress Domains MCP service for domain search functionality.

### [`observability-example.php`](observability-example.php)

Demonstrates custom monitoring and metrics collection for MCP operations:

- **Custom Observability Handler**: Creating handlers that integrate with your monitoring systems
- **Event Tracking**: Recording MCP events and operations
- **Performance Metrics**: Timing and performance measurement
- **Integration Patterns**: Connecting with external monitoring tools

**Key Concepts:**
- Implementing `McpObservabilityHandlerInterface`
- Event recording and context management
- Performance timing and metrics
- Custom logging and alerting integration

**Use Cases:**
- Integration with DataDog, New Relic, or custom analytics
- Performance monitoring and optimization
- Debugging and troubleshooting MCP operations

### [`monitored-transport.php`](monitored-transport.php)

Shows how to create custom transport implementations with built-in monitoring:

- **Transport Wrapper Pattern**: Extending existing transports with additional functionality
- **Request/Response Logging**: Detailed logging of MCP communications
- **Performance Monitoring**: Built-in timing and performance tracking
- **Custom Protocol Implementation**: Foundation for specialized transport protocols

**Key Concepts:**
- Extending base transport classes
- Implementing monitoring at the transport layer
- Request and response interception
- Custom routing and protocol handling

**Use Cases:**
- Performance debugging and optimization
- Compliance and audit logging  
- Integration with existing API gateway infrastructure
- Custom authentication and security requirements

## Usage Patterns

### 1. Development and Testing

During development, these examples help you:

- **Understand Integration Points**: See where MCP Adapter hooks into WordPress
- **Test Different Configurations**: Experiment with various server and client setups
- **Debug Issues**: Use monitoring examples to troubleshoot problems
- **Validate Functionality**: Ensure your MCP integration works correctly

### 2. Production Implementation

For production deployments:

- **Reference Implementation**: Use examples as starting points for your own code
- **Best Practices**: Follow established patterns for error handling and monitoring
- **Security Patterns**: Implement proper authentication and authorization
- **Performance Optimization**: Use monitoring examples to identify bottlenecks

### 3. Custom Development

When building custom functionality:

- **Transport Development**: Use monitored transport as a foundation for custom protocols
- **Observability Integration**: Adapt observability examples for your monitoring stack
- **Error Handling**: Implement robust error management following example patterns
- **Testing Strategies**: Use examples to validate your custom implementations

## Getting Started

### Prerequisites

- WordPress 6.8+
- MCP Adapter plugin installed and activated
- MCP Adapter Demo plugin activated (provides the context these examples run in)
- PHP 7.4+

### Running the Examples

1. **Install Dependencies**: Ensure MCP Adapter and Demo plugin are active
2. **Review Code**: Examine the example files to understand the patterns
3. **Copy and Modify**: Use examples as starting points for your own implementations
4. **Test Integration**: Use the demo plugin admin interface to test your configurations

### Customization

Each example includes commented sections showing:

- **Configuration Options**: Different ways to configure servers and clients
- **Extension Points**: Where to add your own custom logic
- **Integration Patterns**: How to connect with your existing WordPress functionality
- **Error Scenarios**: How to handle various failure conditions

## Integration with Demo Plugin

These examples work in conjunction with the demo plugin's admin interface:

- **Live Testing**: Use the admin interface to test server and client configurations
- **Real-time Monitoring**: See the results of observability examples in action
- **Interactive Debugging**: Use the interface to trigger and observe MCP operations
- **Configuration Validation**: Verify that your custom configurations work correctly

## Best Practices

### Development

- **Start Simple**: Begin with basic server or client examples
- **Incremental Enhancement**: Add monitoring and error handling gradually  
- **Test Thoroughly**: Use the demo plugin interface to validate functionality
- **Document Changes**: Keep track of customizations for maintenance

### Production

- **Security First**: Implement proper authentication and authorization
- **Monitor Everything**: Use observability examples for production monitoring
- **Error Resilience**: Implement comprehensive error handling
- **Performance Tracking**: Monitor timing and resource usage

### Maintenance

- **Version Compatibility**: Test examples with MCP Adapter updates
- **Documentation**: Keep implementation documentation current
- **Monitoring**: Maintain observability for ongoing operations
- **Security Updates**: Regular review of authentication and security patterns

## Contributing

These examples are maintained as part of the MCP Adapter project. To contribute improvements or additional examples, see the main [MCP Adapter repository](https://github.com/WordPress/mcp-adapter).