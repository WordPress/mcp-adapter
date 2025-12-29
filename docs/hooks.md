# WordPress Hooks Reference

This document provides a comprehensive reference for all WordPress actions and filters provided by the MCP Adapter plugin.

## Table of Contents

- [Overview](#overview)
- [Actions](#actions)
  - [`mcp_adapter_init`](#mcp_adapter_init)
- [Filters](#filters)
  - [Server Configuration](#server-configuration)
    - [`mcp_adapter_create_default_server`](#mcp_adapter_create_default_server)
    - [`mcp_adapter_default_server_config`](#mcp_adapter_default_server_config)
    - [`mcp_adapter_validation_enabled`](#mcp_adapter_validation_enabled)
  - [Transport & Session](#transport--session)
    - [`mcp_adapter_default_transport_permission_user_capability`](#mcp_adapter_default_transport_permission_user_capability)
    - [`mcp_adapter_session_max_per_user`](#mcp_adapter_session_max_per_user)
    - [`mcp_adapter_session_inactivity_timeout`](#mcp_adapter_session_inactivity_timeout)
    - [`mcp_adapter_enable_stdio_transport`](#mcp_adapter_enable_stdio_transport)
  - [Observability](#observability)
    - [`mcp_adapter_observability_record_component_registration`](#mcp_adapter_observability_record_component_registration)
  - [Component Naming](#component-naming)
    - [`mcp_adapter_tool_name`](#mcp_adapter_tool_name)
    - [`mcp_adapter_resource_uri`](#mcp_adapter_resource_uri)
    - [`mcp_adapter_resource_name`](#mcp_adapter_resource_name)
  - [Capability Filters](#capability-filters)
    - [`mcp_adapter_discover_abilities_capability`](#mcp_adapter_discover_abilities_capability)
    - [`mcp_adapter_get_ability_info_capability`](#mcp_adapter_get_ability_info_capability)
    - [`mcp_adapter_execute_ability_capability`](#mcp_adapter_execute_ability_capability)
- [Multi-Server Considerations](#multi-server-considerations)
- [Hook Execution Order](#hook-execution-order)

---

## Overview

The MCP Adapter exposes 16 hooks (1 action, 15 filters) that allow developers to customize server behavior, permissions, and component naming. All hooks use the `mcp_adapter_` prefix.

## Actions

### `mcp_adapter_init`

Fires after the MCP Adapter has been initialized.

Use this action to register custom MCP servers. The adapter instance provides methods to create and configure additional servers beyond the default server.

**Location:** `includes/Core/McpAdapter.php`

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$adapter` | `\WP\MCP\Core\McpAdapter` | The MCP Adapter singleton instance |

**Example:**

```php
add_action( 'mcp_adapter_init', function( \WP\MCP\Core\McpAdapter $adapter ) {
    $adapter->create_server(
        'custom-server',                                                        // server_id
        'custom-namespace/v1',                                                  // server_route_namespace
        'mcp',                                                                  // server_route
        'Custom MCP Server',                                                    // server_name
        'A custom MCP server for my plugin',                                    // server_description
        '1.0.0',                                                                // server_version
        [ \WP\MCP\Transport\HttpTransport::class ],                             // mcp_transports
        \WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,    // error_handler
        \WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class, // observability_handler
        [ 'my-plugin/my-ability' ],                                             // tool abilities
        [],                                                                     // resource abilities
        []                                                                      // prompt abilities
    );
} );
```

**Since:** 0.1.0

---

## Filters

### Server Configuration

#### `mcp_adapter_create_default_server`

Filters whether the default MCP server should be created.

Return `false` to prevent the default server from being created. This is useful when you want to define custom servers only.

**Location:** `includes/Core/McpAdapter.php`

**Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `$create_default` | `bool` | `true` | Whether to create the default server |

**Example:**

```php
// Disable default server to use only custom servers
add_filter( 'mcp_adapter_create_default_server', '__return_false' );
```

**Since:** 0.3.0

---

#### `mcp_adapter_default_server_config`

Filters the default MCP server configuration before creation.

Allows customization of the default server's settings before creation. The filtered array is merged with defaults, so you only need to specify the values you want to override.

**Location:** `includes/Servers/DefaultServerFactory.php`

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$config` | `array` | Server configuration array |

**Configuration Keys:**

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `server_id` | `string` | `'mcp-adapter-default-server'` | Server identifier |
| `server_route_namespace` | `string` | `'mcp'` | REST API namespace |
| `server_route` | `string` | `'mcp-adapter-default-server'` | REST API route |
| `server_name` | `string` | `'MCP Adapter Default Server'` | Human-readable name |
| `server_description` | `string` | `'Default MCP server for WordPress abilities discovery and execution'` | Server description |
| `server_version` | `string` | `'v1.0.0'` | Server version |
| `mcp_transports` | `string[]` | `[HttpTransport::class]` | Transport class names |
| `error_handler` | `string` | `ErrorLogMcpErrorHandler::class` | Error handler class |
| `observability_handler` | `string` | `NullMcpObservabilityHandler::class` | Observability handler class |
| `tools` | `string[]` | Built-in abilities | Tool ability names to expose |
| `resources` | `string[]` | Auto-discovered | Resource ability names to expose |
| `prompts` | `string[]` | Auto-discovered | Prompt ability names to expose |

**Example:**

```php
add_filter( 'mcp_adapter_default_server_config', function( $config ) {
    // Change the REST API route
    $config['server_route'] = 'my-custom-route';

    // Add additional tool abilities
    $config['tools'][] = 'my-plugin/custom-ability';

    return $config;
} );
```

**Since:** 0.3.0

---

#### `mcp_adapter_validation_enabled`

Filters whether MCP protocol validation is enabled for a server.

Validation is disabled by default for performance, as the Abilities API also validates all abilities. Enable this filter for stricter MCP protocol compliance checking during development or debugging.

**Location:** `includes/Core/McpServer.php`

**Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `$enabled` | `bool` | `false` | Whether validation is enabled |
| `$server_id` | `string` | | The server ID being configured |
| `$server` | `\WP\MCP\Core\McpServer` | | The McpServer instance being constructed |

**Example:**

```php
// Enable validation for a specific server during development
add_filter( 'mcp_adapter_validation_enabled', function( $enabled, $server_id, $server ) {
    if ( 'my-dev-server' === $server_id && WP_DEBUG ) {
        return true;
    }
    return $enabled;
}, 10, 3 );
```

**Since:** 0.3.0

---

### Transport & Session

#### `mcp_adapter_default_transport_permission_user_capability`

Filters the default user capability required for MCP transport access.

This filter is only applied when no custom transport permission callback is provided or when the custom callback fails. The capability is checked using `current_user_can()`.

**Location:** `includes/Transport/HttpTransport.php`

**Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `$capability` | `string` | `'read'` | The required capability |
| `$context` | `\WP\MCP\Transport\Infrastructure\HttpRequestContext` | | The HTTP request context |

**Example:**

```php
// Require editor capability for MCP access
add_filter( 'mcp_adapter_default_transport_permission_user_capability', function( $capability, $context ) {
    return 'edit_posts';
}, 10, 2 );
```

**Since:** 0.3.0

---

#### `mcp_adapter_session_max_per_user`

Filters the maximum number of MCP sessions allowed per user.

When a user exceeds this limit, the oldest inactive session is automatically removed to make room for new sessions (FIFO behavior).

**Location:** `includes/Transport/Infrastructure/SessionManager.php`

**Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `$max_sessions` | `int` | `5` | Maximum sessions per user |

**Example:**

```php
// Allow more sessions for administrators
add_filter( 'mcp_adapter_session_max_per_user', function( $max ) {
    if ( current_user_can( 'manage_options' ) ) {
        return 20;
    }
    return $max;
} );
```

**Since:** 0.3.0

---

#### `mcp_adapter_session_inactivity_timeout`

Filters the session inactivity timeout in seconds.

Sessions that have been inactive longer than this duration are considered expired and may be cleaned up automatically.

**Location:** `includes/Transport/Infrastructure/SessionManager.php`

**Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `$timeout` | `int` | `DAY_IN_SECONDS` (86400) | Inactivity timeout in seconds (24 hours) |

**Example:**

```php
// Reduce session timeout to 1 hour for tighter security
add_filter( 'mcp_adapter_session_inactivity_timeout', function( $timeout ) {
    return HOUR_IN_SECONDS;
} );
```

**Since:** 0.3.0

---

#### `mcp_adapter_enable_stdio_transport`

Filters whether the STDIO transport is enabled.

Return `false` to disable STDIO transport entirely. This prevents the WP-CLI `mcp-adapter serve` command from functioning.

**Location:** `includes/Cli/StdioServerBridge.php`

**Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `$enabled` | `bool` | `true` | Whether STDIO transport is enabled |

**Example:**

```php
// Disable STDIO transport in production
add_filter( 'mcp_adapter_enable_stdio_transport', function( $enabled ) {
    return ! ( defined( 'WP_ENV' ) && 'production' === WP_ENV );
} );
```

**Since:** 0.3.0

---

### Observability

#### `mcp_adapter_observability_record_component_registration`

Filters whether component registration events should be recorded for observability.

Default is `false` to avoid polluting observability logs during startup. Enable this filter to track tool, resource, and prompt registrations for debugging or monitoring purposes.

**Location:** `includes/Core/McpComponentRegistry.php`

**Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `$should_record` | `bool` | `false` | Whether to record component registration events |
| `$server_id` | `string` | | The server ID for which components are being registered |
| `$server` | `\WP\MCP\Core\McpServer` | | The McpServer instance owning the registry |

**Example:**

```php
// Enable registration tracking for debugging
add_filter( 'mcp_adapter_observability_record_component_registration', function( $record, $server_id ) {
    return WP_DEBUG && 'mcp-adapter-default-server' === $server_id;
}, 10, 2 );
```

**Since:** 0.3.0

---

### Component Naming

#### `mcp_adapter_tool_name`

Filters the MCP tool name derived from an ability.

**Location:** `includes/Domain/Tools/RegisterAbilityAsMcpTool.php`

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$name` | `string` | The sanitized tool name |
| `$ability` | `\WP_Ability` | The source ability instance |

**Example:**

```php
// Prefix all tools with a namespace
add_filter( 'mcp_adapter_tool_name', function( $name, $ability ) {
    if ( str_starts_with( $ability->get_name(), 'my-plugin/' ) ) {
        return 'myco_' . $name;
    }
    return $name;
}, 10, 2 );
```

**Since:** n.e.x.t

---

#### `mcp_adapter_resource_uri`

Filters the MCP resource URI derived from an ability.

**Location:** `includes/Domain/Resources/RegisterAbilityAsMcpResource.php`

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$uri` | `string` | The validated resource URI |
| `$ability` | `\WP_Ability` | The source ability instance |

**Example:**

```php
// Use custom URI scheme
add_filter( 'mcp_adapter_resource_uri', function( $uri, $ability ) {
    return str_replace( 'resource://', 'myapp://', $uri );
}, 10, 2 );
```

**Since:** n.e.x.t

---

#### `mcp_adapter_resource_name`

Filters the MCP resource name derived from an ability.

**Location:** `includes/Domain/Resources/RegisterAbilityAsMcpResource.php`

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$name` | `string` | The resource name |
| `$ability` | `\WP_Ability` | The source ability instance |

**Example:**

```php
// Add descriptive prefix to resource names
add_filter( 'mcp_adapter_resource_name', function( $name, $ability ) {
    return 'WordPress: ' . $name;
}, 10, 2 );
```

**Since:** n.e.x.t

---

### Capability Filters

These filters control which WordPress capabilities are required to use built-in MCP abilities.

#### `mcp_adapter_discover_abilities_capability`

Filters the capability required to discover available abilities.

This capability is checked before listing all registered WordPress abilities through the `mcp-adapter/discover-abilities` tool.

**Location:** `includes/Abilities/DiscoverAbilitiesAbility.php`

**Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `$capability` | `string` | `'read'` | The required capability |

**Since:** 0.3.0

---

#### `mcp_adapter_get_ability_info_capability`

Filters the capability required to get ability information.

This capability is checked before returning detailed information about a specific WordPress ability through the `mcp-adapter/get-ability-info` tool.

**Location:** `includes/Abilities/GetAbilityInfoAbility.php`

**Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `$capability` | `string` | `'read'` | The required capability |

**Since:** 0.3.0

---

#### `mcp_adapter_execute_ability_capability`

Filters the capability required to execute abilities.

This is intentionally set to `'read'` as the minimum baseline capability. Each ability defines its own `permission_callback` that enforces the actual capability requirements for that specific operation. This filter serves only as a gate to prevent completely unauthenticated or capability-less users from reaching the ability execution layer.

**Location:** `includes/Abilities/ExecuteAbilityAbility.php`

**Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `$capability` | `string` | `'read'` | The required capability |

**Example:**

```php
// Require a higher capability for all ability executions
add_filter( 'mcp_adapter_execute_ability_capability', function( $capability ) {
    return 'edit_posts';
} );
```

**Since:** 0.3.0

---

## Multi-Server Considerations

When running multiple MCP servers, several filters receive server context (`$server_id` and `$server` parameters) to enable per-server customization:

- `mcp_adapter_validation_enabled`
- `mcp_adapter_observability_record_component_registration`

**Example: Per-server validation:**

```php
add_filter( 'mcp_adapter_validation_enabled', function( $enabled, $server_id, $server ) {
    // Enable validation only for development server
    return 'dev-server' === $server_id;
}, 10, 3 );
```

---

## Hook Execution Order

During plugin initialization, hooks fire in this order:

1. `mcp_adapter_create_default_server` — Determines if default server is created
2. `mcp_adapter_default_server_config` — Configures default server (if created)
3. `mcp_adapter_validation_enabled` — Sets validation mode per server
4. `mcp_adapter_observability_record_component_registration` — Sets observability mode
5. `mcp_adapter_init` — Signals initialization complete, custom servers can be registered

During request handling:

1. `mcp_adapter_default_transport_permission_user_capability` — Checks transport access
2. `mcp_adapter_discover_abilities_capability`, `mcp_adapter_get_ability_info_capability`, `mcp_adapter_execute_ability_capability` — Check permissions for the default server's built-in abilities
3. `mcp_adapter_tool_name`, `mcp_adapter_resource_*` — Apply during component resolution
