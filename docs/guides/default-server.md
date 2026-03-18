# Default MCP Server

The MCP Adapter automatically creates a default server that provides core MCP functionality for WordPress abilities. This server acts as a bridge between AI agents and WordPress, allowing them to discover and execute WordPress abilities through the Model Context Protocol.

## Choosing between default and custom servers

The MCP Adapter supports two approaches for exposing WordPress abilities to AI agents. You can use both at the same time — they serve different purposes.

### Default server: layered discovery

The default server is created automatically when the plugin loads. It exposes three meta-tools that let AI agents dynamically discover and execute any WordPress ability marked with `mcp.public=true` metadata:

1. **`mcp-adapter-discover-abilities`** — Lists all publicly available abilities
2. **`mcp-adapter-get-ability-info`** — Retrieves the full schema for a specific ability
3. **`mcp-adapter-execute-ability`** — Executes an ability with provided parameters

> **Note:** These are the MCP tool names that AI agents use when calling `tools/call`. The underlying WordPress abilities are registered with `/` separators (e.g., `mcp-adapter/discover-abilities`), but `McpNameSanitizer` converts `/` to `-` during registration so that tool names comply with the MCP specification character set. See [Tool naming](creating-abilities.md#tool-naming) for details.

The AI agent navigates this layered interface: discover what is available, inspect what it needs, then execute. This keeps the MCP `tools/list` response small (only 3 tool schemas) regardless of how many abilities exist on the site.

The default server also auto-discovers abilities registered with `mcp.public=true` and `mcp.type` set to `resource` or `prompt`, exposing them as MCP resources and prompts alongside the three meta-tools.

**Best for:**
- General-purpose AI integration where the set of abilities changes over time
- Sites with many plugins registering abilities — no server reconfiguration needed
- Scenarios where context window efficiency matters (only 3 tool schemas sent to the AI)

### Custom server: direct tool registration

A custom server is created explicitly via the `mcp_adapter_init` hook. Each ability you list is registered as a standalone MCP tool, resource, or prompt with its own schema visible directly in `tools/list`:

```php
add_action( 'mcp_adapter_init', function ( $adapter ) {
    $adapter->create_server(
        'content-server',
        'mcp',
        'content-server',
        'Content Server',
        'Focused content management tools',
        '1.0.0',
        array( \WP\MCP\Transport\HttpTransport::class ),
        \WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
        null, // observability handler
        array( 'my-plugin/create-post', 'my-plugin/update-post' ), // tools
        array(), // resources
        array()  // prompts
    );
} );
```

The AI agent sees `my-plugin-create-post` and `my-plugin-update-post` as individual tools with their full input schemas — no discovery step required.

**Best for:**
- Focused integrations exposing a small, well-defined set of tools
- Cases where AI agents need dedicated schemas with rich parameter descriptions
- When you want the AI to call tools directly without the discover-then-execute indirection

### Comparison

| Aspect | Default Server | Custom Server |
|--------|---------------|---------------|
| Creation | Automatic on plugin load | Manual via `mcp_adapter_init` hook |
| Tool visibility | 3 meta-tools; abilities discovered at runtime | Each ability is a separate MCP tool |
| AI interaction | Discover → Inspect → Execute (3 steps) | Call tool directly (1 step) |
| Scalability | Unlimited abilities without growing `tools/list` | Each tool adds to `tools/list` response |
| Schema detail | AI fetches schemas on demand via `get-ability-info` | Full schemas visible immediately |
| Configuration | Zero-config; abilities opt in with `mcp.public=true` | Explicit ability list per server |
| Auto-discovery | Resources and prompts with `mcp.public=true` auto-discovered | Only explicitly listed components |
| Transport | HTTP (REST API) by default; STDIO via WP-CLI | Any transport you configure |

### Disabling the default server

If you only want custom servers, disable the default server with the `mcp_adapter_create_default_server` filter. This filter is applied in `McpAdapter::maybe_create_default_server()` before any default abilities or the default server factory are registered:

```php
add_filter( 'mcp_adapter_create_default_server', '__return_false' );
```

When disabled, the three built-in meta-tools (`mcp-adapter/discover-abilities`, `mcp-adapter/get-ability-info`, `mcp-adapter/execute-ability`) are not registered and the default server endpoint is not created.

### Extending the default server

Use the `mcp_adapter_default_server_config` filter to modify the default server's configuration before it is created. The filter receives the full configuration array and must return an array — it is merged with defaults via `wp_parse_args()`:

```php
add_filter( 'mcp_adapter_default_server_config', function ( $config ) {
    // Change the server name
    $config['server_name'] = 'My Site MCP Server';

    // Add a custom tool alongside the 3 meta-tools
    $config['tools'][] = 'my-plugin/quick-search';

    // Use a custom error handler
    $config['error_handler'] = \MyPlugin\CustomErrorHandler::class;

    return $config;
} );
```

See the [full default configuration](#default-configuration) below for all available keys.

## HTTP sessions

When using the HTTP REST API transport, MCP clients must follow the session protocol defined by the MCP specification. The STDIO transport (WP-CLI) does not use HTTP sessions — session lifecycle is tied to the WP-CLI process instead.

### Session flow

Every HTTP client must complete an initialization handshake before sending any other MCP request:

1. **Initialize** — Send a `POST` request with the `initialize` JSON-RPC method. No `Mcp-Session-Id` header is needed for this first request.
2. **Capture the session ID** — The response includes an `Mcp-Session-Id` header containing a UUID. Store this value.
3. **Include the header on every subsequent request** — All following `POST` and `DELETE` requests must include the `Mcp-Session-Id` header with the stored value. (The MCP specification also requires the header on `GET` requests for SSE streaming, but SSE is not yet implemented — `GET` currently returns `405 Method Not Allowed`.)
4. **Terminate when done** — Send a `DELETE` request with the `Mcp-Session-Id` header to clean up the session.

### Curl example

```bash
# 1. Initialize and capture the session ID
SESSION_ID=$(curl -s -D - -X POST \
  "https://yoursite.com/wp-json/mcp/mcp-adapter-default-server" \
  --user "username:application_password" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"my-client","version":"1.0.0"}}}' \
  | grep -i 'mcp-session-id' | awk '{print $2}' | tr -d '\r')

echo "Session ID: $SESSION_ID"

# 2. Send initialized notification (tells server the client is ready for requests)
curl -s -X POST \
  "https://yoursite.com/wp-json/mcp/mcp-adapter-default-server" \
  --user "username:application_password" \
  -H "Content-Type: application/json" \
  -H "Mcp-Session-Id: $SESSION_ID" \
  -d '{"jsonrpc":"2.0","method":"notifications/initialized"}'

# 3. Use the session ID for subsequent requests
curl -s -X POST \
  "https://yoursite.com/wp-json/mcp/mcp-adapter-default-server" \
  --user "username:application_password" \
  -H "Content-Type: application/json" \
  -H "Mcp-Session-Id: $SESSION_ID" \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}'

# 4. Terminate the session when done
curl -s -X DELETE \
  "https://yoursite.com/wp-json/mcp/mcp-adapter-default-server" \
  --user "username:application_password" \
  -H "Mcp-Session-Id: $SESSION_ID"
```

### Error cases

| Condition | JSON-RPC Error Code | HTTP Status | Message |
|-----------|-------------------|-------------|---------|
| Missing `Mcp-Session-Id` header on a non-initialize request | `-32600` (Invalid Request) | 400 | Missing Mcp-Session-Id header |
| Invalid or expired session ID | `-32602` (Invalid Params) | 200 | Invalid or expired session | <!-- MCP uses HTTP 200 for application-level errors; the error is in the JSON-RPC body -->
| User not authenticated | `-32010` (Unauthorized) | 401 | User not authenticated |

### Session configuration

Two filters control session behavior:

**`mcp_adapter_session_max_per_user`** — Maximum number of concurrent sessions a single user can have. When the limit is reached the oldest session is automatically evicted. Default: `32`.

```php
// Allow at most 5 concurrent sessions per user
add_filter( 'mcp_adapter_session_max_per_user', function () {
    return 5;
} );
```

**`mcp_adapter_session_inactivity_timeout`** — Number of seconds a session can remain idle before it is considered expired. Default: `DAY_IN_SECONDS` (86 400 seconds / 24 hours).

```php
// Expire sessions after 1 hour of inactivity
add_filter( 'mcp_adapter_session_inactivity_timeout', function () {
    return HOUR_IN_SECONDS;
} );
```

Sessions are stored in user meta and are cleaned up automatically when a new session is created or an existing session is validated.

### STDIO transport (WP-CLI)

The STDIO transport used by WP-CLI does not require HTTP sessions. Each `wp mcp-adapter serve` invocation runs as a single process with its own lifecycle, so session management is unnecessary:

```bash
echo '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}' | \
  wp mcp-adapter serve --user=admin --server=mcp-adapter-default-server
```

## Server Configuration

### Basic Details
- **Server ID**: `mcp-adapter-default-server`
- **Endpoint**: `/wp-json/mcp/mcp-adapter-default-server`
- **Transport**: HTTP (MCP Streamable HTTP compliant)
- **Authentication**: Requires logged-in WordPress user with `read` capability (customizable via filters)

### Default Configuration

```php
$wordpress_defaults = array(
    'server_id'              => 'mcp-adapter-default-server',
    'server_route_namespace' => 'mcp',
    'server_route'           => 'mcp-adapter-default-server',
    'server_name'            => 'MCP Adapter Default Server',
    'server_description'     => 'Default MCP server for WordPress abilities discovery and execution',
    'server_version'         => 'v1.0.0',
    'mcp_transports'         => array( HttpTransport::class ),
    'error_handler'          => ErrorLogMcpErrorHandler::class,
    'observability_handler'  => NullMcpObservabilityHandler::class,
    'tools'                  => array(
        'mcp-adapter/discover-abilities',
        'mcp-adapter/get-ability-info', 
        'mcp-adapter/execute-ability',
    ),
    'resources'              => array(),
    'prompts'                => array(),
);
```

## Core Abilities

The default server includes three core abilities that provide MCP functionality:

### Layered Tooling Architecture

The MCP Adapter uses a **layered tooling approach** where a small set of meta-abilities provides access to all WordPress abilities, solving the "too many tools problem" that affects MCP servers.

**The Problem**: In traditional MCP implementations, each capability would be exposed as a separate tool. When an AI agent connects, it requests `tools/list` and receives every tool's complete schema (name, description, input parameters, etc.). With dozens or hundreds of tools, this creates several issues:

1. **Context Window Bloat**: Tool schemas consume significant portions of the AI's context window before any actual work begins
2. **Decision Paralysis**: AI agents struggle to choose the right tool from an overwhelming list of options
3. **Scalability Limits**: The system becomes unwieldy as the number of tools grows

**The Solution**: Rather than exposing each WordPress ability as a separate MCP tool, the default server exposes just **three strategic meta-abilities** that act as a gateway:

1. **Discover** (`mcp-adapter/discover-abilities`) - Lists all available WordPress abilities
2. **Get Info** (`mcp-adapter/get-ability-info`) - Retrieves detailed schema for any specific ability
3. **Execute** (`mcp-adapter/execute-ability`) - Executes any ability with provided parameters

This layered approach provides several key benefits:

- **Minimal Context Consumption**: Only 3 tool schemas are sent to the AI agent, regardless of how many WordPress abilities exist
- **Dynamic Capability Discovery**: WordPress plugins can register unlimited abilities without MCP server reconfiguration
- **Progressive Information Loading**: The AI only fetches detailed schemas for abilities it actually needs
- **Cleaner Decision-Making**: The AI navigates a simple, structured interface rather than choosing from hundreds of tools
- **Future-Proof Scalability**: New abilities are automatically discoverable through the existing gateway tools

The AI agent uses these three tools in combination to systematically explore and interact with the WordPress abilities ecosystem: first discovering what's available, then getting detailed information about relevant abilities, and finally executing the chosen actions.

### 1. Discover Abilities (`mcp-adapter/discover-abilities`)

**Purpose**: Lists all WordPress abilities that are publicly available via MCP.

**MCP Method**: `tools/list`

**Security**: 
- Requires authenticated WordPress user
- Requires `read` capability (customizable via `mcp_adapter_discover_abilities_capability` filter)
- Only returns abilities with `mcp.public=true` metadata

**Behavior**:
- Scans all registered WordPress abilities
- Excludes abilities starting with `mcp-adapter/` (prevents self-referencing)
- Filters to only include abilities with `mcp.public=true` in their metadata
- Returns ability name, label, and description for each public ability

**Output Format**:
```json
{
  "abilities": [
    {
      "name": "my-plugin/create-post",
      "label": "Create Post", 
      "description": "Creates a new WordPress post"
    }
  ]
}
```

**Annotations**:
- `readOnlyHint`: `true` (does not modify data)
- `destructiveHint`: `false` (safe operation)
- `idempotentHint`: `true` (consistent results)
- `openWorldHint`: `false` (works with known abilities only)

### 2. Get Ability Info (`mcp-adapter/get-ability-info`)

**Purpose**: Provides detailed information about a specific WordPress ability.

**MCP Method**: `tools/call` with tool name `mcp-adapter-get-ability-info`

**Input Parameters**:
- `ability_name` (required): The full name of the ability to query

**Security**:
- Requires authenticated WordPress user
- Requires `read` capability (customizable via `mcp_adapter_get_ability_info_capability` filter)
- Only works with abilities that have `mcp.public=true` metadata
- Returns `ability_not_public_mcp` error for non-public abilities

**Output Format**:
```json
{
  "name": "my-plugin/create-post",
  "label": "Create Post",
  "description": "Creates a new WordPress post",
  "input_schema": {
    "type": "object",
    "properties": {...}
  },
  "output_schema": {...},
  "meta": {...}
}
```

**Annotations**:
- `readOnlyHint`: `true` (does not modify data)
- `destructiveHint`: `false` (safe operation)
- `idempotentHint`: `true` (consistent results)
- `openWorldHint`: `false` (works with known abilities only)

### 3. Execute Ability (`mcp-adapter/execute-ability`)

**Purpose**: Executes any WordPress ability with provided parameters.

**MCP Method**: `tools/call` with tool name `mcp-adapter-execute-ability`

**Input Parameters**:
- `ability_name` (required): The full name of the ability to execute
- `parameters` (required): Object containing parameters to pass to the ability

**Security**:
- Requires authenticated WordPress user
- Requires `read` capability (customizable via `mcp_adapter_execute_ability_capability` filter)
- Only executes abilities with `mcp.public=true` metadata
- Performs additional permission check on the target ability itself
- Double-checks permissions before execution as additional security layer

**Execution Flow**:
1. Validates user authentication and capabilities
2. Checks if target ability has `mcp.public=true` metadata
3. Verifies target ability exists
4. Calls the target ability's permission callback
5. Executes the target ability with provided parameters
6. Returns structured response with success/error status

**Output Format**:
```json
{
  "success": true,
  "data": {
    // Result from the executed ability
  }
}
```

**Error Format**:
```json
{
  "success": false,
  "error": "Error message describing what went wrong"
}
```

**Annotations**:
- `readOnlyHint`: `false` (may modify data depending on executed ability)
- `openWorldHint`: `true` (can execute any registered ability)

## Security Model

### Public MCP Metadata

The default server implements a metadata-driven security model:

- **Default Secure**: Abilities are NOT accessible via MCP by default
- **Explicit Opt-in**: Abilities must include `mcp.public=true` in their metadata to be accessible
- **Granular Control**: Each ability individually decides if it should be MCP-accessible

**Example of Public MCP Ability**:
```php
wp_register_ability('my-plugin/safe-tool', [
    'label' => 'Safe Tool',
    'description' => 'A safe tool for MCP access',
    'execute_callback' => 'my_safe_callback',
    'permission_callback' => function() {
        return current_user_can('read');
    },
    'meta' => [
        'mcp' => [
            'public' => true, // This makes it accessible via MCP
        ]
    ]
]);
```

### Authentication Requirements

All core abilities require:
1. **WordPress Authentication**: User must be logged in (`is_user_logged_in()`)
2. **Capability Check**: User must have required capability (default: `read`)
3. **MCP Exposure Check**: Target ability must have `mcp.public=true` metadata

### Capability Filters

You can customize required capabilities using WordPress filters:

```php
// Require 'edit_posts' for discovering abilities
add_filter('mcp_adapter_discover_abilities_capability', function() {
    return 'edit_posts';
});

// Require 'manage_options' for getting ability info
add_filter('mcp_adapter_get_ability_info_capability', function() {
    return 'manage_options';
});

// Require 'publish_posts' for executing abilities
add_filter('mcp_adapter_execute_ability_capability', function() {
    return 'publish_posts';
});
```

## Customization

### Server Configuration Filter

You can customize the entire server configuration using the `mcp_adapter_default_server_config` filter:

```php
add_filter('mcp_adapter_default_server_config', function($config) {
    // Change server name
    $config['server_name'] = 'My Custom MCP Server';
    
    // Add custom error handler
    $config['error_handler'] = MyCustomErrorHandler::class;
    
    // Add additional tools
    $config['tools'][] = 'my-plugin/custom-tool';
    
    return $config;
});
```

### Adding Resources and Prompts

The default server can be extended with resources and prompts:

```php
add_filter('mcp_adapter_default_server_config', function($config) {
    // Add resources
    $config['resources'] = [
        'my-plugin/site-config',
        'my-plugin/user-data'
    ];
    
    // Add prompts  
    $config['prompts'] = [
        'my-plugin/code-review',
        'my-plugin/content-analysis'
    ];
    
    return $config;
});
```

## Usage Examples

### Testing with WP-CLI

```bash
# List all available tools
echo '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}' | \
  wp mcp-adapter serve --user=admin --server=mcp-adapter-default-server

# Get info about a specific ability
echo '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"mcp-adapter-get-ability-info","arguments":{"ability_name":"my-plugin/create-post"}}}' | \
  wp mcp-adapter serve --user=admin --server=mcp-adapter-default-server

# Execute an ability
echo '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"mcp-adapter-execute-ability","arguments":{"ability_name":"my-plugin/create-post","parameters":{"title":"Test Post","content":"Hello World"}}}}' | \
  wp mcp-adapter serve --user=admin --server=mcp-adapter-default-server
```

### HTTP REST API

```bash
# Test with curl (requires authentication)
curl -X POST "https://yoursite.com/wp-json/mcp/mcp-adapter-default-server" \
  --user "username:application_password" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}'
```

## Error Handling

The default server uses structured error handling:

### Common Error Codes
- `authentication_required`: User not logged in
- `insufficient_capability`: User lacks required WordPress capability
- `ability_not_found`: Requested ability doesn't exist
- `ability_not_public_mcp`: Ability not exposed via MCP (missing `mcp.public=true`)
- `missing_ability_name`: Required ability name parameter missing

### Error Response Format
```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "error": {
    "code": -32008,
    "message": "Permission denied: User lacks required capability: read"
  }
}
```

## Best Practices

### For Plugin Developers

1. **Secure by Default**: Only add `mcp.public=true` to abilities that should be accessible via MCP
2. **Proper Permissions**: Implement appropriate permission callbacks for your abilities
3. **Clear Documentation**: Provide good labels and descriptions for your abilities
4. **Input Validation**: Use proper input schemas to validate parameters

### For Site Administrators

1. **User Management**: Only grant MCP access to trusted users
2. **Capability Review**: Regularly review which users have the required capabilities
3. **Monitor Usage**: Use error logging to monitor MCP usage and potential security issues
4. **Custom Filters**: Use capability filters to tighten security if needed

## Troubleshooting

### No Abilities Returned
- Check that abilities have `mcp.public=true` in their metadata
- Verify user is authenticated and has required capabilities
- Ensure abilities are properly registered during `wp_abilities_api_init`

### Permission Denied Errors
- Verify user authentication (logged in)
- Check user has required capability (default: `read`)
- Confirm ability has `mcp.public=true` metadata

### Ability Not Found
- Ensure ability is registered before MCP server initialization
- Check ability name spelling and format
- Verify ability registration happens during `wp_abilities_api_init` action

## Next Steps

- **[Creating Abilities](creating-abilities.md)** - Learn how to create MCP-compatible abilities
- **[Transport Permissions](transport-permissions.md)** - Customize server-wide authentication
- **[Error Handling](error-handling.md)** - Implement custom error management
