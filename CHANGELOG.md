# Changelog

All notable changes to this project will be documented in this file, per [the Keep a Changelog standard](http://keepachangelog.com/), and will adhere to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased] - TBD

### Breaking Changes
- Schema-backed MCP revisions are exactly `2025-11-25` and `2026-07-28`. `2025-06-18` and `2024-11-05` no longer have their own DTOs; they are negotiated as legacy identifiers and served through the `2025-11-25` schema (see Added). `McpVersionNegotiator::SUPPORTED_PROTOCOL_VERSIONS` now lists only the schema-backed revisions; legacy identifiers moved to `McpVersionNegotiator::LEGACY_PROTOCOL_VERSIONS`.
- Protocol-facing server getters `get_tools()`, `get_resources()`, `get_prompts()`, and `get_prompt()` require a selected `Schema`. There is no implicit default revision.
- The generated DTO classes, `get_protocol_dto()`, and the alternate array serializers have been replaced by exact-revision schema records from `wordpress/php-mcp-schema`. See the [dual-revision migration guide](docs/migration/dual-revision-schema-runtime.md).
- `McpToolValidator`, `McpResourceValidator`, `McpPromptValidator`, `McpErrorFactory::validate_jsonrpc_message()`, `McpServer::is_mcp_validation_enabled()`, and the `mcp_adapter_validation_enabled` filter have been removed. Wire validation always runs through the selected schema.
- `RequestRouter::route_request()`, method handlers, `McpErrorFactory`, `ContentBlockHelper`, and `McpPromptBuilderInterface::build()` now exchange schema records and revision-neutral arrays instead of DTOs. `JsonRpcResponseBuilder` has been removed. Custom transports must delegate to `HttpRequestHandler` or `McpWireOrchestrator`.
- The non-canonical `tools/list/all` method and `ToolsHandler::list_all_tools()` have been removed.
- JSON-RPC batch requests are rejected before dispatch.
- `wp mcp-adapter list` adds per-revision tool, resource, and prompt columns.

- Invalid protocol fields are no longer silently dropped or repaired. Components invalid in every supported schema revision are rejected. Handler values reach final schema projection, except that existing 2026 result assembly can still alter malformed result-level `_meta` before validation. This reverses the 0.6.0 omission of malformed `_meta`. See the [validation migration notes](docs/migration/dual-revision-schema-runtime.md#validation-and-rejected-components).
- Invalid protocol fields are no longer silently dropped or repaired. Components invalid in every supported schema revision are rejected. Handler values reach final schema projection. In 2026 responses, result `_meta` is validated before server identification is added, so malformed metadata is rejected and valid object fields are preserved. This reverses the 0.6.0 omission of malformed `_meta`. See the [validation migration notes](docs/migration/dual-revision-schema-runtime.md#validation-and-rejected-components).
- Removed validation and content helpers and the resource/prompt converter `make()` and getter layers are listed in the [migration guide](docs/migration/dual-revision-schema-runtime.md#removed-helpers-and-converter-methods).
- Non-array Ability `meta.mcp` now returns `mcp_ability_invalid_meta`. Removed errors: `mcp_resource_missing_name`, `mcp_prompt_invalid_argument`, and `mcp_prompt_argument_missing_name`. Non-array prompt arguments return `mcp_prompt_invalid_arguments`; resource-name filters reject non-strings only.

### Added
- Legacy MCP identifiers `2025-06-18`, `2025-03-26`, and `2024-11-05` negotiate through `initialize` and are echoed back verbatim while the `2025-11-25` schema serves the session. Every server-emitted difference between those revisions and `2025-11-25` is an optional additive field, so the projection is unchanged. `MCP-Protocol-Version` must match the negotiated identifier when sent, and may be omitted only for sessions negotiated under `2025-03-26` or `2024-11-05`, which predate the header. `2025-03-26` is newly negotiable.
- `McpRequestContext::protocol_version()` returns the negotiated identifier; `revision()` continues to return the schema revision.
- MCP `2026-07-28` support: the sessionless `server/discover` lifecycle, per-request protocol metadata in the request body, and the `MCP-Protocol-Version`, `Mcp-Method`, `Mcp-Name`, and `Mcp-Param-*` headers over HTTP. STDIO accepts either revision per line.
- `McpRequestContext`, an immutable per-request context carrying the selected schema, transport, and session data.
- `McpWireOrchestrator` and `JsonRpcRequestDecoder`, one decode, validate, dispatch, and encode boundary shared by the HTTP and STDIO transports.
- `McpTool`, `McpResource`, and `McpPrompt` expose `get_protocol_record( Schema $schema )` and `is_available_for()`. A component is omitted from a revision whose schema cannot represent it.
- The `mcp_adapter_tools_list`, `mcp_adapter_resources_list`, `mcp_adapter_prompts_list`, and `mcp_adapter_initialize_response` filters receive the selected schema as a third argument.
- Raw-wire, architecture, and projection test coverage for both revisions.
- Components rejected by every supported schema revision raise `_doing_it_wrong` with schema error paths, in addition to error-handler logs.

### Changed
- Resource abilities support core's top-level `meta.annotations` without a deprecation notice. `meta.mcp.annotations` overrides it, and an empty array suppresses annotations instead of falling back.
- Ability labels and descriptions preserve whitespace, and tool `title` is always emitted from the label. Resource names may be empty; absent names use the URI.
- Resource URIs are no longer trimmed or limited to 2048 bytes; bare schemes such as `wordpress:` are accepted. Invalid `lastModified` timestamps reject resources on both registration paths.
- Prompt results preserve supplied descriptions, metadata, message keys, and empty message lists. Malformed recognized shapes reach schema validation; JSON fallback encoding failures return execution errors.
- Direct tool factories default `inputSchema` only when absent. Prompt fallback arguments include boolean property schemas and preserve supplied titles and descriptions.
- Usage of MCP Adapter as a bundled library has been deprecated in favor of using the canonical MCP Adapter plugin. See the [vx.y.z migration guide](migration/vx.y.z.md) for instructions on how to migrate away from a bundled copy of MCP Adapter.
- `initialize`, `notifications/initialized`, and `ping` are served only for `2025-11-25`; `server/discover` only for `2026-07-28`. The 2025 HTTP session lifecycle is unchanged.
- Adapter-owned `2026-07-28` output omits `Tool.execution`, adds `resultType: "complete"` to completed results, and adds `ttlMs: 0` and `cacheScope: "private"` to discovery, list, and resource-read results.
- Missing tools and prompts return Invalid Params (`-32602`) in both revisions. Missing resources return `-32002` in `2025-11-25` and `-32602` in `2026-07-28`. An unsupported per-request version returns `-32022`.
- Session validation errors carry the JSON-RPC request ID of the failing request instead of `null`.
- `wordpress/php-mcp-schema` is temporarily pinned to a reviewed commit through a VCS repository until the dual-revision runtime is released.

### Fixed
- `mcp-adapter/get-ability-info` serializes an empty `input_schema` as `{}` instead of `[]`.

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
