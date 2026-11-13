# Core Abilities

WordPress 7.2 ships three core abilities built on the Abilities API:

| Ability | Core class | Plugin copy | Category | What it does |
|---|---|---|---|---|
| `core/content-query` | `WP_Abilities_Content` | `WP_MCP_Abilities_Content` | `content` | Queries posts and pages (post types flagged with `show_in_abilities`) |
| `core/users-query` | `WP_Abilities_Users` | `WP_MCP_Abilities_Users` | `user` | Queries site users |
| `core/settings-get` | `WP_Abilities_Settings` | `WP_MCP_Abilities_Settings` | `site` | Reads the site settings flagged with `show_in_abilities` (requires `manage_options`) |

The MCP Adapter makes these abilities available on **every supported WordPress version** (6.9+), and its `core/` directory doubles as the staging ground where future core abilities are developed before landing in WordPress itself. Each plugin copy is core's file with the class renamed to the `WP_MCP_` prefix and a `wp_has_ability()` guard around each registration — the two mechanical transforms `core/README.md` defines — so the copy can load and register per-ability on any WordPress version, alongside core's own class.

## How the polyfill behaves per WordPress version

**On WordPress 7.2 and newer** the plugin does nothing, as long as no staged ability goes beyond what core ships (true today). At load time `WP\MCP\Compat\CoreAbilitiesPolyfill` sees that every mirrored core class exists and nothing is staged, and never hooks the polyfill. The abilities you see come from core, byte for byte. Once an ability is staged beyond a shipped class, the polyfill hooks everywhere, loads the plugin copies whose abilities are not all registered, and the guards inside `register()` fill in only what is missing — core's registrations always stay untouched.

**On WordPress 6.9–7.1** the plugin registers the same abilities from the copies stored in `core/abilities/`. Because those classes depend on registration surface that only exists in 7.2, `WP\MCP\Compat\CoreAbilitiesPolyfill` also bridges the gaps:

- Registers whichever of the `site`, `user`, and `content` ability categories are missing (WordPress 6.9 already ships the first two; 7.2 adds `content`).
- Flags the `post` and `page` post types with `show_in_abilities`, the 7.2 argument `core/content-query` reads to decide which post types it exposes.
- Flags the same initial settings core flags in 7.2 with `show_in_abilities`, and registers them before `core/settings-get` reads them, as core's `_wp_register_initial_settings_for_abilities()` does in 7.2.

Everything is **feature-detected** — `class_exists()`, `function_exists()`, `wp_has_ability()`, `wp_has_ability_category()` — never `version_compare()`. Each shim disables itself individually the moment the running WordPress (or another plugin, such as a newer Abilities API feature plugin) already provides the piece, and core registrations always win: core registers at priority 10, the polyfill at 20 behind per-ability `wp_has_ability()` guards.

## Permissions

The abilities check the same permissions on every WordPress version:

- `core/settings-get` requires the `manage_options` capability. Core declares it with the `required_capabilities` ability argument, which WordPress before 7.2 does not support, so the plugin's copy checks the same capability in a `permission_callback`.
- `core/users-query` requires an authenticated user. Users who cannot list users only see public authors, and filtering by username or role requires permission to list users.
- `core/content-query` requires an authenticated user, and returns only the posts that user can read. Statuses that are not publicly viewable, other than private, require edit access.

## Differences from WordPress 7.2

- **No `page_for_privacy_policy` setting.** On WordPress 6.9–7.1, `core/settings-get` does not expose the privacy policy page. WordPress 7.2 is the first version to register the `wp_page_for_privacy_policy` setting, together with REST API restrictions on who can change it ([#66045](https://core.trac.wordpress.org/ticket/66045)) that older versions lack.
- **Untranslated labels.** The copied files use core's default text domain (translatable strings in core files carry no domain), so on WordPress versions before 7.2 the ability labels and descriptions render in English — the 7.2 strings are not in older translation catalogs. Once a site updates to 7.2, core provides the abilities and its translations apply. This is the deliberate price of keeping the files in sync with core.

## Disabling the polyfill

One filter turns off everything the polyfill does — the ability registrations, the categories, the `show_in_abilities` flags, and the settings registration:

```php
add_filter( 'mcp_adapter_register_core_abilities', '__return_false' );
```

Add the filter in a plugin's main file or in your theme's `functions.php`, so it is in place before `init` runs: the polyfill flags the `post` and `page` post types when core registers them at the start of `init`, well before the abilities initialize. The filter never affects abilities registered by WordPress core itself.

To remove a single ability instead, use the Abilities API directly:

```php
add_action(
	'wp_abilities_api_init',
	static function () {
		wp_unregister_ability( 'core/users-query' );
	},
	200 // After core (10) and the polyfill (20).
);
```

## Developing future core abilities

New core abilities are developed in this plugin before they move to WordPress core. The authoring rules, how to wire a staged ability, how to move one to core, and how to keep the copies in sync are in the contributor documentation in [`core/README.md`](../../core/README.md).
