# Installation Guide

MCP Adapter is distributed as a WordPress plugin and should be installed and activated like any other plugin.

## Installing the plugin

Download the latest release from [GitHub](https://github.com/WordPress/mcp-adapter/releases/latest) and install it like any other WordPress plugin, or use WP-CLI:

```bash
wp plugin install https://github.com/WordPress/mcp-adapter/releases/latest/download/mcp-adapter.zip --activate
```

The plugin automatically initializes and creates a default MCP server at `/wp-json/mcp/mcp-adapter-default-server`.

### With wp-env

To include MCP Adapter in a [`wp-env`](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/) environment, add it to the `plugins` array in `.wp-env.json`:

```jsonc
// .wp-env.json
{
  "$schema": "https://schemas.wp.org/trunk/wp-env.json",
  "plugins": [
    "https://github.com/WordPress/mcp-adapter/releases/latest/download/mcp-adapter.zip"
  ]
}
```

## As a dependency

Plugin authors and developers may wish to rely on MCP Adapter as a dependency and ensure it is installed and activated by users relying on their plugin. You can do that in one of the following ways.

### As a Plugin Dependency (recommended)

> [!IMPORTANT]
> Using MCP Adapter as a plugin dependency requires the plugin to be listed on the WordPress.org plugin directory, and is not currently supported. See [#178](https://github.com/WordPress/mcp-adapter/issues/178) to track progress on plugin submission.

The best way to ensure that MCP Adapter is installed and activated is to include it as one of your Requires Plugins in your [plugin header](https://developer.wordpress.org/plugins/plugin-basics/header-requirements/). For example:

```php
<?php
/**
 * Plugin Name:      My MCP Plugin
 * Description:      Demonstrates MCP Adapter integration
 * Version:          1.0.0
 * Requires Plugins: mcp-adapter
 */
```

### As a Composer Library

> [!NOTE]
> Bundling MCP Adapter as a Composer library is not recommended, as it can lead to conflicts with the MCP Adapter plugin or other plugins that may be bundling their own copy of MCP Adapter. It is strongly recommended to use the plugin dependency method above instead.
>
> If you currently bundle MCP Adapter in your plugin's `vendor/` directory, see the [vx.y.z migration guide](../migration/vx.y.z.md) for instructions on how to migrate to the canonical plugin.
```bash
composer require wordpress/mcp-adapter
```

If you are bundling MCP Adapter with your plugin, we suggest using [Jetpack Autoloader](https://github.com/Automattic/jetpack-autoloader) as your autoloader or a dependency prefixer like [Strauss](https://github.com/BrianHenryIE/strauss) to avoid conflicts with the MCP Adapter plugin or other legacy plugins that may be bundling their own copy of MCP Adapter.

## Checking availability with code

To ensure that the MCP Adapter plugin is active and available, you should check for the existence of the `WP\MCP\Core\McpAdapter` class before using any MCP Adapter functionality. For example:

```php
// The `plugins_loaded` hook ensures that all plugins are loaded before we check for MCP Adapter.
add_action( 'plugins_loaded', static function() {
  if ( ! class_exists( 'WP\MCP\Core\McpAdapter' ) ) {
    // Show an admin notice if MCP Adapter is not active.
    add_action( 'admin_notices', static function() {
      wp_display_notice(
        __( 'MCP Adapter plugin is required for this plugin to function. Please install and activate it.', 'my-plugin-textdomain' ),
        'error'
      );
    } );
    return;
  }

  // If you reach this point, MCP Adapter is active and you can safely use its classes and functions.
  $adapter = \WP\MCP\Core\McpAdapter::instance();

} );
```

You can also check for specific plugin version using the `WP_MCP_VERSION` constant. For example,

```php
if ( ! defined( 'WP_MCP_VERSION' ) || version_compare( WP_MCP_VERSION, '1.0.0', '<' ) ) {
  // Show an admin notice if MCP Adapter is not active or does not meet the version requirement.
  add_action( 'admin_notices', static function() {
    wp_display_notice(
      __( 'MCP Adapter plugin version 1.0.0 or higher is required for this plugin to function. Please update it.', 'my-plugin-textdomain' ),
      'error'
    );
  } );
  return;
}
```

## Next Steps

Once installation is complete:

1. **Follow [Creating Abilities](../guides/creating-abilities.md)** to build your MCP tools.
2. **Read [Basic Examples](./basic-examples.md)** to see how to use MCP Adapter in your plugin.
3. **Review [Architecture Overview](../architecture/overview.md)** for system design.
