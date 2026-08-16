# Migration guide: Composer library to canonical plugin

MCP Adapter started life as a plain Composer library that plugins bundled into their own `vendor/` directory. It now ships as the **canonical WordPress plugin**, and its Composer package type changed from `library` to `wordpress-plugin` to match.

**If you bundle MCP Adapter into your plugin via Composer, migrate away from it.** That usage still works today, but it is no longer the intended way to consume MCP Adapter and support for it may be dropped in a future release. This guide covers where to go instead.

If you install MCP Adapter as a plugin, nothing here applies to you.

## The supported model

One copy of MCP Adapter per site, installed as a plugin, shared by every plugin that needs it.

- **MCP Adapter is the canonical plugin.** It owns the `WP\MCP\` namespace, the `mcp_adapter_init` and `wp_mcp_init` hooks, the default server ID, and the REST routes.
- **Your plugin declares it as a dependency** with the `Requires Plugins` header, so WordPress installs, activates, and load-orders it for you.
- **Namespace-prefixed copies are not supported.** Prefixing (with PHP-Scoper or similar) gives you a second `McpAdapter` class and a second singleton, while the hooks, filters, routes, and server IDs stay global. That collides — see [#172](https://github.com/WordPress/mcp-adapter/issues/172).

See the [Installation Guide](../getting-started/installation.md) for how to install and activate the plugin.

## Migrating off a bundled copy

### 1. Drop the dependency

```bash
composer remove wordpress/mcp-adapter
```

Leave `automattic/jetpack-autoloader` alone for the moment — [step 4](#4-move-off-the-jetpack-autoloader) deals with it.

### 2. Declare MCP Adapter as a plugin dependency

Add the [`Requires Plugins`](https://developer.wordpress.org/plugins/plugin-basics/header-requirements/) header to your main plugin file:

```php
<?php
/**
 * Plugin Name:      My Plugin
 * Description:      Adds MCP tools for my feature.
 * Requires Plugins: mcp-adapter
 */
```

This is what replaces the bundled copy. WordPress will not let your plugin activate until MCP Adapter is installed and active, and it loads MCP Adapter first, so the `WP\MCP\` classes are there by the time your plugin runs. No autoloader to wire up.

The header takes WordPress.org plugin slugs, so it starts working once MCP Adapter is listed there.

### 3. Stop bootstrapping the adapter

A bundled copy required you to call `McpAdapter::instance()` yourself. The plugin does that for you, so drop the call and just register your server:

```php
add_action( 'plugins_loaded', function () {
	if ( ! class_exists( \WP\MCP\Core\McpAdapter::class ) ) {
		// Belt and braces: Requires Plugins should have prevented this.
		return;
	}

	add_action( 'mcp_adapter_init', 'my_plugin_create_mcp_server' );
} );
```

With `Requires Plugins` in place the `class_exists()` check is a safety net rather than the mechanism — keep it if you support WordPress versions before 6.5, or if you want to fail softly rather than block activation.

If you need a *minimum* version of MCP Adapter rather than just its presence, check the `WP_MCP_VERSION` constant at runtime. `Requires Plugins` cannot express a version constraint.

`mcp_adapter_init` fires from `rest_api_init` at priority 15, so register your listener before then. `plugins_loaded` is comfortably early enough.

### 4. Move off the Jetpack Autoloader

You only needed [Jetpack Autoloader](https://github.com/Automattic/jetpack-autoloader) because you were shipping your own copy of `WP\MCP\`, and it had to be deconflicted against every other copy on the site. Once MCP Adapter is a plugin there is one copy, owned by the plugin, and nothing to deconflict.

**First check whether anything else still needs it.** Jetpack Autoloader protects *any* package you bundle that another plugin might also bundle — the rest of the `automattic/jetpack-*` family most commonly. If you bundle any of those, stop here and keep it.

```bash
composer show | grep -i jetpack
```

If MCP Adapter was the only reason, remove it:

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

### 5. Tell your users

MCP Adapter is now a prerequisite rather than something you ship. `Requires Plugins` surfaces that in the WordPress admin on its own, but mention it in your readme too, so people installing by other means are not surprised.

### 6. Remove anything that assumed a vendored copy

Build scripts that packaged `vendor/wordpress/mcp-adapter`, `.gitignore` or `.distignore` entries, `installer-paths` overrides, and PHP-Scoper configuration can all go.

## What still works, for now

If you cannot migrate yet, a bundled copy keeps working. Treat this as a stopgap, not a supported long-term configuration — the two things below are what you are on the hook for until you finish migrating.

**It has to keep resolving through Jetpack Autoloader** (`vendor/autoload_packages.php`, not `vendor/autoload.php`). Composer's stock autoloader binds `WP\MCP\` to whichever copy registers first, which on a real site is decided by plugin activation order — so a stale bundled copy can win against the active plugin. Jetpack Autoloader compares versions and loads the newest instead. That comparison needs a real version to work: if `vendor/composer/jetpack_autoload_psr4.php` shows `dev-*` or `9999999-dev` for `WP\MCP\`, set `COMPOSER_ROOT_VERSION` in your build, or your stale copy may win against a newer one.

This is also why removing Jetpack Autoloader is [step 4](#4-move-off-the-jetpack-autoloader) rather than something to do up front — it is only safe once your bundled copy is actually gone.

**You have to bootstrap the adapter yourself.** A bundled copy is loaded as library code, so the plugin bootstrap file never runs:

| | Plugin install | Bundled via Composer |
|---|---|---|
| `WP_MCP_DIR`, `WP_MCP_VERSION` | Defined | **Not defined** |
| Abilities API dependency check | Runs, with an admin notice on failure | **Does not run** |
| `wp_mcp_init` | Fires | **Does not fire** |
| `McpAdapter::instance()` | Called for you | **You must call it** |
| `mcp_adapter_init` | Fires | Fires, once the adapter is instantiated |

```php
add_action( 'plugins_loaded', function () {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return; // Requires WordPress 6.9 or newer.
	}

	\WP\MCP\Core\McpAdapter::instance();
} );
```

One thing to expect: if `composer/installers` is anywhere in your dependency graph — it is often pulled in transitively, so check `composer show composer/installers` rather than just your own `composer.json` — Composer relocates the package to `wp-content/plugins/mcp-adapter/` relative to your project root instead of leaving it in `vendor/`. Autoloading still resolves, but build steps that package only `vendor/` will silently ship without it. That relocation is Composer treating the package as what it now is. Read it as a signal to migrate rather than something to work around.

## What happens if both are installed

A site can end up with the canonical plugin active *and* a bundled copy loaded. That is a safe state:

- Jetpack Autoloader resolves both to a single set of `WP\MCP\` classes, so there is one `McpAdapter` class and one singleton.
- Whichever side calls `McpAdapter::instance()` first wins; the other gets the same instance back. The default server is created once.
- If the bundled copy is newer than the active plugin, the plugin runs the bundled code. This is why prefixed copies are unsupported.

If a Composer-relocated copy also gets activated as a plugin, MCP Adapter detects that a copy has already bootstrapped and the second one bails out quietly.

## Troubleshooting

**`Fatal error: Cannot redeclare WP\MCP\constants()`**

Two copies of `mcp-adapter.php` were loaded as plugins. Current versions bail out of the second copy, but versions up to and including 0.6.1 cannot: PHP binds the redeclared function while the second file is compiled, before any runtime guard gets to run. Upgrade both copies, or deactivate one.

**Admin notice: "The Composer autoloader was not found"**

The plugin found no `vendor/` directory next to itself and could not resolve `WP\MCP\` through any other autoloader.

- Installed from a source checkout? Run `composer install`.
- Is this a Composer dependency copy that got relocated into `wp-content/plugins/`? Composer flattens dependencies into your root `vendor/`, so this copy legitimately has no `vendor/` of its own. Current versions suppress the notice whenever the classes are already loadable from elsewhere; 0.6.1 and earlier show it regardless.

**`Class "WP\MCP\Core\McpAdapter" not found`**

The MCP Adapter plugin is not active. Add `Requires Plugins: mcp-adapter` to your plugin header so WordPress loads it before your plugin. If you are still on a bundled copy, check that you are loading `vendor/autoload_packages.php` rather than `vendor/autoload.php`.

**`duplicate_server_id`**

You are running a namespace-prefixed copy alongside another copy. That is not supported — remove the prefixing. See [#172](https://github.com/WordPress/mcp-adapter/issues/172).

## Next steps

- **[Installation Guide](../getting-started/installation.md)** — all installation methods
- **[Quick Start Guide](../getting-started/README.md)** — registering abilities and creating servers
