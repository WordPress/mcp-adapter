# CLI Usage Guide

This guide covers how to use the MCP Adapter's CLI functionality for STDIO transport and development workflows.

## Overview

The MCP Adapter includes WP-CLI commands that enable communication with MCP clients via standard input/output (STDIO) using the JSON-RPC 2.0 protocol. This is particularly useful for:

- Development and testing workflows
- Command-line automation and scripting
- IDE integrations and development tools

## Available Commands

### `wp mcp-adapter serve`

Serves an MCP server via STDIO transport for communication with MCP clients.

#### Syntax

```bash
wp mcp-adapter serve [--server=<server-id>] [--user=<id|login|email>]
```

#### Options

- `--server=<server-id>` - The ID of the MCP server to serve. If not specified, uses the first available server.
- `--user=<id|login|email>` - Run as a specific WordPress user for permission checks. Without this, runs as unauthenticated (limited capabilities).

#### Examples

```bash
# Serve the default MCP server as admin user
wp mcp-adapter serve --user=admin

# Serve a specific server as user with ID 1
wp mcp-adapter serve --server=my-mcp-server --user=1

# Serve without authentication (limited capabilities)
wp mcp-adapter serve --server=public-server
```

### `wp mcp-adapter list`

Lists all available MCP servers and their configurations.

#### Syntax

```bash
wp mcp-adapter list [--format=<format>]
```

#### Options

- `--format=<format>` - Output format (table, json, csv, yaml). Default: table.

#### Examples

```bash
# List servers in table format
wp mcp-adapter list

# List servers in JSON format
wp mcp-adapter list --format=json
```

## STDIO Transport Protocol

The STDIO transport uses JSON-RPC 2.0 protocol for communication:

### Request Format

```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "method": "tools/list",
  "params": {}
}
```

### Response Format

```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "result": {
    "tools": [...]
  }
}
```

## Integration Examples

### Using with MCP Clients

Many MCP clients can launch subprocess servers. Here's how to configure them:

#### Claude Desktop Configuration

```json
{
  "mcpServers": {
    "wordpress": {
      "command": "wp",
      "args": [
        "--path=/path/to/your/wordpress/site",
        "mcp-adapter",
        "serve",
        "--server=your-server-id",
        "--user=admin"
      ]
    }
  }
}
```


### Development Workflow

The CLI commands are particularly useful for development:

```bash
# Test your MCP server locally
wp mcp-adapter serve --user=admin --server=my-dev-server

# List available servers during development
wp mcp-adapter list --format=json | jq '.[].id'

# Test with different user permissions
wp mcp-adapter serve --user=editor --server=content-server
```

## Authentication and Permissions

### User Context

When using the `--user` option, the MCP server runs with that user's capabilities:

```bash
# Run as administrator (full access)
wp mcp-adapter serve --user=admin

# Run as editor (limited access)
wp mcp-adapter serve --user=editor

# Run as specific user ID
wp mcp-adapter serve --user=123
```


### Permission Debugging

Use WP-CLI's `--debug` flag to see permission checks:

```bash
wp mcp-adapter serve --user=admin --debug
```

## Error Handling

The CLI commands include comprehensive error handling:

### Common Errors

**Server Not Found**
```bash
wp mcp-adapter serve --server=nonexistent
# Error: Server with ID  'nonexistent' not found.
```

**User Not Found**
```bash
wp mcp-adapter serve --user=baduser
# Error: Invalid user ID, email or login:'baduser'
```

**No Servers Available**
```bash
wp mcp-adapter serve
# Error: No MCP servers available. Make sure servers are registered via mcp_adapter_init.
```

### Debug Output

Enable debug output for troubleshooting:

```bash
wp mcp-adapter serve --user=admin --debug
```

This will show:
- Server initialization process
- User authentication details
- Request/response flow
- Error details

## Advanced Usage

### Custom Server Selection

When multiple servers are available, specify which one to serve:

```bash
# List available servers
wp mcp-adapter list

# Serve specific server
wp mcp-adapter serve --server=content-management --user=admin
```

### Environment-Specific Configurations

Use different configurations for different environments:

```bash
# Development
wp mcp-adapter serve --server=dev-server --user=admin

# Staging
wp mcp-adapter serve --server=staging-server --user=staging-user

# Production (limited access)
wp mcp-adapter serve --server=prod-server --user=api-user
```

## Best Practices

### Development

- Use `--debug` during development for detailed output
- Test with different user roles to verify permissions
- Use `wp mcp-adapter list` to verify server registration

### Production

- Specify user explicitly for consistent permissions
- Use specific server IDs rather than defaults
- Implement proper error handling in client applications

### Security

- Avoid running as admin user unless necessary
- Use least-privilege user accounts
- Validate server IDs to prevent unauthorized access

This CLI functionality provides a powerful interface for integrating WordPress MCP servers with development tools and automated workflows and MCP clients.
