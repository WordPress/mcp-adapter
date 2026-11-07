# Confirming destructive Ability calls (experimental)

> **Experimental.** Names, defaults and messages may change while the Adapter is 0.x. Tracked as C4 in [#402](https://github.com/WordPress/mcp-adapter/issues/402).

An Ability call over MCP can be made to wait for a yes from the person before it runs. A call that needs confirmation changes nothing. It answers with what it would do and a short-lived, single-use confirmation. Only a later call that carries that confirmation back, with exactly the same input, runs.

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

| Client | What happens |
| --- | --- |
| MCP `2026-07-28` with form elicitation | The call returns `input_required` with one form question (`mcp_adapter_confirmation`, a required boolean `confirm`). The client asks the person and retries with the answer. The model never sees the confirmation. |
| Every other client | The call returns a tool execution error that says what would change and gives a token. The agent asks in the conversation and, after a yes, sends the same call again with `"_mcp_confirmation_token": "<token>"` at the top level of the arguments. The Adapter removes that argument before the Ability sees it. |

A conversation token shows that the agent was told to ask, not that a person answered. A site that needs more can turn the conversation channel off; clients without form elicitation then get a plain refusal:

```php
add_filter( 'mcp_adapter_tool_call_confirmation_allows_chat', '__return_false' );
```

Form elicitation is reported by the client, so it is not independent proof either. A wp-admin confirmation page (URL elicitation) is the stronger form and is not part of this prototype.

## What a confirmation is bound to

Each confirmation is stored server-side for five minutes (filter `mcp_adapter_tool_call_confirmation_ttl`, 30 to 3600 seconds) and bound to the user, site, server, MCP tool, target Ability and a SHA-256 digest of its input with object keys sorted. A different input, user or tool gets `mcp_adapter_confirmation_mismatch` and the confirmation stays usable for the call it was given for. A token issued for the conversation cannot answer an elicitation question, and the other way round.

A confirmation runs once: only the request that deletes it may run. A retry after that gets `mcp_adapter_confirmation_unknown`, which does not say whether the first call succeeded, so check the site before asking again.

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

## Recording what was confirmed

`mcp_adapter_tool_call_confirmed` fires just before a confirmed call runs, with the Ability name, a digest of the input (never the input itself), the summary the person saw, the channel, the user and the times. Use it to log that the person said yes, and to what.
