# Migration Guide: Version x.y.z

Version x.y.z standardizes MCP Adapter as a [canonical WordPress plugin](https://make.wordpress.org/core/2022/09/11/canonical-plugins-revisited/), and deprecates usage as a bundled Composer library.

If you have already installed MCP Adapter as a plugin, then you can update to the latest version and continue using it as before. Nothing in this guide applies to you.

**If you bundle MCP Adapter into your plugin via Composer, migrate away from it.** That usage still works today, but will be removed in a future version.

## Migrating to the canonical plugin

### 1. Remove the bundled dependency

The following command will remove the MCP Adapter dependency from your plugin's `composer.json` and `composer.lock`, and uninstall it from your local development environment.

```bash
composer remove wordpress/mcp-adapter
```

If you are not using Composer and instead are manually including a copy of MCP Adapter (e.g. in a `./lib/mcp-adapter` folder or via a git submodule), remove it manually and remove any `require` or `include` statements that load it.

### 2. Remove Jetpack Autoloader if you no longer need it

If you are using [Jetpack Autoloader](https://github.com/Automattic/jetpack-autoloader) and do not need it for other packages, you should remove it as well:

```bash
composer remove automattic/jetpack-autoloader
```

Then switch your main plugin file back to the standard Composer autoloader:

```php
// Before:
require_once plugin_dir_path( __FILE__ ) . 'vendor/autoload_packages.php';

// After:
require_once plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';
```

Finally, clear out the generated files. Jetpack Autoloader writes `vendor/autoload_packages.php`, `vendor/jetpack-autoloader/`, and `vendor/composer/jetpack_autoload_*.php`, and removing the package does not always delete them. A stale `autoload_packages.php` left in a build is the kind of thing that works locally and fails in production:

```bash
rm -rf vendor
composer install
ls vendor/autoload_packages.php   # should now be "No such file or directory"
```

Drop the `automattic/jetpack-autoloader` entry from `config.allow-plugins` in your `composer.json` too, along with any `COMPOSER_ROOT_VERSION` you set in CI purely to make its version comparison work.

### 3. Declare MCP Adapter as a plugin dependency

To ensure MCP Adapter is installed and active, add it to the [`Requires Plugins`](https://developer.wordpress.org/plugins/plugin-basics/header-requirements/) header to your main plugin file:

```php
<?php
/**
 * Plugin Name:      My Plugin
 * Description:      Adds MCP tools for my feature.
 * Requires Plugins: mcp-adapter
 */
```

This is what replaces the bundled copy and ensures the plugin cannot be activated without MCP Adapter. WordPress will not let your plugin activate until MCP Adapter is installed and active and direct the user to install it directly.

If your plugin doesn't require MCP Adapter for all functionality, but only uses it if available, you can skip this step and instead check for the existence of the `WP\MCP\Core\McpAdapter` class - or specific plugin version - before using any MCP Adapter functionality. See [Checking availability with code](../getting-started/installation.md#checking-availability-with-code) for an example.

## What still works (for now)

If you are currently bundling a copy MCP Adapter in your plugin, it will continue to work for now, but will cause a deprecation notice to be logged.

## What if both copies are present?

If both the canonical plugin and a bundled copy are present, a `_doing_it_wrong()` notice will be logged, and an admin notice will be displayed on the WordPress dashboard informing users that a duplicate copy of MCP Adapter is present and is being used instead.

While not recommended, you can suppress the plugin's autoloader entirely and the resulting warnings, by conditionally defining the `WP_MCP_AUTOLOAD` constant:

```php
// Disable the canonical plugin's autoloader in favor of your own bundled copy. This is NOT recommended.
if ( is_plugin_active( 'mcp-adapter/mcp-adapter.php' ) && ! defined( 'WP_MCP_AUTOLOAD' ) ) {
  define( 'WP_MCP_AUTOLOAD', false );
}
```

## Next steps

- **[Installation Guide](../getting-started/installation.md)** — all installation methods
- **[Quick Start Guide](../getting-started/README.md)** — registering abilities and creating servers
