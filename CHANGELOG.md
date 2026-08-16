# Changelog

All notable changes to this project will be documented in this file, per [the Keep a Changelog standard](http://keepachangelog.com/), and will adhere to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased] - TBD

### Fixed
- A second copy of MCP Adapter loaded as a plugin no longer takes the site down. Because the package type is `wordpress-plugin`, Composer relocates a `composer require wordpress/mcp-adapter` install into `wp-content/plugins/`, where it can be activated alongside the canonical plugin. WordPress then loaded `mcp-adapter.php` twice and fataled with `Cannot redeclare WP\MCP\constants()`. The second copy now bails out instead.
- The "Composer autoloader was not found" admin notice is no longer shown when the `WP\MCP\` classes are already provided by another autoloader. A copy installed as a Composer dependency has no `vendor/` directory of its own, because Composer flattens dependencies into the root project, so the notice pointed at a file that was never going to exist. A source checkout with genuinely missing dependencies still gets the notice.

### Added
- A `_doing_it_wrong()` notice when the MCP Adapter code being executed is not the plugin's own copy, pointing developers at the `Requires Plugins` header. Two cases are covered: the plugin is not active and something else supplied the classes, reported from `McpAdapter::instance()`; and the plugin is active but a copy bundled inside another plugin won the autoload race, reported from the plugin bootstrap and naming the offending file and both version numbers. The notice is only visible with `WP_DEBUG` enabled and does not change runtime behavior.
- A [migration guide](docs/migration/composer-to-plugin.md) for plugins that bundle MCP Adapter as a Composer library, covering the `Requires Plugins` header, removing the Jetpack Autoloader, and what still works during the transition.

## [0.6.1] - 2026-08-13

### Fixed
- The release ZIP no longer ships a Jetpack Autoloader class map pointing at files the ZIP omits. In 0.6.0 the class map listed test-only global classes, including `WP_CLI` and `WP_CLI_Command`, and mapped them to files under `tests/phpunit/`, which the release artifact excludes. Any plugin calling `class_exists( 'WP_CLI' )` on a normal web request could therefore trigger an uncaught fatal error. `class_exists( 'WP_CLI' )` now returns `false` when WP-CLI is unavailable ([#283](https://github.com/WordPress/mcp-adapter/issues/283)).

No API, hook, or protocol behavior changed. Upgrading from 0.6.0 requires no migration. Installations built from source or required through Composer were unaffected.

## [0.6.0] - 2026-08-12

### Breaking Changes
- WordPress 6.9 or newer is now required. The standalone Abilities API plugin is no longer a supported installation path.
- Abilities with `meta.public: true` are now exposed through the default MCP server unless `meta.mcp.public` explicitly opts out. Existing permission callbacks and capability checks still apply.
- On multisite only, active Streamable HTTP sessions must reconnect once after upgrading, because session storage moves from a network-wide key to separate per-site keys. Single-site installations are unaffected.
- The MIME validation helpers previously exposed by `McpValidator` have been removed. Integrations calling them directly should apply their own application-specific MIME validation.

### Added
- Support for `resources/templates/list`, returning an empty template list when no templates are available.
- Blocked direct execution of plugin PHP files.
- Expanded automated compatibility, dependency, and Plugin Check coverage.

### Changed
- Jetpack Autoloader is now used so the newest available `WP\MCP` classes win when the standalone adapter and another plugin bundle different versions.
- Session storage is scoped by blog on WordPress multisite.
- Concurrent session mutations are protected with bounded retries, reducing the risk of one request overwriting another session.
- Documentation clarifies that the Abilities API is included in WordPress 6.9 and newer, documents the required ability `category` field, and improves examples throughout.

### Fixed
- `_meta` is preserved on resource contents, embedded resources, content blocks, and prompt messages.
- Malformed `_meta` is omitted without discarding the payload it accompanies.
- `mimeType` is emitted exactly as declared, including values with parameters such as `text/html;profile=mcp-app`.
- Blob-only resource contents are handled correctly.
- Resource URI schemes are matched case-insensitively, so clients can read resources even when they normalize the scheme to lowercase.
- Empty arguments are normalized for schema-defining abilities, allowing valid zero-argument tool calls to execute.
- `wp mcp-adapter serve` keeps JSON-RPC stdout clean when selecting the default server.
- WP-CLI's global `--user` argument is used instead of registering a conflicting local option.

## [0.5.0] - 2026-04-15

### Added
- Full integration of [`wordpress/php-mcp-schema`](https://github.com/WordPress/php-mcp-schema) throughout the adapter, so MCP responses use typed protocol DTOs instead of hand-built arrays.
- Typed DTO handling for MCP tools, resources, prompts, initialization, and JSON-RPC errors.
- Protocol version negotiation for `2025-11-25`, `2025-06-18`, and `2024-11-05`.

### Changed
- Validation, encapsulation, and type-safety improvements across the core and domain layers.
- Security hardening with stricter input validation and fail-closed permission handling.
- Reduced `SessionManager` write amplification to lower lock contention.
- Packaging and dependency updates, including the `php-mcp-schema` v0.1.1 follow-up for cleaner Composer dist archives.
- Improved observability for protocol errors and `isError` tool responses.


Existing ability registration, `create_server()`, and WordPress hooks are unchanged. Custom handlers, transports, or code depending on internal component structures should review the [v0.5.0 migration guide](https://github.com/WordPress/mcp-adapter/blob/trunk/docs/migration/v0.5.0.md).

## [0.4.1] - 2025-12-09

### Fixed
- Corrected JSON-RPC error response structure and HTTP status codes ([#106](https://github.com/WordPress/mcp-adapter/pull/106)).
- Error messages are now returned properly when using unnested error formats in `ToolsHandler` ([#90](https://github.com/WordPress/mcp-adapter/pull/90)).

## [0.4.0] - 2025-12-04

### Added
- Automatic transformation of flattened schemas (string, number, boolean, array) into MCP-compatible object schemas, so abilities using flattened schemas now work. The MCP specification requires all tool input schemas to be of type `object` ([#93](https://github.com/WordPress/mcp-adapter/pull/93)).
- `.gitattributes` to exclude development files from releases ([#94](https://github.com/WordPress/mcp-adapter/pull/94)).
- GNU General Public License v2 ([#97](https://github.com/WordPress/mcp-adapter/pull/97)).

### Changed
- Applied WordPress PHP documentation standards ([#92](https://github.com/WordPress/mcp-adapter/pull/92)).

### Fixed
- Annotation field name mismatches between the Abilities API format and the MCP specification. `readonly` now maps to `readOnlyHint`, `destructive` to `destructiveHint`, and `idempotent` to `idempotentHint` ([#91](https://github.com/WordPress/mcp-adapter/pull/91)).
- Missing comma in the transport permissions example ([#85](https://github.com/WordPress/mcp-adapter/pull/85)).

## [0.3.0] - 2025-11-06

### Breaking Changes
- `RestTransport` and `StreamableTransport` have been removed and replaced by the unified `HttpTransport` class ([#48](https://github.com/WordPress/mcp-adapter/pull/48)).
- Observability events now use unified names with a `status` tag instead of separate success and failure event names.
- Observability handlers now use instance methods instead of static methods, and `McpObservabilityHelperTrait::record_error_event()` has been removed.
- All filter and action names now use the `mcp_adapter_` prefix ([#81](https://github.com/WordPress/mcp-adapter/pull/81)).

### Added
- Unified `HttpTransport` with session management, streaming support, and enhanced error handling ([#48](https://github.com/WordPress/mcp-adapter/pull/48)).
- Metadata-driven observability, recorded centrally at the transport layer with automatic metadata extraction from handler responses.

### Changed
- Error handling refactored to use the `WP_Error` pattern throughout, replacing exceptions ([#71](https://github.com/WordPress/mcp-adapter/pull/71)).

### Fixed
- Tool error handling now complies with the MCP specification ([#73](https://github.com/WordPress/mcp-adapter/pull/73)).
- Metadata no longer leaks into tool response content ([#72](https://github.com/WordPress/mcp-adapter/pull/72)).
- `WP_Error` handling in `PromptsHandler` and `ResourcesHandler` ([#74](https://github.com/WordPress/mcp-adapter/pull/74)).
- Null parameter handling in Prompts and Resources handlers ([#77](https://github.com/WordPress/mcp-adapter/pull/77)).
- Parameter handling for abilities without input schemas ([#76](https://github.com/WordPress/mcp-adapter/pull/76)).
- Prompt parameter validation, by converting `input_schema` to MCP arguments ([#78](https://github.com/WordPress/mcp-adapter/pull/78)).
- The `init` action now fires before initializing in WP-CLI ([#86](https://github.com/WordPress/mcp-adapter/pull/86)).

See the [v0.3.0 migration guide](https://github.com/WordPress/mcp-adapter/blob/trunk/docs/migration/v0.3.0.md) for detailed upgrade instructions.

## [0.1.0] - 2025-08-14

### Added
- First stable release: ability-to-MCP conversion, multi-server management, extensible transport layer, error handling, observability, validation, and granular permission control.
