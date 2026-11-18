# Ability input preparation and pre-execution filters

Ability-backed tools and prompts now prepare and validate input before calling the ability's permission callback. The adapter uses `WP_Ability::prepare_input()` when available; on older Core versions it calls `normalize_input()` and `validate_input()` before `check_permissions()`.

The existing adapter filter order is unchanged:

1. Prepare and validate the ability input, then check permissions.
2. Run `mcp_adapter_pre_tool_call` or `mcp_adapter_pre_prompt_get`.
3. Execute the ability. Core prepares and validates the filtered input and checks permissions again before calling the execution callback.

## Compatibility change from adapter 0.7.1

Previously, a permission callback that accepted raw input could let a request reach the adapter's pre-execution filter before input validation. For example, a tool or prompt with a required string `id` could accept `arguments: {}` if its permission callback returned `true` and its late filter supplied `id`.

That request is now rejected before the late filter runs. Required input cannot be supplied or repaired only in these late filters. This also affects integrations that relied on a late filter to repair an invalid value.

The filters still run for valid, authorized requests and can return `WP_Error` to stop execution. They do not run after failed input preparation or denied permission checks. If a late filter changes an argument, Core validates and authorizes that changed input again.

## Supplying missing input on WordPress 7.1 and later

Move ability-level enrichment needed for validation or permissions to `wp_ability_normalize_input`, which runs before input validation. Scope the callback to the ability's registration name, not its MCP tool or prompt name.

For an object input schema with a required string `id`, this example supplies a server-side default only when the client omitted that property:

```php
add_filter(
    'wp_ability_normalize_input',
    static function ( $input, string $ability_name ) {
        if ( 'my-plugin/my-ability' !== $ability_name || ! is_array( $input ) ) {
            return $input;
        }

        if ( ! array_key_exists( 'id', $input ) ) {
            $input['id'] = 'server-id'; // Replace with your ability's default.
        }

        return $input;
    },
    10,
    2
);
```

An existing `id`, including an invalid value or explicit `null`, is left for validation rather than silently replaced. The resulting input must still satisfy the ability's schema and permission callback. Supplying a default does not authorize access to that ID.

This is a Core hook: it also affects calls to the same ability outside MCP. Keep normalization deterministic and free of side effects. It runs during the adapter's permission precheck and again during Core execution; do not use it to perform the action or assume it runs only once per request. The adapter's late filters continue to receive their original arguments, not the prepared input from the precheck.

## WordPress 6.9 and 7.0

The `wp_ability_normalize_input` hook requires WordPress 7.1 or later. Adding that filter does not provide this migration on 6.9 or 7.0.

For those versions, clients must supply input that is valid before the late filter runs, or the ability must provide suitable defaults during its own input normalization. If the integration depends on the Core normalization hook, require WordPress 7.1 or later. Keep schema validation and permission checks in place.
