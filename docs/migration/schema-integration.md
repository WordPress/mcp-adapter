# Migration Guide: Schema Package Integration

This guide covers the integration of the `wordpress/php-mcp-schema` package into MCP Adapter, which provides type-safe Data Transfer Objects (DTOs) for the MCP 2025-11-25 specification.

## Overview

The MCP Adapter now uses the official `wordpress/php-mcp-schema` package for all MCP protocol data structures. This provides:

- Type-safe DTOs for all MCP protocol messages
- Automatic serialization/deserialization
- Better IDE support and type hints
- Consistent data structures across WordPress MCP implementations

## What's Changed

### 1. Internal Architecture

The adapter now uses DTOs from `WP\McpSchema` namespace throughout:

- **Tools**: `WP\McpSchema\Server\Tools\Tool`
- **Resources**: `WP\McpSchema\Server\Resources\Resource`
- **Prompts**: `WP\McpSchema\Server\Prompts\Prompt`
- **Responses**: Various result DTOs like `CallToolResult`, `ReadResourceResult`, etc.

### 2. For Plugin Developers

**Good news**: If you're using the standard WordPress abilities API (`wp_register_ability()`), **no changes are required**. The adapter handles all DTO conversions internally.

## Benefits

1. **Type Safety**: IDEs can now provide better autocomplete and type checking
2. **Consistency**: All MCP implementations using the schema package share the same data structures
3. **Future Proof**: Updates to the MCP specification can be handled by updating the schema package
4. **Less Boilerplate**: DTOs handle serialization/deserialization automatically

## No Breaking Changes

The integration has been designed to be transparent for all users:

- ✅ WordPress abilities work exactly as before
- ✅ All existing hooks and filters continue to work
- ✅ The public API remains unchanged
- ✅ Custom transports continue to work

For more information about the schema package, see: [wordpress/php-mcp-schema](https://github.com/wordpress/php-mcp-schema)
