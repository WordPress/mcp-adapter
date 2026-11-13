# WordPress core files, carried by the plugin

This directory holds copies of WordPress core files, plus core abilities that are developed in this plugin first and are destined for WordPress core. Paths mirror `wp-includes/` in [wordpress-develop](https://github.com/WordPress/wordpress-develop):

```
core/abilities/class-wp-mcp-abilities-content.php  ↔  src/wp-includes/abilities/class-wp-abilities-content.php
```

The copies track wordpress-develop trunk. `php bin/diff-core-abilities.php` compares each copy with trunk and reports any drift; pass `--wp-develop=/path/to/wordpress-develop` to compare with a local checkout instead.

See [`docs/guides/core-abilities.md`](../docs/guides/core-abilities.md) for how the polyfill behaves per WordPress version, the permissions that apply, and how to disable it.

## The two mechanical transforms

A copy differs from its upstream file by two transforms, both reversible, so a sync in either direction is a copy plus a rename:

1. **Class prefix.** The class is declared as `WP_MCP_Abilities_*` instead of core's `WP_Abilities_*` (two lines per file: the header docblock and the `final class` declaration), and the file name follows the class name (`class-wp-mcp-abilities-*.php` for core's `class-wp-abilities-*.php`). Core loads its own `WP_Abilities_*` classes from `wp-settings.php` before any plugin runs, so a copy keeping core's name could never load on the WordPress versions that ship the class — and an ability staged inside it would be missing exactly there. The prefixed copy loads on every WordPress version.
2. **Per-ability guards.** Inside `register()`, each registration call is wrapped in a `wp_has_ability()` guard — `if ( ! wp_has_ability( 'core/users-query' ) ) { … }`, one guard around exactly one registration call — so a copy running next to core (or another plugin) registers only the abilities nothing else provides. Whoever registered first wins; core registers at priority 10, the polyfill at 20.

Everything else stays byte-identical to upstream, apart from the known differences below: docblocks, `@since`, and i18n via `__()` with **no text domain**.

## Known differences

Changes to core-owned lines that the copies need to run on WordPress versions before 7.2. Keep this list short: `bin/diff-core-abilities.php` reverses each entry before comparing, and the core patch for a file reverses them too.

- **`class-wp-mcp-abilities-settings.php`:** `core/settings-get` checks `manage_options` in a `permission_callback` instead of core's `'required_capabilities' => array( 'manage_options' )`, an argument WordPress before 7.2 does not support. The other registration arguments are re-aligned to the shorter key.

## Rules

- **Never change core-owned lines here,** except for the known differences above. A change to code that already exists in core lands upstream in wordpress-develop first and is then re-copied (applying the two transforms above).
- **Additions are allowed — and only additions.** A *staged* ability (one no WordPress release ships yet) may live in a file whose class core already ships: new registration calls, new private helpers, new constants. Relative to upstream, every change in such a file must be a pure addition, so re-syncing is conflict-free and the upstream patch is the additions as-is.
- A file **without** an upstream counterpart is entirely staged: written exactly as it will land in core (modulo the two transforms), with `@since` set to the targeted WordPress version and only `register()` public, callbacks behind closures.
- These files are intentionally **not** autoloaded (not in the Composer/Jetpack autoloader): `WP\MCP\Compat\CoreAbilitiesPolyfill` `require_once`s a file only when the running WordPress does not already provide every ability it holds.
- These files are **not** linted by the plugin's PHPCS ruleset or analyzed by PHPStan (core-owned code is validated upstream); staged code is linted with core rules via `composer lint:core`.

## Developing future core abilities

New core abilities (content updates, user updates, …) are developed and tested in the MCP Adapter first, then moved to WordPress core. The infrastructure is built so that the move is a copy-paste.

### Authoring rules

A new ability goes **into the existing class that owns its domain** whenever the shared helpers fit — core's classes say so themselves ("Future write-oriented user abilities can reuse them as well"). For example, `core/user-update` is a new guarded registration plus private helpers added to `WP_MCP_Abilities_Users`. A new file under `core/abilities/` (named after the class: `class-wp-mcp-abilities-{name}.php`) is only for a genuinely new domain.

Write the code exactly as it will land in core, modulo the two mechanical transforms above:

- A global `final` class named `WP_MCP_Abilities_*`, in a file named after it. The `WP_MCP_` prefix replaces core's `WP_` on exactly two lines (header docblock and class declaration) plus the file name, and is what lets the copy load on WordPress versions that ship the core class; it is renamed back when the code moves upstream.
- Each registration call in `register()` wrapped in its own `wp_has_ability()` guard — one guard around exactly one `$this->register_…();` call. The guard is what makes partial states safe: a WordPress that ships some of the class's abilities only gains the ones it is missing.
- In a file whose class core already ships, every change relative to upstream must be a **pure addition** (new registration calls, helpers, constants) so re-syncing stays conflict-free.
- `declare( strict_types=1 );` and core's file-level docblock, with `@since` set to the WordPress version the ability targets.
- Translatable strings via `__()` **without** a text domain.
- Only `register()` public; execute and permission callbacks stay private or are closures.
- PHP compatible with the plugin's floor (7.4). Core polyfills such as `array_any()`/`array_all()` are safe — they ship with WordPress 6.9+.
- Check capabilities in a `permission_callback`, not with the `required_capabilities` argument: WordPress before 7.2 does not support it.

### Wiring a staged ability

1. In `CoreAbilitiesPolyfill::ABILITY_CLASSES`, add the ability's name to its class's `abilities` **and** `staged` lists (for a new class, add a whole entry: file, `core_class`, both lists). `staged` is what keeps the polyfill hooked on WordPress versions that ship the class without the new ability; `abilities` is the complete list the copy registers, which the registrar checks to decide whether the copy needs loading at all.
2. If the ability needs a new category, add it to `CoreAbilitiesPolyfill::CATEGORIES` with core's English label.
3. If it reads new `show_in_abilities` surface (post types, settings), extend `POST_TYPES` / `SETTINGS_FLAG_MAP` the same way.
4. Add unit coverage in `tests/phpunit/Unit/Compat/CoreAbilitiesPolyfillTest.php` and extend the fresh-process lifecycle test (`tests/phpunit/Integration/CoreAbilitiesPolyfillBootstrapTest.php`) if the ability has registration-time behavior. The CI matrix runs the suite against WordPress versions with and without core's classes, so assertions must branch on feature detection, never on version numbers.

### Moving an ability to core

The core patch is:

1. Copy the file (or, for an ability staged into an existing class, the added lines) from `core/abilities/` to `src/wp-includes/abilities/`, reversing the two transforms: rename the `WP_MCP_` prefix back to `WP_` (in the class name and the file name), and unwrap the `wp_has_ability()` guards in `register()` so each registration call runs unconditionally. Reverse any known difference in the copied lines as well.
2. For a new class, add core's loading lines — a `require` alongside the other ability classes in `wp-settings.php` and a `( new WP_Abilities_X() )->register();` call in `wp-includes/abilities.php` — matching how the existing three abilities are loaded.

Nothing changes in the plugin when core merges it: the `wp_has_ability()` guards make the plugin's registrations inert on the new WordPress version with no plugin release required. The next release moves the ability out of the `staged` list (so the load-time gate can short-circuit again on that WordPress version), and once the plugin's minimum WordPress version rises past the release that shipped the ability, its guard, copy, or `ABILITY_CLASSES` entry is deleted.

### Keeping copies in sync

When core changes a copied file, copy it again from `src/wp-includes/` on wordpress-develop trunk (for example with `git show trunk:src/wp-includes/abilities/<file>`), then re-apply the two mechanical transforms, the known differences, and any staged additions. Run `php bin/diff-core-abilities.php` afterwards: once normalized, each copy must match trunk exactly, except for staged additions.
