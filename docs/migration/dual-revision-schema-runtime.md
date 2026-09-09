# Migrating to the dual-revision schema runtime

MCP Adapter now supports exact MCP `2025-11-25` and `2026-07-28` through the
revision-selected `wordpress/php-mcp-schema` record runtime. Clients that
propose `2025-06-18`, `2025-03-26`, or `2024-11-05` keep working: the
identifier is echoed back and the session is served through the `2025-11-25`
schema. Ordinary Ability authors do not add protocol branches; direct
Adapter/schema consumers must make the changes below.

## What does not change

Ability registration remains revision-neutral. Keep existing:

- `input_schema` and `output_schema` declarations;
- execute and permission callbacks;
- `meta.mcp` tool/resource/prompt configuration; and
- Adapter pre-execution and result filters.

The Adapter supplies modern result/cache fields and omits removed standardized
fields.

## Select an exact schema

Protocol-facing server getters now require a selected schema. Reuse the
server-owned schema cache:

```php
use WP\McpSchema\Schemas;

$schema = $server->get_schemas()->forVersion( Schemas::V2026_07_28 );
$tools  = $server->get_tools( $schema );
```

There is no no-argument overload or implicit 2025 default. Schema identifiers
are exactly `2025-11-25` and `2026-07-28`. Legacy identifiers listed in
`McpVersionNegotiator::LEGACY_PROTOCOL_VERSIONS` are negotiated by name and
resolve to the `2025-11-25` schema through
`McpVersionNegotiator::schema_version_for()`.

## Replace removed schema classes

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

Replace complete serialization methods with `jsonSerialize()` or direct JSON
encoding. Records also provide named getters, `get()`, and `has()`.

The removed API includes:

- the old area-specific schema namespaces and factories;
- generated enum objects and union factories;
- `get_protocol_dto()`;
- static construction on generated values;
- alternate complete array serializers; and
- validation state and the `mcp_adapter_validation_enabled` filter.

There are no aliases or compatibility facades.

## Component access

`McpTool`, `McpResource`, and `McpPrompt` expose
`get_protocol_record( Schema $schema )`. Use `is_available_for()` when a caller
needs to inspect per-revision availability. A component can be valid for one
revision and absent from another.

The CLI reports neutral registration counts plus per-revision availability. Wire
discovery returns only records valid for the selected revision.

## Validation and rejected components

The selected schema validates protocol fields. The Adapter no longer drops malformed
icons or `_meta`, coerces annotation values, repairs prompt roles or content types,
or filters invalid prompt arguments. Fix the source data instead of relying on
partial output. This reverses the 0.6.0 behavior that omitted malformed `_meta`
while keeping its payload. Explicit `null` in PHP configuration still counts as absent.

A component that fails projection in every supported revision is excluded from
registration. Each failed revision is logged through the server's error handler;
a completely rejected component also raises `_doing_it_wrong` with the component
identifier, revisions, and schema error paths (for example, `/icons/0/src`). A
component valid for one revision remains registered and is exposed only in that
revision. Invalid handler output fails final projection with JSON-RPC `-32603`,
`Internal error: Invalid handler result`. Modern result assembly adds server metadata
before projection; its existing handling of malformed result-level `_meta` can
still alter that value. The pass-through changes do not remove that limitation.

The Adapter retains tool/prompt name checks and resource URI scheme checks.
Resource URIs are used without trimming, have no 2048-byte limit, and may have an
empty path such as `wordpress:`. Resource `lastModified` remains an additional
client-compatibility check: include a date, time, and time zone, for example
`2026-09-09T12:00:00.123Z`. An invalid value rejects the resource on both the
Ability and `fromArray()` paths.

### Ability metadata and direct factories

- `meta.mcp`, when set, must be an array. All three Ability converters return
  `mcp_ability_invalid_meta` for a non-array value.
- Resource abilities support top-level `meta.annotations`, matching WordPress
  core and tool abilities. The old deprecation notice is removed. Resource
  `meta.mcp.annotations` overrides it; an empty array means no annotations.
  Mapping still drops core's null defaults, but does not coerce values.
- Ability labels and descriptions retain whitespace. Tool `title` is always
  emitted from the Ability label. Direct resource names may be empty; an absent
  name defaults to the URI. The resource-name filter rejects non-strings only.
- `McpTool::fromArray()` supplies `{ "type": "object" }` only when `inputSchema`
  is absent. A supplied schema must declare its own root type; typeless or
  non-object values are no longer repaired before projection.
- Explicit prompt arguments are reindexed and passed to the schema as given.
  Non-array `meta.mcp.arguments` returns `mcp_prompt_invalid_arguments`. An empty
  array retains the input-schema fallback. That fallback includes every property,
  including boolean property schemas, and preserves argument titles and descriptions.
- `mimeType`, `size`, annotations, icons, and other optional protocol fields reach
  the schema without the previous type filtering. A value the schema accepts,
  such as negative resource `size`, is no longer silently omitted.

`mcp_resource_missing_name`, `mcp_prompt_invalid_argument`, and
`mcp_prompt_argument_missing_name` are removed. Schema projection errors replace
these checks. `mcp_resource_name_filter_invalid` now applies only to non-strings;
`mcp_resource_missing_uri` applies only to an absent/null URI. Malformed URI values
return `mcp_resource_invalid_uri` for direct factories or `resource_uri_invalid`
for Ability conversion. Invalid resource timestamps return
`mcp_resource_invalid_annotations` or `resource_annotations_invalid`, respectively.

### Prompt result conveniences

The `text`, `texts`, `messages`, and `role` plus `content` shapes remain supported.
Shape selection uses key presence, so `array( 'messages' => 'bad' )` reaches schema
validation instead of becoming JSON fallback text. Message lists are reindexed;
message keys and result `_meta` are preserved. Empty `messages` stays empty.
Result descriptions and text-shape annotations are carried as supplied. JSON
fallback applies only when no recognized shape is set, and encoding failure
returns a prompt execution error instead of invented `{}` content.

### Removed helpers and converter methods

Prefer `McpResource::fromAbility()` and `McpPrompt::fromAbility()` for components.
`RegisterAbilityAsMcpResource` and `RegisterAbilityAsMcpPrompt` are now `@internal`.
Their `make()` methods and the resource `get_resource()` / `get_data()` and prompt
`get_prompt()` / `get_data()` layers are removed. Direct internal consumers use
`build()` and check for `WP_Error` before reading `resource_data` or `prompt_data`
from its returned array; `adapter_meta` is returned alongside it.

Removed utility methods:

- `ContentBlockHelper::error_text()`, `json_text()`, `to_array_list()`, and `audio()`;
- `McpValidator::normalize_meta()`, `validate_base64()`, `validate_icons_array()`,
  `get_icon_validation_errors()`, `validate_icon_src()`, `validate_icon_size()`,
  `validate_icon_theme()`, `validate_roles_array()`, `validate_role()`, and
  `validate_priority()`.

Build revision-neutral content arrays for final schema projection instead of
calling the removed content helpers. Protocol field validation belongs to the
selected schema; application-specific checks belong to the integration.

## Direct Adapter integrations

Protocol-facing Adapter internals now receive validated records and an exact
request context:

| Surface                              | Current contract                                                                                                            |
| ------------------------------------ | --------------------------------------------------------------------------------------------------------------------------- |
| Custom HTTP transports               | Delegate raw `WP_REST_Request` objects through `HttpRequestHandler` and `HttpRequestContext`.                               |
| Other custom transports              | Decode and process raw JSON through `McpWireOrchestrator`.                                                                  |
| `RequestRouter::route_request()`     | Accepts a generated request `Record`, `McpRequestContext`, and transport name. Do not pass raw method and parameter arrays. |
| Method handlers                      | Accept the exact generated request record and `McpRequestContext`; return logical arrays for final schema projection.       |
| `McpErrorFactory`                    | Returns logical JSON-RPC error arrays. Pass the selected revision to `resource_not_found()`.                                |
| `ContentBlockHelper`                 | Returns revision-neutral content arrays for final schema hydration.                                                         |
| `McpPromptBuilderInterface::build()` | Returns the revision-neutral prompt configuration array.                                                                    |

See [Custom transports](../guides/custom-transports.md) and
[Error handling](../guides/error-handling.md) for complete examples.

## Filters

Tool, resource, and prompt list filters keep their existing first two arguments
and receive the selected schema third:

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

Filter payloads are generated records. A filtered list is validated when the
final list-result record is constructed.

## Revision removals and replacements

- `initialize`, `notifications/initialized`, and `ping` are 2025-only.
- `server/discover` and per-request metadata are 2026-only.
- `tools/list/all` is not canonical in either supported revision and is no longer
  dispatchable.
- `Tool.execution` is omitted from Adapter-owned 2026 output.
- 2026 completed results include `resultType: "complete"`.
- 2026 discovery/list/resource-read results include `ttlMs: 0` and
  `cacheScope: "private"`.
- 2026 resource misses use `-32602`; unsupported per-request versions use
  `-32022`.
- Missing tools and prompts use standard Invalid Params (`-32602`) in both
  revisions.

## Transport changes

The 2025 HTTP lifecycle remains session-based. Modern HTTP is sessionless and
requires body metadata plus `MCP-Protocol-Version`, `Mcp-Method`, applicable
`Mcp-Name`, and declared `Mcp-Param-*` headers. STDIO carries revision metadata
in each modern request body and can alternate exact revisions line by line.

Batch requests are rejected before dispatch.

## Verification

After migrating a direct integration, run:

```bash
npm run test:php
npm run lint:php
npm run lint:php:stan
```

Add raw-wire tests for each revision your integration sends and for any method or
field that was removed between them.
