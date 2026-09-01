=== MCP Adapter ===
Contributors:      wordpressdotorg, ovidiu-galatan
Tags:              mcp, ai, abilities-api, model-context-protocol
Requires at least: 6.9
Tested up to:      7.1
Requires PHP:      7.4
Stable tag:        0.6.1
License:           GPL-2.0-or-later
License URI:       https://spdx.org/licenses/GPL-2.0-or-later.html

Expose WordPress abilities as Model Context Protocol (MCP) tools, resources, and prompts for AI agents.

== Description ==

Part of the [AI Building Blocks for WordPress](https://make.wordpress.org/ai/2025/07/17/ai-building-blocks) initiative.

The MCP Adapter bridges WordPress's Abilities API with the [Model Context Protocol (MCP)](https://modelcontextprotocol.io) specification, providing a standardized way for AI agents to interact with WordPress functionality. It includes HTTP and STDIO transport support, comprehensive error handling, and an extensible architecture for custom integrations.

**Features:**

* **Ability-to-MCP Conversion** - Automatically converts WordPress abilities into MCP tools, resources, and prompts.
* **Multi-Server Management** - Create and manage multiple MCP servers with unique configurations.
* **Extensible Transport Layer** - Built-in HTTP and STDIO transports, plus support for custom transport protocols.
* **Flexible Error Handling** - Default WordPress-compatible error logging with support for custom, server-specific handlers.
* **Observability** - Zero-overhead metrics tracking with configurable handlers.
* **Permission Control** - Granular, configurable permission checking for all exposed functionality.

== Installation ==

1. Go to **Plugins > Add New** in your WordPress admin, search for "MCP Adapter", and click **Install Now**.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Register the abilities you want to expose, or install a plugin that registers them for you.

You can also upload the plugin ZIP via **Plugins > Add New > Upload Plugin**, or install it with WP-CLI:

`wp plugin install mcp-adapter --activate`

== Frequently Asked Questions ==

= What is MCP? =

Model Context Protocol is an open standard for connecting AI agents to external systems. Instead of every AI client needing its own custom integration, a system exposes what it can do as MCP tools, resources and prompts, and any MCP-compatible client can use them. See [modelcontextprotocol.io](https://modelcontextprotocol.io) for the specification.

= What is MCP Adapter? =

MCP Adapter is the server and transport layer. It does not provide any AI features itself.

What it exposes comes from the WordPress Abilities API. An ability is a single capability registered by WordPress core or by another plugin, such as reading posts or updating a setting. MCP Adapter takes the abilities registered on your site, makes them available to MCP clients, and handles protocol negotiation, permissions and error handling.

That means you also need abilities on the site for this to be useful, from core or from a plugin that registers its own.

= How do AI agents connect to my site? =

The adapter registers MCP endpoints over the built-in HTTP transport. Point an MCP-compatible client at the endpoint and authenticate as a WordPress user. The adapter negotiates MCP protocol versions `2025-11-25`, `2025-06-18` and `2024-11-05`. See the [getting started guide](https://github.com/WordPress/mcp-adapter/tree/trunk/docs/getting-started) for endpoint details.

= Does every registered ability get exposed automatically? =

No, exposure is opt-in. An ability is only reachable over MCP if it has been marked public, and permission checks still run against the current user before anything executes. See the [documentation](https://github.com/WordPress/mcp-adapter/tree/trunk/docs) for how to opt abilities in and out.

= Where do I report bugs or request features? =

On the [GitHub repository](https://github.com/WordPress/mcp-adapter/issues). Security issues should follow the process in [SECURITY.md](https://github.com/WordPress/mcp-adapter/blob/trunk/SECURITY.md) rather than being reported publicly.

Plugin developers should depend on MCP Adapter by adding `Requires Plugins: mcp-adapter` to their plugin header. See the [README](https://github.com/WordPress/mcp-adapter#installation) for WP-CLI and wp-env options.

== Changelog ==

= 0.6.1 - 2026-08-13 =

**Fixed**

- The release ZIP no longer ships a Jetpack Autoloader class map pointing at files the ZIP omits. In 0.6.0 the class map listed test-only global classes, including `WP_CLI` and `WP_CLI_Command`, and mapped them to files under `tests/phpunit/`, which the release artifact excludes. Any plugin calling `class_exists( 'WP_CLI' )` on a normal web request could therefore trigger an uncaught fatal error. `class_exists( 'WP_CLI' )` now returns `false` when WP-CLI is unavailable ([#283](https://github.com/WordPress/mcp-adapter/issues/283)).

No API, hook, or protocol behavior changed. Upgrading from 0.6.0 requires no migration. Installations built from source or required through Composer were unaffected.

= 0.6.0 - 2026-08-12 =

**Breaking Changes**

- WordPress 6.9 or newer is now required. The standalone Abilities API plugin is no longer a supported installation path.
- Abilities with `meta.public: true` are now exposed through the default MCP server unless `meta.mcp.public` explicitly opts out. Existing permission callbacks and capability checks still apply.
- On multisite only, active Streamable HTTP sessions must reconnect once after upgrading, because session storage moves from a network-wide key to separate per-site keys. Single-site installations are unaffected.
- The MIME validation helpers previously exposed by `McpValidator` have been removed. Integrations calling them directly should apply their own application-specific MIME validation.

**Added**

- Support for `resources/templates/list`, returning an empty template list when no templates are available.
- Blocked direct execution of plugin PHP files.
- Expanded automated compatibility, dependency, and Plugin Check coverage.

**Changed**

- Jetpack Autoloader is now used so the newest available `WP\MCP` classes win when the standalone adapter and another plugin bundle different versions.
- Session storage is scoped by blog on WordPress multisite.
- Concurrent session mutations are protected with bounded retries, reducing the risk of one request overwriting another session.
- Documentation clarifies that the Abilities API is included in WordPress 6.9 and newer, documents the required ability `category` field, and improves examples throughout.

**Fixed**

- `_meta` is preserved on resource contents, embedded resources, content blocks, and prompt messages.
- Malformed `_meta` is omitted without discarding the payload it accompanies.
- `mimeType` is emitted exactly as declared, including values with parameters such as `text/html;profile=mcp-app`.
- Blob-only resource contents are handled correctly.
- Resource URI schemes are matched case-insensitively, so clients can read resources even when they normalize the scheme to lowercase.
- Empty arguments are normalized for schema-defining abilities, allowing valid zero-argument tool calls to execute.
- `wp mcp-adapter serve` keeps JSON-RPC stdout clean when selecting the default server.
- WP-CLI's global `--user` argument is used instead of registering a conflicting local option.

For older releases, see [CHANGELOG.md](https://github.com/WordPress/mcp-adapter/blob/trunk/CHANGELOG.md).

== Upgrade Notice ==

= x.y.z =
Usage of MCP Adapter as a bundled library has been deprecated in favor of using the canonical MCP Adapter plugin. Bundling MCP Adapter as a Composer library is not recommended, as it can lead to conflicts with the MCP Adapter plugin or other plugins that may be bundling their own copy of MCP Adapter.

See the [vx.y.z migration guide](https://github.com/WordPress/mcp-adapter/blob/trunk/docs/migration/vx.y.z.md) for instructions on how to migrate away from a bundled copy of MCP Adapter.

= 0.6.1 =
Repairs the release ZIP so that class_exists( 'WP_CLI' ) no longer risks a fatal error on normal web requests. Anyone running 0.6.0 from the release asset should upgrade.

= 0.6.0 =
This version includes breaking changes. WordPress 6.9 is now required, abilities marked meta.public are exposed through the default server unless they opt out, multisite sessions must reconnect once, and the McpValidator MIME helpers have been removed.

