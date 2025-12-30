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

### 3. For Advanced Integrations

If you're directly creating MCP components or custom handlers, you'll need to work with the DTOs:

**Before:**
```php
// Manual array construction
$tool = array(
    'name' => 'my-tool',
    'description' => 'My tool description',
    'inputSchema' => array(
        'type' => 'object',
        'properties' => array(
            'input' => array('type' => 'string')
        )
    )
);
```

**After:**
```php
use WP\McpSchema\Server\Tools\Tool;

// Type-safe DTO construction
$tool = new Tool(
    name: 'my-tool',
    description: 'My tool description',
    inputSchema: (object) array(
        'type' => 'object',
        'properties' => array(
            'input' => array('type' => 'string')
        )
    )
);
```

### 4. Validation

The existing validator classes (`McpToolValidator`, `McpResourceValidator`, `McpPromptValidator`) continue to work and now validate the DTOs:

```php
use WP\MCP\Domain\Tools\McpToolValidator;
use WP\McpSchema\Server\Tools\Tool;

$tool = new Tool(...);
$result = McpToolValidator::validate_tool_dto($tool);
if (is_wp_error($result)) {
    // Handle validation error
}
```

## Benefits

1. **Type Safety**: IDEs can now provide better autocomplete and type checking
2. **Consistency**: All MCP implementations using the schema package share the same data structures
3. **Future Proof**: Updates to the MCP specification can be handled by updating the schema package
4. **Less Boilerplate**: DTOs handle serialization/deserialization automatically

## No Breaking Changes for Most Users

The integration has been designed to be transparent for most users:

- ✅ WordPress abilities work exactly as before
- ✅ All existing hooks and filters continue to work
- ✅ The public API remains unchanged
- ✅ Custom transports continue to work

## Need Help?

If you encounter any issues with the schema integration:

1. Ensure you have the latest version of MCP Adapter
2. Run `composer install` to get the schema package
3. Check that your PHP version meets the requirements (PHP 7.4+)

For more information about the schema package, see: [wordpress/php-mcp-schema](https://github.com/wordpress/php-mcp-schema)