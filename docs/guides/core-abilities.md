# Core Abilities

WordPress 7.2 ships three core abilities built on the Abilities API:

| Ability | Class | Category | What it does |
|---|---|---|---|
| `core/content-query` | `WP_Abilities_Content` | `content` | Queries posts and pages (post types flagged with `show_in_abilities`) |
| `core/users-query` | `WP_Abilities_Users` | `user` | Queries site users |
| `core/settings-get` | `WP_Abilities_Settings` | `site` | Reads the site settings flagged with `show_in_abilities` (requires `manage_options`) |

The MCP Adapter makes these abilities available on **every supported WordPress version** (6.9+), and its `core/` directory doubles as the staging ground where future core abilities are developed before landing in WordPress itself.

## How the polyfill behaves per WordPress version

**On WordPress 7.2 and newer** the plugin does nothing. The core ability classes are loaded by WordPress before any plugin runs, so the plugin detects them with `class_exists()` at load time and never hooks its polyfill. The abilities you see come from core, byte for byte.

**On WordPress 6.9–7.1** the plugin registers the same abilities from verbatim copies of the core class files stored in `core/abilities/`. Because those classes depend on registration surface that only exists in 7.2, `WP\MCP\Compat\CoreAbilitiesPolyfill` also bridges the gaps:

- Registers whichever of the `site`, `user`, `content`, and `uncategorized` ability categories are missing (WordPress 6.9 already ships the first two; 7.2 adds the others).
- Flags the `post` and `page` post types with `show_in_abilities`, the 7.2 argument `core/content-query` reads to decide which post types it exposes.
- Flags the same initial settings core flags in 7.2 with `show_in_abilities`, and registers the `wp_page_for_privacy_policy` setting for abilities, so `core/settings-get` exposes the same settings as core.
- Maps the 7.2-only `required_capabilities` ability argument onto a `permission_callback` with identical semantics (every capability must pass `current_user_can()`, then any existing permission callback runs). The mapping applies to `core/*` abilities only.

Everything is **feature-detected** — `class_exists()`, `wp_has_ability()`, `wp_has_ability_category()`, `property_exists()` — never `version_compare()`. Each shim disables itself individually the moment the running WordPress (or another plugin, such as a newer Abilities API feature plugin) already provides the piece, and core registrations always win: the polyfill registers at a later hook priority behind `wp_has_ability()` guards.

### Translations

The copied files use core's default text domain (translatable strings in core files carry no domain), so on WordPress versions before 7.2 the ability labels and descriptions render in English — the 7.2 strings are not in older translation catalogs. Once a site updates to 7.2, core provides the abilities and its translations apply. This is the deliberate price of keeping the files byte-identical to core.

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
	200 // After core (10) and the polyfill (100).
);
```

## Developing future core abilities in this plugin

New core abilities (content updates, user updates, …) are developed and tested in the MCP Adapter first, then moved to WordPress core. The infrastructure is built so that the move is a copy-paste.

### Authoring rules

Write the ability exactly as it will land in core, in a new file under `core/abilities/` following core's layout and naming (`class-wp-abilities-{name}.php`):

- A global `final` class named `WP_Abilities_*` — no plugin namespace, no plugin prefix. The class name is both the 7.2+ feature detection and the don't-redeclare guard.
- `declare( strict_types=1 );` and core's file-level docblock, with `@since` set to the WordPress version the ability targets.
- Translatable strings via `__()` **without** a text domain.
- One public `register()` method that calls `wp_register_ability()`; execute and permission callbacks stay private or are closures.
- PHP compatible with the plugin's floor (7.4). Core polyfills such as `array_any()`/`array_all()` are safe — they ship with WordPress 6.9+.
- Use `required_capabilities` freely: on WordPress versions without it, the polyfill maps it to an equivalent `permission_callback` automatically.

Never edit a file in `core/abilities/` that already exists in WordPress core — change it upstream in [wordpress-develop](https://github.com/WordPress/wordpress-develop) first, then re-copy it (see `core/README.md` for the provenance rules).

### Wiring a staged ability

1. Add the class to `CoreAbilitiesPolyfill::ABILITY_CLASSES` — the file name and the ability names it registers. The polyfill `require_once`s the file (guarded by `class_exists()`) and calls `register()` (guarded by `wp_has_ability()`), so the entry keeps working unchanged before *and after* core ships the ability.
2. If the ability needs a new category, add it to `CoreAbilitiesPolyfill::CATEGORIES` with core's English label.
3. If it reads new `show_in_abilities` surface (post types, settings), extend `POST_TYPES` / `SETTINGS_FLAG_MAP` the same way.
4. Add unit coverage in `tests/phpunit/Unit/Compat/CoreAbilitiesPolyfillTest.php` and extend the fresh-process lifecycle test (`tests/phpunit/Integration/CoreAbilitiesPolyfillBootstrapTest.php`) if the ability has registration-time behavior. The CI matrix runs the suite against WordPress versions with and without core's copies, so assertions must branch on feature detection, never on version numbers.

### Moving an ability to core

The core patch is:

1. Copy the file from `core/abilities/` to `src/wp-includes/abilities/` unchanged.
2. Add core's loading lines — a `require` alongside the other ability classes in `wp-settings.php` and a `( new WP_Abilities_X() )->register();` call in `wp-includes/abilities.php` — matching how the existing three abilities are loaded.

Nothing changes in the plugin when core merges it: the `class_exists()` and `wp_has_ability()` guards make the plugin's copy inert on the new WordPress version with no plugin release required. Once the plugin's minimum WordPress version rises past the release that shipped the ability, delete the file and its `ABILITY_CLASSES` entry.

### Keeping copies in sync

`core/README.md` records the wordpress-develop commit the current copies were taken from. When re-syncing, copy each file byte-for-byte from `src/wp-includes/` at a known commit (for example with `git show <commit>:src/wp-includes/abilities/<file>`) and update the recorded commit.
