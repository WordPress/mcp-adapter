# Migrating to the dual-revision schema runtime

MCP Adapter now supports exact MCP `2025-11-25` and `2026-07-28` through the
revision-selected `wordpress/php-mcp-schema` record runtime. Ordinary Ability
authors do not add protocol branches; direct Adapter/schema consumers must make
the changes below.

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

There is no no-argument overload or implicit 2025 default. Supported identifiers
are exactly `2025-11-25` and `2026-07-28`.

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

Present browser `Origin` headers must match the WordPress installation or an
origin allowed through `mcp_adapter_allowed_http_origins`.

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
