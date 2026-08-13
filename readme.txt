=== MCP Adapter ===
Contributors:      wordpressdotorg, ovidiu-galatan
Tags:              mcp, ai, abilities-api, model-context-protocol
Requires at least: 6.9
Tested up to:      7.0
Requires PHP:      7.4
Stable tag:        0.6.1
License:           GPL-2.0-or-later
License URI:       https://spdx.org/licenses/GPL-2.0-or-later.html

Expose WordPress abilities as Model Context Protocol (MCP) tools, resources, and prompts for AI agents.

== Description ==

Part of the [AI Building Blocks for WordPress](https://make.wordpress.org/ai/2025/07/17/ai-building-blocks) initiative.

The MCP Adapter bridges WordPress's Abilities API with the [Model Context Protocol (MCP)](https://modelcontextprotocol.io) specification, providing a standardized way for AI agents to interact with WordPress functionality. It includes HTTP and STDIO transport support, comprehensive error handling, and an extensible architecture for custom integrations.

**Features:**

* **Ability-to-MCP Conversion** – Automatically converts WordPress abilities into MCP tools, resources, and prompts.
* **Multi-Server Management** – Create and manage multiple MCP servers with unique configurations.
* **Extensible Transport Layer** – Built-in HTTP and STDIO transports, plus support for custom transport protocols.
* **Flexible Error Handling** – Default WordPress-compatible error logging with support for custom, server-specific handlers.
* **Observability** – Zero-overhead metrics tracking with configurable handlers.
* **Permission Control** – Granular, configurable permission checking for all exposed functionality.

== Installation ==

1. Go to **Plugins > Add New** in your WordPress admin, search for "MCP Adapter", and click **Install Now**.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Register the abilities you want to expose, or install a plugin that registers them for you. Permission checks run against the current user before anything is executed.

You can also upload the plugin ZIP via **Plugins > Add New > Upload Plugin**, or install it with WP-CLI:

`wp plugin install mcp-adapter --activate`

WordPress 6.9 or newer is required, as the Abilities API ships in WordPress core from that release.

== For Developers ==

MCP Adapter is built to be extended, and can also be consumed directly as a library.

**Use it as a Composer dependency:**

`composer require wordpress/mcp-adapter`

If you vendor the library inside your own plugin, load it through the [Jetpack Autoloader](https://github.com/Automattic/jetpack-autoloader) so that every plugin on the site resolves the same `WP\MCP` implementation. Running an independently namespace-prefixed copy alongside the plugin is not supported.

**Extend the adapter:**

* **Custom Transports** – Implement `McpTransportInterface` to add transport protocols beyond the built-in HTTP and STDIO support.
* **Custom Error Handlers** – Implement `McpErrorHandlerInterface` for per-server error handling strategies.
* **Observability Handlers** – Record events and timings into your own metrics pipeline.
* **Multiple Servers** – Use `create_server()` to run several MCP servers with independent configurations.
* **Hooks** – Filters and actions throughout the codebase, all prefixed `mcp_adapter_`.

**Get started:**

1. Read the [developer documentation](https://github.com/WordPress/mcp-adapter/tree/trunk/docs) for architecture, getting started guides, and troubleshooting.
2. Read the [Contributing Guide](https://github.com/WordPress/mcp-adapter/blob/trunk/CONTRIBUTING.md) for development setup.
3. Join the conversation in [#core-ai on WordPress Slack](https://wordpress.slack.com/archives/C08TJ8BPULS).
4. Browse the [GitHub repository](https://github.com/WordPress/mcp-adapter) and open issues or pull requests.

== Frequently Asked Questions ==

= What do I need to run this? =

WordPress 6.9 or newer and PHP 7.4 or newer. The Abilities API is included in WordPress core from 6.9, so no additional plugin is required.

= How do AI agents connect to my site? =

The adapter registers MCP endpoints over the built-in HTTP transport. Point an MCP-compatible client at the endpoint and authenticate as a WordPress user. The adapter negotiates MCP protocol versions `2025-11-25`, `2025-06-18` and `2024-11-05`. See the [getting started guide](https://github.com/WordPress/mcp-adapter/tree/trunk/docs/getting-started) for endpoint details.

= Does every registered ability get exposed automatically? =

No, exposure is opt-in. An ability is exposed through the default MCP server when `meta.mcp.public` is true, or when it isn't set and the general `meta.public` flag is true. Setting `meta.mcp.public` to false always wins. Permission callbacks and capability checks still run against the current user before anything executes.

Note that since 0.6.0, an ability marked `meta.public` for reasons unrelated to MCP will be exposed unless it opts out. If you register public abilities, check whether you want them reachable over MCP.

= Can another plugin bundle MCP Adapter as a library? =

Yes, via `composer require wordpress/mcp-adapter`. Load it through the Jetpack Autoloader so that all plugins on the site resolve the same `WP\MCP` implementation. Running an independently namespace-prefixed copy alongside the plugin is not supported.

= Is this safe to use on a production site? =

The adapter applies permission checks before executing any ability, and exposure is opt-in rather than automatic. As with any feature that gives external clients access to site functionality, review which abilities you expose and to which user roles before enabling it in production.

= Where do I report bugs or request features? =

On the [GitHub repository](https://github.com/WordPress/mcp-adapter/issues). Security issues should follow the process in [SECURITY.md](https://github.com/WordPress/mcp-adapter/blob/trunk/SECURITY.md) rather than being reported publicly.

== Changelog ==

= 0.6.1 - 2026-08-13 =

**Fixed**

- The release ZIP no longer ships a Jetpack Autoloader class map pointing at files the ZIP omits. In 0.6.0 the class map listed test-only global classes, including `WP_CLI` and `WP_CLI_Command`, and mapped them to files under `tests/phpunit/`, which the release artifact excludes. Any plugin calling `class_exists( 'WP_CLI' )` on a normal web request could therefore trigger an uncaught fatal error. `class_exists( 'WP_CLI' )` now returns `false` when WP-CLI is unavailable ([#283](https://github.com/WordPress/mcp-adapter/issues/283)).

No API, hook, or protocol behaviour changed. Upgrading from 0.6.0 requires no migration. Installations built from source or required through Composer were unaffected.

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
- Resource URI schemes are matched case-insensitively, so clients can read resources even when they normalise the scheme to lowercase.
- Empty arguments are normalised for schema-defining abilities, allowing valid zero-argument tool calls to execute.
- `wp mcp-adapter serve` keeps JSON-RPC stdout clean when selecting the default server.
- WP-CLI's global `--user` argument is used instead of registering a conflicting local option.

= 0.5.0 - 2026-04-15 =

**Added**

- Full integration of [`wordpress/php-mcp-schema`](https://github.com/WordPress/php-mcp-schema) throughout the adapter, so MCP responses use typed protocol DTOs instead of hand-built arrays.
- Typed DTO handling for MCP tools, resources, prompts, initialization, and JSON-RPC errors.
- Protocol version negotiation for `2025-11-25`, `2025-06-18`, and `2024-11-05`.

**Changed**

- Validation, encapsulation, and type-safety improvements across the core and domain layers.
- Security hardening with stricter input validation and fail-closed permission handling.
- Reduced `SessionManager` write amplification to lower lock contention.
- Packaging and dependency updates, including the `php-mcp-schema` v0.1.1 follow-up for cleaner Composer dist archives.

**Fixed**

- Improved observability for protocol errors and `isError` tool responses.

Existing ability registration, `create_server()`, and WordPress hooks are unchanged. Custom handlers, transports, or code depending on internal component structures should review the [v0.5.0 migration guide](https://github.com/WordPress/mcp-adapter/blob/trunk/docs/migration/v0.5.0.md).

= 0.4.1 - 2025-12-09 =

**Fixed**

- Corrected JSON-RPC error response structure and HTTP status codes ([#106](https://github.com/WordPress/mcp-adapter/pull/106)).
- Error messages are now returned properly when using unnested error formats in `ToolsHandler` ([#90](https://github.com/WordPress/mcp-adapter/pull/90)).

= 0.4.0 - 2025-12-04 =

**Added**

- Automatic transformation of flattened schemas (string, number, boolean, array) into MCP-compatible object schemas, so abilities using flattened schemas now work. The MCP specification requires all tool input schemas to be of type `object` ([#93](https://github.com/WordPress/mcp-adapter/pull/93)).
- `.gitattributes` to exclude development files from releases ([#94](https://github.com/WordPress/mcp-adapter/pull/94)).
- GNU General Public License v2 ([#97](https://github.com/WordPress/mcp-adapter/pull/97)).

**Changed**

- Applied WordPress PHP documentation standards ([#92](https://github.com/WordPress/mcp-adapter/pull/92)).

**Fixed**

- Annotation field name mismatches between the Abilities API format and the MCP specification. `readonly` now maps to `readOnlyHint`, `destructive` to `destructiveHint`, and `idempotent` to `idempotentHint` ([#91](https://github.com/WordPress/mcp-adapter/pull/91)).
- Missing comma in the transport permissions example ([#85](https://github.com/WordPress/mcp-adapter/pull/85)).

= 0.3.0 - 2025-11-06 =

**Breaking Changes**

- `RestTransport` and `StreamableTransport` have been removed and replaced by the unified `HttpTransport` class ([#48](https://github.com/WordPress/mcp-adapter/pull/48)).
- Observability events now use unified names with a `status` tag instead of separate success and failure event names.
- Observability handlers now use instance methods instead of static methods, and `McpObservabilityHelperTrait::record_error_event()` has been removed.
- All filter and action names now use the `mcp_adapter_` prefix ([#81](https://github.com/WordPress/mcp-adapter/pull/81)).

**Added**

- Unified `HttpTransport` with session management, streaming support, and enhanced error handling ([#48](https://github.com/WordPress/mcp-adapter/pull/48)).
- Metadata-driven observability, recorded centrally at the transport layer with automatic metadata extraction from handler responses.

**Changed**

- Error handling refactored to use the `WP_Error` pattern throughout, replacing exceptions ([#71](https://github.com/WordPress/mcp-adapter/pull/71)).

**Fixed**

- Tool error handling now complies with the MCP specification ([#73](https://github.com/WordPress/mcp-adapter/pull/73)).
- Metadata no longer leaks into tool response content ([#72](https://github.com/WordPress/mcp-adapter/pull/72)).
- `WP_Error` handling in `PromptsHandler` and `ResourcesHandler` ([#74](https://github.com/WordPress/mcp-adapter/pull/74)).
- Null parameter handling in Prompts and Resources handlers ([#77](https://github.com/WordPress/mcp-adapter/pull/77)).
- Parameter handling for abilities without input schemas ([#76](https://github.com/WordPress/mcp-adapter/pull/76)).
- Prompt parameter validation, by converting `input_schema` to MCP arguments ([#78](https://github.com/WordPress/mcp-adapter/pull/78)).
- The `init` action now fires before initializing in WP-CLI ([#86](https://github.com/WordPress/mcp-adapter/pull/86)).

See the [v0.3.0 migration guide](https://github.com/WordPress/mcp-adapter/blob/trunk/docs/migration/v0.3.0.md) for detailed upgrade instructions.

= 0.1.0 - 2025-08-14 =

**Added**

- First stable release: ability-to-MCP conversion, multi-server management, extensible transport layer, error handling, observability, validation, and granular permission control.

== Upgrade Notice ==

= 0.6.1 =
Repairs the release ZIP so that class_exists( 'WP_CLI' ) no longer risks a fatal error on normal web requests. Anyone running 0.6.0 from the release asset should upgrade.

= 0.6.0 =
This version includes breaking changes. WordPress 6.9 is now required, abilities marked meta.public are exposed through the default server unless they opt out, multisite sessions must reconnect once, and the McpValidator MIME helpers have been removed.

= 0.5.0 =
Internal upgrade to typed protocol DTOs. Custom handlers, transports, or code depending on internal component structures should review the migration guide.

= 0.3.0 =
This version includes breaking changes. RestTransport and StreamableTransport are replaced by HttpTransport, observability handlers move to instance methods, and hook names are prefixed with mcp_adapter_.
