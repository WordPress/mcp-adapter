# Migration Guide: Version x.y.z

Version x.y.z standardizes MCP Adapter as a [canonical WordPress plugin](https://make.wordpress.org/core/2022/09/11/canonical-plugins-revisited/), deprecates usage as a bundled Composer library, and introduces the dual-revision schema runtime.

Which sections apply depends on how your integration uses MCP Adapter:

- If you bundle MCP Adapter into your plugin, follow [Migrating to the canonical plugin](#migrating-to-the-canonical-plugin). Bundled usage still works today, but will be removed in a future version.
- If your integration directly uses Adapter or schema APIs, follow [Migrating to the dual-revision schema runtime](#migrating-to-the-dual-revision-schema-runtime), even if MCP Adapter is already installed as a standalone plugin.
- If you use the standalone plugin and only register WordPress Abilities through the existing declarations and callbacks, no registration changes are needed. See [What does not change](#what-does-not-change).

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

### What still works (for now)

If you are currently bundling a copy MCP Adapter in your plugin, it will continue to work for now, but will cause a deprecation notice to be logged.

### What if both copies are present?

If both the canonical plugin and a bundled copy are present, a `_doing_it_wrong()` notice will be logged, and an admin notice will be displayed on the WordPress dashboard informing users that a duplicate copy of MCP Adapter is present and is being used instead.

While not recommended, you can suppress the plugin's autoloader entirely and the resulting warnings, by conditionally defining the `WP_MCP_AUTOLOAD` constant:

```php
// Disable the canonical plugin's autoloader in favor of your own bundled copy. This is NOT recommended.
if ( is_plugin_active( 'mcp-adapter/mcp-adapter.php' ) && ! defined( 'WP_MCP_AUTOLOAD' ) ) {
  define( 'WP_MCP_AUTOLOAD', false );
}
```

## Migrating to the dual-revision schema runtime

MCP Adapter now supports exact MCP `2025-11-25` and `2026-07-28` through the revision-selected `wordpress/php-mcp-schema` record runtime. Clients that propose `2025-06-18` or `2024-11-05` keep working: the identifier is echoed back and the session is served through the `2025-11-25` schema. Ordinary Ability authors do not add protocol branches; direct Adapter/schema consumers must make the changes below.

### What does not change

Ability registration remains revision-neutral. Keep existing:

- `input_schema` and `output_schema` declarations;
- execute and permission callbacks;
- `meta.mcp` tool/resource/prompt configuration; and
- Adapter pre-execution and result filters.

The Adapter supplies modern result/cache fields and omits removed standardized fields.

### Select an exact schema

Protocol-facing server getters now require a selected schema. Reuse the server-owned schema cache:

```php
use WP\McpSchema\Schemas;

$schema = $server->get_schemas()->forVersion( Schemas::V2026_07_28 );
$tools  = $server->get_tools( $schema );
```

There is no no-argument overload or implicit 2025 default. Schema identifiers are exactly `2025-11-25` and `2026-07-28`. Legacy identifiers listed in `McpVersionNegotiator::LEGACY_PROTOCOL_VERSIONS` are negotiated by name and resolve to the `2025-11-25` schema through `McpVersionNegotiator::schema_version_for()`.

### Replace removed schema classes

Use flat generated records and construct them through the selected `Schema`:

```php
use WP\McpSchema\Record\Tool;

$tool = $schema->fromArray(
    Tool::class,
    array(
        'name'        => 'weather',
        'inputSchema' => array( 'type' => 'object' ),
    )
);
```

Replace complete serialization methods with `jsonSerialize()` or direct JSON encoding. Records also provide named getters, `get()`, and `has()`.

The removed API includes:

- the old area-specific schema namespaces and factories;
- generated enum objects and union factories;
- `get_protocol_dto()`;
- static construction on generated values;
- alternate complete array serializers; and
- validation state and the `mcp_adapter_validation_enabled` filter.

There are no aliases or compatibility facades.

### Component access

`McpTool`, `McpResource`, and `McpPrompt` expose `get_protocol_record( Schema $schema )`. Use `is_available_for()` when a caller needs to inspect per-revision availability. A component can be valid for one revision and absent from another.

The CLI reports neutral registration counts by default. Use `wp mcp-adapter list --protocol=<revision>` to count components available under one supported schema revision. Wire discovery returns only records valid for the selected revision.

Factory validation is deferred to projection. `McpTool::fromArray()`, `McpResource::fromArray()`, and `McpPrompt::fromArray()` return `WP_Error` only for structural problems: a missing name or URI, a missing handler, or an invalid resource URI. A schema problem, such as an invalid `x-mcp-header` annotation or an input schema the selected revision cannot represent, no longer fails construction. It surfaces when a revision is selected: `is_available_for()` returns `false` and `get_projection_error( $revision )` returns the throwable. At registration the server logs a warning for each revision a component cannot project to and rejects the component only when no supported revision can represent it. Code that relied on `is_wp_error()` to catch schema problems should check `is_available_for()` for each revision it serves.

### Direct Adapter integrations

Protocol-facing Adapter internals now receive validated records and an exact request context:

| Surface                              | Current contract                                                                                                            |
| ------------------------------------ | --------------------------------------------------------------------------------------------------------------------------- |
| Custom HTTP transports               | Delegate raw `WP_REST_Request` objects through `HttpRequestHandler` and `HttpRequestContext`.                               |
| Other custom transports              | Decode and process raw JSON through `McpWireOrchestrator`.                                                                  |
| `RequestRouter::route_request()`     | Accepts a generated request `Record`, `McpRequestContext`, and transport name. Do not pass raw method and parameter arrays. |
| Method handlers                      | Accept the exact generated request record and `McpRequestContext`; return logical arrays for final schema projection.       |
| `McpErrorFactory`                    | Returns logical JSON-RPC error arrays. Pass the selected revision to `resource_not_found()`.                                |
| `ContentBlockHelper`                 | Returns revision-neutral content arrays for final schema hydration.                                                         |
| `McpPromptBuilderInterface::build()` | Returns the revision-neutral prompt configuration array.                                                                    |

See [Custom transports](../guides/custom-transports.md) and [Error handling](../guides/error-handling.md) for complete examples.

### Filters

Tool, resource, and prompt list filters keep their existing first two arguments and receive the selected schema third:

```php
add_filter(
    'mcp_adapter_tools_list',
    static function ( array $tools, $server, $schema ): array {
        return $tools;
    },
    10,
    3
);
```

Filter payloads are generated records. A filtered list is validated when the final list-result record is constructed.

### Revision removals and replacements

- `initialize`, `notifications/initialized`, and `ping` are 2025-only.
- `server/discover` and per-request metadata are 2026-only.
- `tools/list/all` is not canonical in either supported revision and is no longer dispatchable.
- `Tool.execution` is omitted from Adapter-owned 2026 output.
- 2026 completed results include `resultType: "complete"`.
- 2026 discovery/list/resource-read results include `ttlMs: 0` and `cacheScope: "private"`.
- 2026 resource misses use `-32602`; unsupported per-request versions use `-32022`.
- Missing tools and prompts use standard Invalid Params (`-32602`) in both revisions.
- 2025 `tools/call` responses omit `structuredContent` when a tool returns a JSON list, including an empty list, because the 2025-11-25 schema types that field as an object. The text content block still carries the encoded list. 2026 responses keep the list because the 2026-07-28 schema accepts any JSON value there.

### Transport changes

The 2025 HTTP lifecycle remains session-based. Modern HTTP is sessionless and requires body metadata plus `MCP-Protocol-Version`, `Mcp-Method`, applicable `Mcp-Name`, and declared `Mcp-Param-*` headers. STDIO carries revision metadata in each modern request body and can alternate exact revisions line by line.

Batch requests are rejected before dispatch.

`resources/read` forwards only the protocol-defined parameters (`uri`, `_meta`, `inputResponses`, and `requestState`) to permission callbacks, the `mcp_adapter_pre_resource_read` filter, and resource handlers. Any other key in the request params is dropped before dispatch.

### Verification

After migrating a direct integration, run:

```bash
npm run test:php
npm run lint:php
npm run lint:php:stan
```

Add raw-wire tests for each revision your integration sends and for any method or field that was removed between them.

## Next steps

- **[Installation Guide](../getting-started/installation.md)** — all installation methods
- **[Quick Start Guide](../getting-started/README.md)** — registering abilities and creating servers
