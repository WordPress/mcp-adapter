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

- Registers whichever of the `site`, `user`, `content`, and `uncategorized` ability categories are missing (WordPress 6.9 already ships the first two; 7.2 adds the others).
- Flags the `post` and `page` post types with `show_in_abilities`, the 7.2 argument `core/content-query` reads to decide which post types it exposes.
- Flags the same initial settings core flags in 7.2 with `show_in_abilities`, and registers the `wp_page_for_privacy_policy` setting for abilities, so `core/settings-get` exposes the same settings as core.
- Maps the 7.2-only `required_capabilities` ability argument onto a `permission_callback` with identical semantics (every capability must pass `current_user_can()`, then any existing permission callback runs). The mapping applies to `core/*` abilities only.

Everything is **feature-detected** — `class_exists()`, `wp_has_ability()`, `wp_has_ability_category()`, `property_exists()` — never `version_compare()`. Each shim disables itself individually the moment the running WordPress (or another plugin, such as a newer Abilities API feature plugin) already provides the piece, and core registrations always win: core registers at priority 10, the polyfill at 20 behind per-ability `wp_has_ability()` guards.

### Translations

The copied files use core's default text domain (translatable strings in core files carry no domain), so on WordPress versions before 7.2 the ability labels and descriptions render in English — the 7.2 strings are not in older translation catalogs. Once a site updates to 7.2, core provides the abilities and its translations apply. This is the deliberate price of keeping the files identical to core outside the two mechanical transforms.

### Disabling the polyfill

One filter turns off everything the polyfill does — the ability registrations, the categories, the `show_in_abilities` flags, and the `required_capabilities` mapping:

```php
add_filter( 'mcp_adapter_register_core_abilities', '__return_false' );
```

The filter is evaluated when each shim runs, so a theme or plugin can hook it at any point before `wp_abilities_api_init` fires (which happens lazily, on first registry access after `init`). It never affects abilities registered by WordPress core itself.

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

## Developing future core abilities in this plugin

New core abilities (content updates, user updates, …) are developed and tested in the MCP Adapter first, then moved to WordPress core. The infrastructure is built so that the move is a copy-paste.

### Authoring rules

A new ability goes **into the existing class that owns its domain** whenever the shared helpers fit — core's classes say so themselves ("Future write-oriented user abilities can reuse them as well"). For example, `core/user-update` is a new guarded registration plus private helpers added to `WP_MCP_Abilities_Users`. A new file under `core/abilities/` (core naming: `class-wp-abilities-{name}.php`) is only for a genuinely new domain.

Write the code exactly as it will land in core, modulo the two mechanical transforms from `core/README.md`:

- A global `final` class named `WP_MCP_Abilities_*`. The `WP_MCP_` prefix replaces core's `WP_` on exactly two lines (header docblock and class declaration) and is what lets the copy load on WordPress versions that ship the core class; it is renamed back when the code moves upstream.
- Each registration call in `register()` wrapped in its own `wp_has_ability()` guard — one guard around exactly one `$this->register_…();` call. The guard is what makes partial states safe: a WordPress that ships some of the class's abilities only gains the ones it is missing.
- In a file whose class core already ships, every change relative to upstream must be a **pure addition** (new registration calls, helpers, constants) so re-syncing stays conflict-free.
- `declare( strict_types=1 );` and core's file-level docblock, with `@since` set to the WordPress version the ability targets.
- Translatable strings via `__()` **without** a text domain.
- Only `register()` public; execute and permission callbacks stay private or are closures.
- PHP compatible with the plugin's floor (7.4). Core polyfills such as `array_any()`/`array_all()` are safe — they ship with WordPress 6.9+.
- Use `required_capabilities` freely: on WordPress versions without it, the polyfill maps it to an equivalent `permission_callback` automatically.

Never change core-owned lines in `core/abilities/` — change them upstream in [wordpress-develop](https://github.com/WordPress/wordpress-develop) first, then re-copy (see `core/README.md` for the provenance rules).

### Wiring a staged ability

1. In `CoreAbilitiesPolyfill::ABILITY_CLASSES`, add the ability's name to its class's `abilities` **and** `staged` lists (for a new class, add a whole entry: file, `core_class`, both lists). `staged` is what keeps the polyfill hooked on WordPress versions that ship the class without the new ability; `abilities` is the complete list the copy registers, which the registrar checks to decide whether the copy needs loading at all.
2. If the ability needs a new category, add it to `CoreAbilitiesPolyfill::CATEGORIES` with core's English label.
3. If it reads new `show_in_abilities` surface (post types, settings), extend `POST_TYPES` / `SETTINGS_FLAG_MAP` the same way.
4. Add unit coverage in `tests/phpunit/Unit/Compat/CoreAbilitiesPolyfillTest.php` and extend the fresh-process lifecycle test (`tests/phpunit/Integration/CoreAbilitiesPolyfillBootstrapTest.php`) if the ability has registration-time behavior. The CI matrix runs the suite against WordPress versions with and without core's classes, so assertions must branch on feature detection, never on version numbers.

### Moving an ability to core

The core patch is:

1. Copy the file (or, for an ability staged into an existing class, the added lines) from `core/abilities/` to `src/wp-includes/abilities/`, reversing the two transforms: rename the class prefix `WP_MCP_` back to `WP_`, and unwrap the `wp_has_ability()` guards in `register()` so each registration call runs unconditionally.
2. For a new class, add core's loading lines — a `require` alongside the other ability classes in `wp-settings.php` and a `( new WP_Abilities_X() )->register();` call in `wp-includes/abilities.php` — matching how the existing three abilities are loaded.

Nothing changes in the plugin when core merges it: the `wp_has_ability()` guards make the plugin's registrations inert on the new WordPress version with no plugin release required. The next release moves the ability out of the `staged` list (so the load-time gate can short-circuit again on that WordPress version), and once the plugin's minimum WordPress version rises past the release that shipped the ability, its guard, copy, or `ABILITY_CLASSES` entry is deleted.

### Keeping copies in sync

`core/README.md` records the wordpress-develop commit the current copies were taken from. When re-syncing, copy each file from `src/wp-includes/` at a known commit (for example with `git show <commit>:src/wp-includes/abilities/<file>`), re-apply the two mechanical transforms, re-apply any staged additions, and update the recorded commit. To verify a copy, normalize it first — rename the class prefix back and unwrap the guards — and diff the result against upstream: it must match exactly, except for staged additions.
