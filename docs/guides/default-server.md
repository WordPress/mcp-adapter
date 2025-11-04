# Default MCP Server

The MCP Adapter automatically creates a default server that provides core MCP functionality for WordPress abilities. This server acts as a bridge between AI agents and WordPress, allowing them to discover and execute WordPress abilities through the Model Context Protocol.

## Server Configuration

### Basic Details
- **Server ID**: `mcp-adapter-default-server`
- **Endpoint**: `/wp-json/mcp/mcp-adapter-default-server`
- **Transport**: HTTP (MCP 2025-06-18 compliant)
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
