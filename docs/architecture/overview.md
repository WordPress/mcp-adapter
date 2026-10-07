# Architecture overview

MCP Adapter exposes WordPress Abilities as MCP tools, resources, and prompts. It keeps component execution separate from protocol representation so the same registration can serve different MCP revisions.

## Responsibilities

Three layers participate in an Ability-backed request:

| Layer                      | Responsibility                                                                                                                                                                               |
| -------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| WordPress Abilities API    | Stores Ability registrations and owns Ability permission checks, input/output validation, and callback execution.                                                                            |
| `wordpress/php-mcp-schema` | Provides revision-specific catalogs, structural validation, generated records, field-presence tracking, and JSON serialization.                                                              |
| MCP Adapter                | Selects exposed components, prepares WordPress-facing arguments, delegates Ability execution, and implements MCP negotiation, transport, dispatch, result mapping, hooks, and observability. |

The Adapter uses the schema package rather than maintaining a second set of protocol DTOs. The package describes which methods and records exist in a revision; the Adapter separately determines which methods it implements. See [McpWireOrchestrator](../../includes/Transport/Infrastructure/McpWireOrchestrator.php) for that intersection and [composer.lock](../../composer.lock) for the schema dependency in use.

MCP structural validation and WordPress Ability validation serve different purposes. A request can satisfy the MCP `tools/call` schema and still contain arguments that the selected Ability rejects.

## Startup and server composition

[Plugin](../../includes/Plugin.php) initializes the [McpAdapter](../../includes/Core/McpAdapter.php) singleton. The Adapter initializes on `rest_api_init` for REST requests, or on `init` for WP-CLI. During initialization it fires `mcp_adapter_init`; integrations use that action to call `create_server()`. That method returns the Adapter on success and `WP_Error` for invalid timing, duplicate server IDs, invalid error or observability handler classes, or caught server-construction failures. Invalid transport classes and rejected components can instead be reported and skipped while the server is still registered.

Each [McpServer](../../includes/Core/McpServer.php) owns:

- a schema catalog provider (`Schemas`);
- a [McpComponentRegistry](../../includes/Core/McpComponentRegistry.php) holding its tools, resources, and prompts;
- error and observability handlers; and
- a [McpTransportFactory](../../includes/Core/McpTransportFactory.php) that constructs configured transports.

The transport factory creates a [McpTransportContext](../../includes/Transport/Infrastructure/McpTransportContext.php) containing the server, method handlers, router, error handler, observability handler, and optional transport permission callback. This is the dependency container for a transport. A separate [McpRequestContext](../../includes/Core/McpRequestContext.php) carries the selected schema, negotiated protocol identifier, client information/capabilities, and transport metadata for one request. It defensively copies JSON-compatible context data on construction and access.

### Default server and custom servers

[DefaultServerFactory](../../includes/Servers/DefaultServerFactory.php) creates `/wp-json/mcp/mcp-adapter-default-server`. Its tool list contains three meta-tools: `mcp-adapter-discover-abilities`, `mcp-adapter-get-ability-info`, and `mcp-adapter-execute-ability`. These MCP names are derived from WordPress Ability identifiers such as `mcp-adapter/discover-abilities` by replacing the slash with a hyphen. It also discovers publicly exposed resources and prompts.

[McpAbilityExposure](../../includes/Abilities/McpAbilityExposure.php) resolves default-server exposure from the stored Ability metadata: a non-null `meta.mcp.public` takes precedence; otherwise exposure follows `meta.public`. A missing or null `meta.mcp` is treated as absent; a non-null value that is not an array fails closed. Custom servers explicitly select their component lists; the component registry does not apply this default-server exposure policy to every registration.

See [Default server](../guides/default-server.md) and [Creating abilities](../guides/creating-abilities.md) for configuration examples.

## Request and response flow

### Transport access

For HTTP, WordPress runs [HttpTransport::check_permission()](../../includes/Transport/HttpTransport.php) before invoking the request handler. A custom transport permission callback replaces the default capability check, which uses `current_user_can( 'read' )` unless filtered. This endpoint-level check is separate from the selected component's permission check.

[HttpRequestHandler](../../includes/Transport/Infrastructure/HttpRequestHandler.php) handles HTTP methods, legacy sessions, and HTTP response status. [StdioServerBridge](../../includes/Cli/StdioServerBridge.php) reads newline-delimited JSON and writes responses to STDOUT, with diagnostics on STDERR. STDIO uses the WordPress user context selected through WP-CLI; it does not run the HTTP permission callback.

### MCP validation and dispatch

Both built-in transports pass raw JSON through the same [McpWireOrchestrator](../../includes/Transport/Infrastructure/McpWireOrchestrator.php):

1. [JsonRpcRequestDecoder](../../includes/Transport/Infrastructure/JsonRpcRequestDecoder.php) decodes JSON, preserving objects as `stdClass` and lists as arrays. It rejects malformed JSON, batches, excessive depth, out-of-range integer tokens, and non-finite numbers.
2. The orchestrator inspects the envelope and revision metadata. It builds an associative view of the message while retaining the original decoded object for schema hydration. It validates applicable HTTP headers and constructs the request context. Envelope-header checks precede context construction; tool parameter-header checks use the selected context.
3. For supported requests, the orchestrator checks both catalog availability and Adapter implementation, then hydrates the concrete request record. Failed hydration stops dispatch. `server/discover` bypasses custom logical routing within the request completion scope; ordinary method requests go through [RequestRouter](../../includes/Transport/Infrastructure/RequestRouter.php) to typed handlers. The supported `notifications/initialized` notification is validated and then ignored.
4. Handlers return logical result data or error arrays. The orchestrator projects results into the selected schema's records and constructs the response envelope. Failed result projection produces an internal error response.
5. The transport serializes the response and delivers it through HTTP or STDIO.

Decoding, initial envelope, and transport/session failures can return plain error arrays before a schema-backed request context exists. Consequently, not every outgoing error has passed through schema hydration. The selected-schema checks also do not replace the Ability's own validation or authorization.

### Component execution

The [tool](../../includes/Handlers/Tools/ToolsHandler.php), [resource](../../includes/Handlers/Resources/ResourcesHandler.php), and [prompt](../../includes/Handlers/Prompts/PromptsHandler.php) handlers follow the same broad execution sequence:

1. Find the component and confirm it is available for the selected revision.
2. Check component permissions using the prepared WordPress-facing arguments.
3. Apply the pre-execution filter, which can return `WP_Error` to stop execution.
4. Execute the component, then apply the result filter.
5. Map the result or error into logical MCP data for final schema projection.

[HandlerHelperTrait](../../includes/Handlers/HandlerHelperTrait.php) converts protocol argument objects into associative arrays before permission checks, filters, and execution. Ability-backed tools and prompts also unwrap transformed inputs and use [AbilityArgumentNormalizer](../../includes/Domain/Utils/AbilityArgumentNormalizer.php) to normalize empty or null arguments according to the Ability’s input schema, including its default and nullable type, before delegating to WordPress. Ability-backed resources call their Ability without arguments; direct resource callables receive the prepared read parameters.

The domain models delegate Ability operations to `WP_Ability::check_permissions()` and `WP_Ability::execute()`. Direct callable components and prompt builders use their configured execution and permission strategies instead. See [McpTool](../../includes/Domain/Tools/McpTool.php), [McpResource](../../includes/Domain/Resources/McpResource.php), and [McpPrompt](../../includes/Domain/Prompts/McpPrompt.php).

## Components and revision projections

The domain models retain revision-neutral protocol data alongside their Ability or callable execution strategy. Their shared [McpComponentInterface](../../includes/Domain/Contracts/McpComponentInterface.php) is internal. Registration accepts Ability names or concrete `McpTool`, `McpResource`, and `McpPrompt` instances; prompts also support the existing [prompt-builder interface](../../includes/Domain/Prompts/Contracts/McpPromptBuilderInterface.php). Arbitrary implementations of `McpComponentInterface` are not a registration extension point.

[RevisionProjectionTrait](../../includes/Domain/Utils/RevisionProjectionTrait.php) caches successful immutable records and projection failures by schema revision. During registration, the registry checks each supported revision:

- A failed projection makes the component unavailable for that revision's listing and invocation.
- Projection failures are logged with the revision and reason.
- A projection failure prevents registration only if no supported revision remains available.
- Other registration checks still apply, including valid registration inputs, Ability lookup, conversion, and unique component identifiers.

Protocol-facing server getters require a selected `Schema`; component getters expose the underlying domain model. [McpCommand](../../includes/Cli/McpCommand.php) reports registration counts by default; `list --protocol=<revision>` reports counts available under the selected schema revision.

For 2026 Tool projection, `McpTool` omits the removed `execution` field and validates `x-mcp-header` annotations. Although those annotations describe HTTP headers, their validation is part of the shared revision projection: an invalid annotation also makes the tool unavailable over 2026 STDIO.

## Protocol lifecycle

[McpVersionNegotiator](../../includes/Core/McpVersionNegotiator.php) defines the supported schema revisions and legacy identifiers. The current exact schemas are `2025-11-25` and `2026-07-28`.

| Path      | Context and lifecycle                                                                                                                                                                                                                                                                      |
| --------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| 2025 HTTP | Successful initialization creates a WordPress-user-bound `Mcp-Session-Id`. Subsequent legacy POST requests require that session and a matching negotiated-version header, except that `2024-11-05` sessions may omit the version header. Session creation failure returns an error.        |
| 2026 HTTP | Requests carry protocol version and client capabilities in `params._meta`. The Adapter validates applicable HTTP headers against the request body and does not use a server-side MCP session. `server/discover` replaces the initialization flow; `initialize` and `ping` are unavailable. |
| STDIO     | The bridge retains successful 2025 initialization parameters. Each 2026 request supplies its own context, so the same bridge can alternate legacy and modern requests without replacing its stored legacy context.                                                                         |

The built-in HTTP implementation processes POST requests, supports legacy session termination through DELETE, and returns 405 for GET. It does not implement SSE. DELETE follows its own session-validation path rather than the POST path's negotiated-version-header comparison. Receipt of `notifications/initialized` does not create a separate readiness state.

An unsupported version proposed in legacy `initialize` parameters receives `2025-11-25` as the counter-proposal. The legacy initialization flow does not negotiate `2026-07-28`.

Legacy identifiers `2025-06-18` and `2024-11-05` are echoed during initialization but use the `2025-11-25` schema. `McpRequestContext::protocol_version()` exposes the negotiated identifier; `revision()` exposes the schema revision. This mapping does not provide a feature-by-feature legacy projection: newer content variants such as audio and resource links can be emitted but are not accepted by the `2024-11-05` content schema. It is not a guarantee that every older client accepts every response.

See the [migration guide](../migration/v0.7.0.md#migrating-to-the-dual-revision-schema-runtime) for changed APIs and revision-specific behavior, and [Custom transports](../guides/custom-transports.md) for integration examples.

## Result mapping, errors, and observability

The orchestrator's 2026 result projection adds `resultType: "complete"` to completed results and `io.modelcontextprotocol/serverInfo` metadata. This includes tool results containing `isError: true`: a completed protocol result does not necessarily mean the underlying operation succeeded.

Direct callable tools can return `input_required` under `2026-07-28`. The callback receives the current request's answers and opaque state separately from ordinary arguments. The Adapter validates protocol structure and client capabilities; the tool author owns state protection, answer validation, and workflow decisions. Existing Ability execution and ordinary result shapes remain unchanged. See [MRTR tools](../guides/mrtr.md).

For discovery, list, and resource-read results, the Adapter selects `ttlMs: 0` and `cacheScope: "private"`. The schema requires these fields on cacheable results, but those particular values are Adapter choices. Server information is recommended metadata rather than a schema-required field. See [result projection](../../includes/Transport/Infrastructure/McpWireOrchestrator.php) and the [2026 schema](https://github.com/modelcontextprotocol/modelcontextprotocol/blob/main/schema/2026-07-28/schema.json).

Tool permission and execution failures are represented as tool results with `isError: true`. Resource and prompt permission/execution failures use JSON-RPC error responses. [McpErrorFactory](../../includes/Infrastructure/ErrorHandling/McpErrorFactory.php) constructs logical protocol error arrays; [McpErrorHandlerInterface](../../includes/Infrastructure/ErrorHandling/Contracts/McpErrorHandlerInterface.php) is the separate logging contract. See [Error handling](../guides/error-handling.md) for the mappings and custom handlers.

[RequestRouter](../../includes/Transport/Infrastructure/RequestRouter.php) emits one `mcp.request` event for each routed request. When called through `McpWireOrchestrator`, its status and duration include final response projection. Projection failures are errors with `failure_reason: invalid_handler_result`; the configured error handler also receives the schema diagnostic and available JSON pointer. Direct router calls still report the handler outcome. These events do not cover every rejection before dispatch. Component registration events use `mcp.component.registration` and are disabled unless enabled through `mcp_adapter_observability_record_component_registration`. Events are sent through [McpObservabilityHandlerInterface](../../includes/Infrastructure/Observability/Contracts/McpObservabilityHandlerInterface.php); its implementations decide how to log or aggregate them. See [Observability](../guides/observability.md).

## Extension points

| Integration                     | Entry point                                                                                                                                                                                                         |
| ------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Custom server                   | `mcp_adapter_init` and `McpAdapter::create_server()`                                                                                                                                                                |
| Custom transport                | [McpTransportInterface](../../includes/Transport/Contracts/McpTransportInterface.php); REST transports also implement [McpRestTransportInterface](../../includes/Transport/Contracts/McpRestTransportInterface.php) |
| Error logging                   | `McpErrorHandlerInterface`                                                                                                                                                                                          |
| Request and registration events | `McpObservabilityHandlerInterface`                                                                                                                                                                                  |
| Prompt builder                  | `McpPromptBuilderInterface`                                                                                                                                                                                         |
| Execution customization         | Component pre-execution and result filters                                                                                                                                                                          |

Custom HTTP integrations can delegate processing to `HttpRequestHandler`; other transports can use `McpWireOrchestrator`. Calling the router with unvalidated method/parameter arrays bypasses the required boundary and does not match its current API. Transport authentication and delivery remain the custom transport's responsibility. See [Custom transports](../guides/custom-transports.md) and [Transport permissions](../guides/transport-permissions.md).

Tool, resource, and prompt list filters keep the component list as the first argument and the server as the second, and add the selected `Schema` as the third argument. The list now contains generated records rather than the removed DTO classes. A non-array filter result falls back to the original list; array contents are checked during final schema projection. Direct consumers must follow the [migration guide](../migration/v0.7.0.md#migrating-to-the-dual-revision-schema-runtime).

For development commands and verification, see [CONTRIBUTING.md](../../CONTRIBUTING.md) and the [Testing guide](../guides/testing.md).
