# Tools that request additional client input

Under MCP `2026-07-28`, a callable tool can return `input_required` instead of a
completed result. The client gathers input and retries the original tool. Each
attempt runs the callback from the beginning; no PHP invocation stays suspended.

The Adapter supports this for direct `tools/call` handlers. It does
not implement MRTR for `prompts/get` or `resources/read`.

## Return an explicit result

Create a direct tool with `McpTool::fromArray()`. The Adapter passes ordinary
arguments and a `McpToolCallContext` to its handler. No feature flag is needed.
Register the tool through the server's existing `tools` argument.

User-defined callbacks that declare only the arguments parameter can ignore the
extra context. Callbacks using a second parameter or variadic arguments now receive
context there. Wrap PHP built-in functions in a user-defined callback rather than
registering them directly if their signatures do not accept this contract.
Calling `McpTool::execute()` outside the Adapter without a context supplies `null`
as the second argument.

Return `new McpInputRequired($input_requests, $request_state)` when more input is
needed. Input requests are a map of MCP requests keyed by author-chosen identifiers.
The optional state is an opaque string owned by the author. The Adapter sends it
unchanged. At least one input request or non-null state must be supplied.

Only this explicit object requests another round. Arrays, including arrays with
keys named `resultType`, `inputRequests`, or `requestState`, remain ordinary tool
data. Normal values and `WP_Error` follow the existing result handling.

| Context method | Meaning |
| --- | --- |
| `revision()` | The selected MCP revision. |
| `client_capabilities()` | Capabilities declared for this request. |
| `input_responses()` | A defensive copy of this request's schema-valid response map. No history is accumulated. |
| `request_state()` | The untrusted, opaque string supplied by the client, or `null`. |
| `is_continuation()` | The client supplied continuation fields. This does not prove a previous interaction. |

Schema-valid responses have valid protocol structure. The Adapter does not check
that their values answer the questions you issued, authenticate state, or prove
that a human approved anything.

## Confirm post creation before calling an Ability

Assume your plugin already registers `my-plugin/create-post`. It accepts
`title` and `content`, creates a draft, and returns its normal result. Substitute
your existing Ability name and input fields; its definition is not repeated here.

The tool below asks **“Allow post creation?”** and only calls the Ability after a
validated affirmative answer. Register the returned tool through your server's
`tools` argument. The Adapter validates returned protocol values and capabilities.
Because this example saves state before returning, it also checks support before
that write. Unsupported clients receive an error without creating an approval row.

The factory accepts a callback that returns the current request's **verified
client identifier**, supplied by your authentication integration. Do not derive
it from tool arguments or `clientInfo.name`. This keeps the example independent
of whether your transport uses OAuth, application passwords, or another scheme.

```php
use WP\MCP\Domain\Tools\McpInputRequired;
use WP\MCP\Domain\Tools\McpTool;
use WP\MCP\Domain\Tools\McpToolCallContext;
use WP\McpSchema\Schemas;

$make_create_post_tool = static function ( callable $authenticated_client_id ) {
    return McpTool::fromArray(
        array(
            'name'        => 'create-post-with-confirmation',
            'description' => 'Create a draft post after confirmation of its title and content.',
            'inputSchema' => array(
                'type'                 => 'object',
                'properties'           => array(
                    'title'   => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 200 ),
                    'content' => array( 'type' => 'string', 'maxLength' => 10000 ),
                ),
                'required'             => array( 'title', 'content' ),
                'additionalProperties' => false,
            ),
            'permission' => static function ( array $args ) {
                $ability = wp_get_ability( 'my-plugin/create-post' );
                if ( null === $ability ) {
                    return new WP_Error( 'missing_ability', 'The post creation Ability is unavailable.' );
                }
                // Ability permission callbacks expect validated domain input.
                $valid = $ability->validate_input( $args );
                if ( is_wp_error( $valid ) ) {
                    return $valid;
                }
                return $ability->check_permissions( $args );
            },
            'handler' => static function ( array $args, McpToolCallContext $context ) use ( $authenticated_client_id ) {
                // Direct tools validate their own arguments before using them.
                if ( ! isset( $args['title'], $args['content'] )
                    || ! is_string( $args['title'] ) || ! is_string( $args['content'] )
                    || '' === trim( $args['title'] )
                    || mb_strlen( $args['title'], 'UTF-8' ) > 200 || mb_strlen( $args['content'], 'UTF-8' ) > 10000
                    || array_diff( array_keys( $args ), array( 'title', 'content' ) ) ) {
                    return new WP_Error( 'invalid_post_input', 'Supply a title and content within the size limits.' );
                }

                $ability = wp_get_ability( 'my-plugin/create-post' );
                if ( null === $ability ) {
                    return new WP_Error( 'missing_ability', 'The post creation Ability is unavailable.' );
                }
                $client_id = $authenticated_client_id();
                if ( ! is_string( $client_id ) || '' === $client_id || 0 === get_current_user_id() ) {
                    return new WP_Error( 'missing_identity', 'An authenticated client and user are required.' );
                }

                // Fixed key order makes the digest independent of argument order.
                $input = array( 'title' => $args['title'], 'content' => $args['content'] );
                $binding = array(
                    'user'   => get_current_user_id(),
                    'blog'   => get_current_blog_id(),
                    'client' => $client_id,
                    'tool'   => 'create-post-with-confirmation',
                    'input'  => hash( 'sha256', wp_json_encode( $input, JSON_THROW_ON_ERROR ) ),
                );
                $state = $context->request_state();
                $answers = $context->input_responses();

                if ( null === $state ) {
                    if ( $context->is_continuation() ) {
                        return new WP_Error( 'missing_approval', 'Restart the request to obtain an approval ID.' );
                    }
                    // Check before saving state: the Adapter checks returned requests later.
                    $elicitation = $context->client_capabilities()->elicitation ?? null;
                    if ( Schemas::V2026_07_28 !== $context->revision()
                        || ! $elicitation instanceof \stdClass
                        || ( ! isset( $elicitation->form ) && array() !== get_object_vars( $elicitation ) ) ) {
                        return new WP_Error( 'confirmation_unavailable', 'Post creation requires MCP 2026-07-28 with form elicitation.' );
                    }
                    $state = bin2hex( random_bytes( 16 ) );
                    $key = 'my_plugin_post_approval_' . $state;
                    $pending = array( 'binding' => $binding, 'expires' => time() + 600 );
                    if ( ! add_option( $key, $pending, '', false ) ) {
                        return new WP_Error( 'approval_storage_failed', 'Could not save the pending approval.' );
                    }
                } else {
                    if ( 1 !== preg_match( '/\A[a-f0-9]{32}\z/', $state ) ) {
                        return new WP_Error( 'invalid_approval', 'The approval ID is invalid.' );
                    }
                    $key = 'my_plugin_post_approval_' . $state;
                    $pending = get_option( $key );
                    if ( ! is_array( $pending ) ) {
                        return new WP_Error( 'approval_unavailable', 'This approval is unknown, expired, or already used. Check whether the draft exists before restarting.' );
                    }
                    if ( ( $pending['binding'] ?? null ) !== $binding ) {
                        return new WP_Error( 'invalid_approval', 'The approval does not match this caller and post.' );
                    }
                    if ( ( $pending['expires'] ?? 0 ) <= time() ) {
                        delete_option( $key );
                        return new WP_Error( 'expired_approval', 'The approval expired. Restart the request.' );
                    }
                }

                $answer = $answers->allow_post_creation ?? null;
                if ( null === $answer ) {
                    return new McpInputRequired(
                        array(
                            'allow_post_creation' => array(
                                'method' => 'elicitation/create',
                                'params' => array(
                                    'message' => "Allow post creation?\n\nDraft title: " . $input['title']
                                        . "\n\nDraft content:\n" . $input['content'],
                                    'requestedSchema' => array(
                                        'type' => 'object',
                                        'properties' => array(
                                            'allow' => array( 'type' => 'boolean', 'title' => 'Allow post creation' ),
                                        ),
                                        'required' => array( 'allow' ),
                                    ),
                                ),
                            ),
                        ),
                        $state
                    );
                }
                if ( ! isset( $answer->action ) ) {
                    return new WP_Error( 'invalid_answer', 'Expected an elicitation answer.' );
                }
                if ( in_array( $answer->action, array( 'decline', 'cancel' ), true ) ) {
                    delete_option( $key );
                    return array( 'outcome' => $answer->action );
                }
                $content = $answer->content ?? null;
                if ( 'accept' !== $answer->action || ! $content instanceof \stdClass
                    || ! property_exists( $content, 'allow' ) || ! is_bool( $content->allow ) ) {
                    return new WP_Error( 'invalid_answer', 'The answer must contain an allow boolean.' );
                }
                if ( false === $content->allow ) {
                    delete_option( $key );
                    return array( 'outcome' => 'declined' );
                }

                // Only the request that removes this row may execute the Ability.
                if ( ! delete_option( $key ) ) {
                    return new WP_Error( 'approval_used', 'This approval is no longer available. Check whether the draft exists before restarting.' );
                }
                return $ability->execute( $input );
            },
        )
    );
};
```

Call `$make_create_post_tool($authenticated_client_id)` with your integration's
identity callback. The factory returns an `McpTool` or `WP_Error`; check the result
before registering it. The example deliberately does not redefine the Ability or
implement an authentication system.

## What state and validation do here

`requestState` contains only a random approval ID. The associated record stays in
WordPress and contains a digest of the exact proposed title/content, caller
identity, tool name, and expiry. Changing the content, switching users or clients,
or submitting an unknown or expired ID fails before the Ability runs. The client
cannot change the stored record by editing the ID.

The callback validates three different things: original post arguments, the
approval's identity and operation binding, and the answer's exact boolean value.
The strings `"true"` and `"false"` are not accepted as booleans. `accept` alone is
insufficient: `allow` must be `true`. A missing answer repeats the same question
with the same state; decline, cancellation, and `allow: false` do not create a post.

The tool's permission callback validates input and delegates to the Ability's own
permission callback on every attempt, before showing a question or consuming an
approval. A denied retry leaves the pending approval available until expiry.
The final `$ability->execute()`
still performs the Ability's own input validation, permission checks, execution,
and output validation. This is confirmation reported by the MCP client, not
independent proof that a human approved the request. Use a separately authenticated
approval page if your application requires that stronger guarantee.

The example consumes the stored approval before execution and checks deletion
success so concurrent retries cannot both use the same approval. If execution
fails after consumption, start a new approval; this is not transactional or an
exactly-once delivery system. It prevents reuse of this approval, not submission
of a new create request. Use your existing operation-idempotency system if needed.

For brevity, this uses non-autoloaded options. Add cleanup for abandoned/expired
records in your plugin, or use your existing workflow store with an atomic
consume operation. Options do not expire automatically. The ten-minute lifetime
is an example chosen by the tool author, not an Adapter default. This store must
be private to your plugin and trusted server code.

## Use the same pattern for post updates

For an existing `my-plugin/update-post` Ability, adapt the wrapper as follows:

- Add a required integer `post_id` to the tool schema and validate it as a positive
  integer. Include it in the fixed-order `$input` array and therefore its digest.
- Name the tool `update-post-with-confirmation` and use that name in its state
  binding. Resolve the update Ability instead of the create Ability.
- Require `current_user_can( 'edit_post', $post_id )` for that post before showing
  its content on every attempt. Pass the final inputs through the Ability executor.
- Ask **“Allow post update?”**, showing the post ID and proposed changes. Use
  `allow_post_update` as the input-request/response key and the label
  **“Allow post update”** for the same strict boolean field.
- If approval depends on the previous post contents, save a digest of those
  contents in the pending record and compare it with the current post on retry.
  Reject a stale approval instead of overwriting intervening edits. If concurrent
  updates must be excluded, enforce that condition atomically in the update
  operation; a preliminary comparison alone is not a lock.

`McpTool::fromAbility()` remains available for ordinary non-interactive exposure.
These confirmation wrappers use `McpTool::fromArray()` so state and answers stay
outside the Ability's domain inputs.

## State and validation belong to the author

Use your existing workflow store or a protected token when correlation matters.
The Adapter does not prescribe storage, token encoding, expiry, replay handling,
or a signing key. It does not parse or verify `requestState`.

Treat both state and answers as attacker-controlled input. Before using state to
influence authorization, resource access, or business logic, verify its integrity
and its association with the authenticated principal and intended operation.
Bind elicitation to the authenticated client and user, not unverified client
metadata or a claimed user ID in an answer. A database identifier must resolve
only to records the authenticated caller may use. A client-carried state payload
needs integrity protection such as HMAC or AEAD; signing alone does not hide it.
Choose an appropriate expiry and enforce one-time consumption server-side when
an operation must execute at most once.

Validate answer types and values against the issued question and your domain
rules. Protocol schema validation does not enforce a form's `requestedSchema`
against its submitted content. Use your existing validation code or the Ability's
input validation when those express the same constraints. Do not assume WordPress
REST validation enforces strict JSON types without coercion.

An `accept` answer is not independently verified human approval. In URL mode it
indicates consent to open the URL, not completion of the external operation.
Verify completion through your own server-side state. Never collect secrets through
form elicitation; use the protocol's URL flow and verify identity there.

See the [MRTR requirements](https://modelcontextprotocol.io/specification/2026-07-28/basic/patterns/mrtr)
and [elicitation requirements](https://modelcontextprotocol.io/specification/2026-07-28/client/elicitation)
for the server obligations an author must implement.

## What the Adapter enforces

The Adapter validates outgoing and incoming protocol structures through the exact
schema. It checks the client's elicitation capability and mode before sending an
elicitation request. Unsupported capabilities return `-32021` with HTTP 400.
Sampling and roots input requests are not supported and return an internal error.
A form's `requestedSchema` must declare `type: object` and `properties`; an
input request that fails schema validation is logged and returned as `-32603`,
never sent to the client. These checks happen after
the callback returns; they cannot undo its side effects. A callback that persists
state before returning an input request must check the revision and necessary
capabilities before that write, as the example does. Tool permission callbacks run
on every attempt.

Continuation fields on Ability-backed tools, other supported methods without an
MRTR implementation, or the 2025 revision are rejected. A direct tool may complete
normally under `2025-11-25`, but returning `McpInputRequired` produces an explicit
internal error (`-32603`). The Adapter never silently downgrades it to completed output.

The `mcp_adapter_tool_call_result` filter receives the `McpInputRequired` object
unchanged. Filters that reshape array results must pass it through.
