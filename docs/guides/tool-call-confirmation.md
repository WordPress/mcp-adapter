# Confirming destructive Ability calls (experimental)

> **Experimental.** Names, defaults and messages may change while the Adapter is 0.x. Tracked as C4 in [#402](https://github.com/WordPress/mcp-adapter/issues/402).

An Ability call over MCP can be made to wait for a yes from the person before it runs. A call that needs confirmation changes nothing. It answers with what it would do and a short-lived, single-use confirmation. Only a later call that carries that confirmation back, with exactly the same input, runs. A retry of that confirmed call gets the first call's result back instead of running again.

This covers Ability-backed tools, including the default server's `mcp-adapter-execute-ability`, where the question is about the Ability it would run. Direct tools built with `McpTool::fromArray()` keep their own flow, see [MRTR Tools](mrtr.md).

## When a call is confirmed

By default, a call needs confirmation when both are true:

- the target Ability is annotated `destructive` and not `readonly`, and
- the current user is an agent user, as reported by `wpai_is_agent_user()` from the AI plugin's Agent Users experiment ([WordPress/ai#1068](https://github.com/WordPress/ai/pull/1068)).

People connecting with their own Application Password keep today's behaviour. Change the default with a filter:

```php
// Ask before every destructive call, whoever makes it.
add_filter(
	'mcp_adapter_tool_call_requires_confirmation',
	static function ( bool $required, WP_Ability $ability ): bool {
		$annotations = $ability->get_meta()['annotations'] ?? array();
		return $required || true === ( $annotations['destructive'] ?? false );
	},
	10,
	2
);
```

The check runs after the tool's permission check and after `mcp_adapter_pre_tool_call`. A call that would be refused is refused before anyone is asked, and a confirmation never lets a user do more than they already may.

## How the person is asked

The Adapter picks the strongest channel the client supports:

| Client | What happens |
| --- | --- |
| MCP `2026-07-28` with URL elicitation | The call returns `input_required` with a URL elicitation for a wp-admin approval page. The approver signs in as themselves, sees the same summary, and presses Approve or Decline. The client then retries and the call runs. This proves that a person answered. |
| MCP `2026-07-28` with form elicitation | The call returns `input_required` with one form question (`mcp_adapter_confirmation`, a required boolean `confirm`). The client asks the person and retries with the answer. The model never sees the confirmation. |
| Every other client | The call returns a tool execution error that says what would change and gives a token. The agent asks in the conversation and, after a yes, sends the same call again with `"_mcp_confirmation_token": "<token>"` at the top level of the arguments. The Adapter removes that argument before the Ability sees it. |

A conversation token shows that the agent was told to ask, not that a person answered. A site that needs more can turn the conversation channel off. Clients without elicitation then get a plain refusal; clients with form or URL elicitation are still asked:

```php
add_filter( 'mcp_adapter_tool_call_confirmation_allows_chat', '__return_false' );
```

Form elicitation is reported by the client, so it is not independent proof. The approval page is.

### The approval page

The link opens `wp-admin/admin-post.php?action=mcp_adapter_confirmation&confirmation=<id>`, so a signed-out person is sent to the login screen first. The approver is:

- the agent user's parent, from `wpai_get_agent_parent()`, when the caller is an agent user;
- the caller, when the caller is a person.

An agent user without a parent gets no link, and the next channel is used. `mcp_adapter_tool_call_confirmation_approver` changes the approver; an agent user can never be one. Nobody else can see the request or answer it, answering needs a nonce for that confirmation, and the link stops working when the confirmation expires. In URL mode the client's `accept` only means the person agreed to open the page: the call runs once the page recorded an approval.

## What a confirmation is bound to

Each confirmation is stored server-side for five minutes (filter `mcp_adapter_tool_call_confirmation_ttl`, 30 to 3600 seconds) and bound to the user, site, server, MCP tool, target Ability and a SHA-256 digest of its input with object keys sorted. A different input, user or tool gets `mcp_adapter_confirmation_mismatch`, and the confirmation stays usable for the call it was given for. A token issued for the conversation cannot answer an elicitation question, and the other way round.

## Retries

A confirmation runs its call once: only the request that deletes it may run. The call's response is kept until the confirmation would have expired, keyed by the confirmation, so that a client that timed out and retried gets the same answer:

- A retry of the same call (same token or request state, same user, tool and input) gets the stored response. The Ability does not run again, and `mcp_adapter_tool_call_confirmed` does not fire again.
- A retry that arrives while the first call is still running waits up to five seconds for its response (filter `mcp_adapter_tool_call_confirmation_replay_wait`, 0 to 30), then says the call is still running. It never runs the call a second time.
- Protocol errors are never replayed, because they carry the first request's id. Results larger than 512 KB are not kept. In both cases a retry is told that the call already ran once and to check the site.

Only the response is stored, never the input.

## One yes for the obvious next step

A confirmation can cover a short list of follow-up calls, for example installing a plugin covering activating that same plugin. Each follow-up names one Ability, its exact input and the sentence the person sees. The question always lists them under "The same yes also covers:", and a covered call runs once, for the same user and site, without a second question. Any other Ability, or the same Ability with a different input, is asked as usual. At most three follow-ups are kept, and they expire with the confirmation.

An Ability can declare them with a callback in its MCP metadata:

```php
'meta' => array(
	'annotations' => array( 'destructive' => true ),
	'mcp'         => array(
		'public'                  => true,
		'confirmation_follow_ups' => static function ( $input ): array {
			return array(
				array(
					'ability' => 'my-plugin/plugin-activate',
					'input'   => array( 'plugin' => $input['plugin'] ),
					'summary' => sprintf( 'Activate the plugin %s', $input['plugin'] ),
				),
			);
		},
	),
),
```

`mcp_adapter_tool_call_confirmation_follow_ups` filters the list for any Ability, including returning an empty array to withdraw them.

## Describing the change

The default question names the Ability and shows its input as JSON. An Ability that can predict its effect should say so:

```php
add_filter(
	'mcp_adapter_tool_call_confirmation_summary',
	static function ( string $summary, WP_Ability $ability, $input ): string {
		if ( 'core/content-delete' !== $ability->get_name() ) {
			return $summary;
		}
		$post = get_post( (int) ( $input['id'] ?? 0 ) );
		return $post ? sprintf( 'Move "%s" to the trash', $post->post_title ) : $summary;
	},
	10,
	3
);
```

Covered follow-ups are appended after this filter, so they are always named.

## Recording what was confirmed

`mcp_adapter_tool_call_confirmed` fires once, just before a confirmed call runs, with the confirmation id, the Ability name, a digest of the input (never the input itself), the summary the person saw, the channel (`url`, `form` or `chat`), the user, the approver for the approval page, the confirmation a follow-up was covered by, and the times. Use it to log that the person said yes, and to what.
