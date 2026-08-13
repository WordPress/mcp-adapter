# Protocol versions

The MCP Adapter supports exactly two Model Context Protocol revisions. They use different lifecycles and cannot be treated as a feature flag on one wire format.

| Behavior | MCP 2025-11-25 | MCP 2026-07-28 |
|----------|----------------|----------------|
| Lifecycle | `initialize`, then session-bound requests | Independent stateless requests |
| Version selection | Negotiated during `initialize` | Declared in every request's `_meta` |
| Discovery | Initialize result | `server/discover` |
| HTTP session ID | Required after initialize | Not used |
| `ping` | Supported | Method not found |
| Multi-round continuation | Not supported | Supported for call/read/get operations |

The Adapter validates each request and response with the descriptor-backed `php-mcp-schema` catalog for its exact revision. Unsupported modern revisions return JSON-RPC error `-32022`.

## MCP 2025-11-25

Legacy clients start with `initialize`:

```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "method": "initialize",
  "params": {
    "protocolVersion": "2025-11-25",
    "capabilities": {},
    "clientInfo": {
      "name": "example-client",
      "version": "1.0.0"
    }
  }
}
```

The response negotiates `2025-11-25`. If the client requests another revision through this legacy lifecycle, the Adapter falls back to `2025-11-25`; `initialize` never selects the stateless 2026 revision. After a successful response, send the `notifications/initialized` notification before ordinary operations.

For HTTP, the initialize response includes `Mcp-Session-Id`. Every later JSON-RPC request in the session must send both:

- `Mcp-Session-Id: <value returned by initialize>`
- `MCP-Protocol-Version: 2025-11-25`

The protocol header must match the version retained in the session. A client can terminate the session with an authenticated `DELETE` carrying its `Mcp-Session-Id`.

For STDIO, send `initialize` before other legacy requests in the same `wp mcp-adapter serve` process. The bridge retains the negotiated legacy version for that process.

## MCP 2026-07-28

Modern clients do not initialize or create a session. Start with `server/discover`:

```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "method": "server/discover",
  "params": {
    "_meta": {
      "io.modelcontextprotocol/protocolVersion": "2026-07-28",
      "io.modelcontextprotocol/clientInfo": {
        "name": "example-client",
        "version": "1.0.0"
      },
      "io.modelcontextprotocol/clientCapabilities": {}
    }
  }
}
```

The discovery result advertises `2026-07-28` and `2025-11-25`, the server's implemented capabilities, server information, and private zero-duration cache fields.

Every modern request must include these fields in `params._meta`:

- `io.modelcontextprotocol/protocolVersion`, set to `2026-07-28`; and
- `io.modelcontextprotocol/clientCapabilities`, including any capability used by embedded input requests.

The optional `io.modelcontextprotocol/clientInfo` field identifies the client when supplied, as in the discovery example above. Over HTTP, send `MCP-Protocol-Version: 2026-07-28`; its value must match the body metadata. Do not send `Mcp-Session-Id` for modern requests.

For example, a stateless tools-list request is:

```json
{
  "jsonrpc": "2.0",
  "id": 2,
  "method": "tools/list",
  "params": {
    "_meta": {
      "io.modelcontextprotocol/protocolVersion": "2026-07-28",
      "io.modelcontextprotocol/clientCapabilities": {}
    }
  }
}
```

Modern results include `resultType` and server information. `server/discover`, `tools/list`, `resources/list`, `resources/templates/list`, `resources/read`, and `prompts/list` also include their exact cache fields. MCP 2026-07-28 has no `ping` method.

## Multi-round continuation

MCP 2026-07-28 permits `tools/call`, `resources/read`, and `prompts/get` to return `resultType: "input_required"`. The client answers the embedded requests, then retries the original operation under a new JSON-RPC ID with `inputResponses` and any returned `requestState`.

Direct component callbacks can opt in by accepting `McpContinuationContext` as a second argument and returning `McpExecutionResult`. Existing one-argument callbacks continue to work. Ability-backed execution and prompt-builder callbacks keep their existing signatures.

This tool asks the client for confirmation on the first round and completes on the next round:

```php
use WP\MCP\Domain\Continuation\McpContinuationContext;
use WP\MCP\Domain\Continuation\McpExecutionResult;
use WP\MCP\Domain\Tools\McpTool;

$tool = McpTool::fromArray(
    array(
        'name'        => 'confirm-operation',
        'inputSchema' => array(
            'type'       => 'object',
            'properties' => array(),
        ),
        'permission'  => static function(): bool {
            return current_user_can( 'edit_posts' );
        },
        'handler'     => static function( array $arguments, ?McpContinuationContext $continuation ): McpExecutionResult {
            if ( null === $continuation || ! $continuation->is_resumed() ) {
                return McpExecutionResult::input_required(
                    array(
                        'confirm' => array(
                            'method' => 'elicitation/create',
                            'params' => array(
                                'mode'            => 'form',
                                'message'         => 'Confirm this operation.',
                                'requestedSchema' => array(
                                    'type'       => 'object',
                                    'properties' => array(
                                        'confirmed' => array( 'type' => 'boolean' ),
                                    ),
                                    'required'   => array( 'confirmed' ),
                                ),
                            ),
                        ),
                    ),
                    'confirm-operation-v1'
                );
            }

            $responses = $continuation->get_input_responses();
            $response  = $responses['confirm'] ?? array();
            $accepted  = 'accept' === ( $response['action'] ?? null )
                && true === ( $response['content']['confirmed'] ?? false );

            return McpExecutionResult::complete(
                array( 'confirmed' => $accepted )
            );
        },
    )
);
```

The client must advertise `elicitation` in `io.modelcontextprotocol/clientCapabilities` before the callback can return an `elicitation/create` input request. The Adapter rejects an input-required result when the corresponding client capability is absent.

### State and security ownership

The Adapter does not persist continuation state. It passes `requestState` back to the client and returns it to the callback on the next independent request. The callback owner must therefore:

- keep state small and versioned;
- avoid secrets or personal data in plaintext state;
- sign or encrypt state when integrity or confidentiality matters;
- treat `requestState` and `inputResponses` as untrusted client input;
- revalidate authorization, referenced objects, and business preconditions on every round; and
- make retries and duplicate submissions safe.

Use `McpExecutionResult::complete( $value )` only for the final callback value. Do not perform a privileged or destructive action solely because the client returned an accepted elicitation response.

## Custom transports

The built-in HTTP and STDIO transports enforce these lifecycles. A [custom transport](custom-transports.md) should pass the exact revision as the optional sixth argument to `RequestRouter::route_request()` when known. Existing calls that omit it remain compatible and use legacy selection unless request metadata or `server/discover` identifies the modern revision.
