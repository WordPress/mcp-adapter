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

Configure MCP clients (Claude Desktop, Claude Code, VS Code, Cursor, etc.) to connect to your WordPress MCP servers. Many MCP clients can launch subprocess servers directly via STDIO; others need an HTTP proxy.

#### STDIO transport (local sites)

Many MCP clients can launch subprocess servers. Point the client's config at the `wp` binary:

```jsonc
{
  "mcpServers": {
    "wordpress": {
      "command": "wp",
      "args": [
        "--path=/path/to/your/wordpress/site",
        "mcp-adapter",
        "serve",
        // Change the server ID and user as needed
        "--server=mcp-adapter-default-server",
        "--user=admin"
      ]
    },
  }
}
```

#### HTTP transport via proxy

The [`@automattic/mcp-wordpress-remote`](https://www.npmjs.com/package/@automattic/mcp-wordpress-remote) proxy runs locally and translates STDIO-based MCP communication from AI clients into HTTP REST API calls that WordPress understands. Authentication uses [WordPress Application Passwords](https://make.wordpress.org/core/2020/11/05/application-passwords-integration-guide/).

```jsonc
{
  "mcpServers": {
    "wordpress": {
      "command": "npx",
      "args": [
        "-y",
        "@automattic/mcp-wordpress-remote@latest"
      ],
      "env": {
        // Update these to match your environment details
        "WP_API_URL": "http://your-site.test/wp-json/mcp/mcp-adapter-default-server",
        "LOG_FILE": "/path/to/logs/mcp-adapter.log",
        "WP_API_USERNAME": "your-username",
        "WP_API_PASSWORD": "your-application-password"
      }
    }
  }
}
```

For more information, see the [@automattic/mcp-wordpress-remote](https://www.npmjs.com/package/@automattic/mcp-wordpress-remote) package documentation.

#### Connecting directly over HTTP (remote clients)

Both options above are STDIO from the client's point of view: `wp mcp-adapter serve` runs locally, and so does the `@automattic/mcp-wordpress-remote` proxy — it just forwards to HTTP behind the scenes. Neither works for a client that runs somewhere it can't launch local processes, such as Claude in Chat or Cowork mode, which runs in a hosted sandbox.

For those clients, point them at the site's MCP REST endpoint directly — no local process required:

```
https://your-site.example/wp-json/mcp/mcp-adapter-default-server
```

Authenticate with a [WordPress Application Password](https://make.wordpress.org/core/2020/11/05/application-passwords-integration-guide/) sent as HTTP Basic Auth, the same credential used by the proxy above. Whether a given client's remote-connector UI lets you supply Basic Auth credentials (as opposed to only OAuth) depends on the client; check its documentation for adding a custom/remote MCP connector.

The endpoint implements the MCP Streamable HTTP transport: `POST` for JSON-RPC requests, `GET` for an SSE stream (used for server-initiated messages on an established session), and `DELETE` to end a session. For example, with `curl`:

```bash
# Initialize a session (also returns an Mcp-Session-Id response header)
curl -u your-username:your-application-password \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","clientInfo":{"name":"curl","version":"1.0.0"}}}' \
  https://your-site.example/wp-json/mcp/mcp-adapter-default-server

# Open the SSE stream for that session
curl -N -u your-username:your-application-password \
  -H "Accept: text/event-stream" \
  -H "Mcp-Session-Id: <id from the response above>" \
  https://your-site.example/wp-json/mcp/mcp-adapter-default-server
```

The SSE stream is held open only for a bounded duration (30 seconds by default) and then closes; compliant clients reconnect automatically. This keeps one slow or idle client from tying up a PHP-FPM worker indefinitely. Site owners can adjust this behavior with filters:

- `mcp_adapter_enable_http_sse_stream` — return `false` to disable the SSE stream and make `GET` respond with `405` again (the transport still works over POST/DELETE without it).
- `mcp_adapter_sse_stream_duration` — how long, in seconds, a single stream stays open before closing (default `30`).
- `mcp_adapter_sse_ping_interval` — how often, in seconds, a keep-alive comment is sent while the stream is open (default `15`).

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
