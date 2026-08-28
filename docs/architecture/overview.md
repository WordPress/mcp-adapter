# Architecture overview

MCP Adapter exposes WordPress Abilities as MCP tools, resources, and prompts
under two exact protocol revisions: `2025-11-25` and `2026-07-28`. It stores
component configuration independently of the protocol, then validates every
request, component projection, result, and response through the exact selected
`php-mcp-schema` catalog.

## Ownership

`wordpress/php-mcp-schema` owns canonical structural validity, record
hydration, method availability, omitted-versus-null behavior, object/list
identity, and serialization. MCP Adapter owns:

- exact revision negotiation and lifecycle state;
- HTTP headers, legacy sessions, and STDIO line handling;
- WordPress Ability registration, permissions, and execution;
- per-revision component projection and availability;
- conservative Adapter-owned result defaults;
- filters, hooks, errors, and observability; and
- the intersection between canonical methods and implemented handlers.

The Adapter does not copy schema records, disable schema validation, or infer a
revision from a global default.

## Request flow

Both HTTP and STDIO feed raw JSON into `JsonRpcRequestDecoder`. The decoder
rejects malformed JSON, batches, excessive depth, integers outside PHP's native
range, and non-finite numbers before they can enter dispatch. It preserves JSON
objects as `stdClass` and lists as arrays.

`McpWireOrchestrator` then:

1. extracts only the envelope fields needed to select a revision;
2. constructs one immutable `McpRequestContext` containing the exact revision,
   selected `Schema`, client identity/capabilities, and transport metadata;
3. applies the function-only profile keyed by that exact revision;
4. verifies canonical method availability and the Adapter handler intersection;
5. hydrates the exact request record before dispatch;
6. routes the validated request record to a typed handler and derives legacy
   associative arguments only at the Ability callback boundary; and
7. projects logical handler output through the selected profile into exact
   result and response records before serialization.

Invalid requests do not reach handlers. Invalid handler output does not reach
the wire.

## Exact lifecycle profiles

### `2025-11-25`

The legacy profile implements `initialize`,
`notifications/initialized`, and `ping`. HTTP initialization may create a
WordPress-user-bound `Mcp-Session-Id`; subsequent HTTP requests require that
session and exact `MCP-Protocol-Version: 2025-11-25`. STDIO retains the
initialization context in the bridge process.

An unsupported initialization proposal receives exact `2025-11-25` as the
counter-proposal. The profile never negotiates `2026-07-28` through
`initialize`.

### `2026-07-28`

The modern profile is sessionless. Every request supplies exact protocol
version and client capabilities in `params._meta`; `clientInfo` is retained when
present. `server/discover` is implemented and `initialize` and `ping` are not
available.

Modern HTTP validates `MCP-Protocol-Version`, `Mcp-Method`, applicable
`Mcp-Name`, and declared `Mcp-Param-*` values against the body. Missing or
mismatched headers return HTTP 400 with `-32020`. Unsupported per-request
versions return `-32022` with `requested` and `supported`. Removed or
unimplemented methods return `-32601`. A present browser `Origin` must match an
allowed WordPress origin before the request is processed.

STDIO has no header layer. A single bridge can process initialized 2025 lines
and self-contained 2026 lines without changing global revision state.

## Component registration

`McpTool`, `McpResource`, and `McpPrompt` retain neutral protocol data alongside
their Ability or callable execution strategy. Each component projects through
both exact catalogs independently and caches successful immutable records.

- A projection failure removes the component only from that revision.
- Registration is rejected only when every supported projection fails.
- Projection failures are logged with the exact revision and reason.
- Protocol-facing server getters require a selected `Schema`.
- CLI diagnostics report both neutral registration counts and per-revision
  availability.

Adapter-owned 2026 Tool projection omits the removed `execution` field.
Peer-supplied extension data remains the schema runtime's responsibility.
`x-mcp-header` annotations are checked as a modern HTTP projection constraint;
an invalid annotation can therefore make a tool unavailable only in 2026.

## Results and defaults

Handlers receive validated request records and preserve existing execution,
permission, and hook behavior. Existing Ability callbacks still receive and
return the same logical WordPress values. The selected wire profile constructs
the final generated result record and supplies fields the modern schema
requires:

- `resultType: "complete"` on every successful result;
- `ttlMs: 0` and `cacheScope: "private"` on discovery, list, and resource-read
  results.
- `io.modelcontextprotocol/serverInfo` metadata on successful modern results.

The Adapter does not emit `input_required` because it does not implement that
optional capability.

List filters retain their original first two arguments and receive the selected
`Schema` as the third argument. The filtered list is validated again when its
final result record is constructed.

## Extension and compatibility boundaries

The following remain stable unless exact protocol behavior requires otherwise:

- Ability registration, permission callbacks, and execution callbacks;
- transport permission interfaces;
- handler result and pre-execution filters;
- component registration and request observability; and
- public action/filter names and original argument order.

Direct consumers of the removed generated schema classes must migrate. There is
no compatibility alias, wrapper, alternate serialization method, or validation
toggle. See the [dual-revision migration guide](../migration/dual-revision-schema-runtime.md).

## Verification

The repository gates are:

```bash
npm run test:php
npm run lint:php
npm run lint:php:stan
npm run plugin-zip
```

The PHPUnit corpus includes conforming raw HTTP and STDIO lifecycle, tool,
resource, and prompt positives for both revisions; cross-revision method
negatives; Origin, header, metadata, numeric, and unsupported-version failures;
ordinary Ability execution; hook preservation; isolated component projections;
and forbidden-symbol scans.
