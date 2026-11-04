# WordPress core files, carried by the plugin

This directory holds copies of WordPress core files, plus core abilities that are developed in this plugin first and are destined for WordPress core. Paths mirror `wp-includes/` in [wordpress-develop](https://github.com/WordPress/wordpress-develop):

```
core/abilities/class-wp-abilities-content.php  ↔  src/wp-includes/abilities/class-wp-abilities-content.php
```

The copies were last synced from wordpress-develop trunk at commit `658bcc55325e75d2c2f6a38d06e57d8b454e8e52` (7.2-beta4).

## The two mechanical transforms

A copy differs from its upstream file by exactly two transforms, both reversible, so a sync in either direction is a copy plus a rename:

1. **Class prefix.** The class is declared as `WP_MCP_Abilities_*` instead of core's `WP_Abilities_*` (two lines per file: the header docblock and the `final class` declaration). Core loads its own `WP_Abilities_*` classes from `wp-settings.php` before any plugin runs, so a copy keeping core's name could never load on the WordPress versions that ship the class — and an ability staged inside it would be missing exactly there. The prefixed copy loads on every WordPress version.
2. **Per-ability guards.** Inside `register()`, each registration call is wrapped in a `wp_has_ability()` guard — `if ( ! wp_has_ability( 'core/users-query' ) ) { … }`, one guard around exactly one registration call — so a copy running next to core (or another plugin) registers only the abilities nothing else provides. Whoever registered first wins; core registers at priority 10, the polyfill at 20.

Everything else stays byte-identical to upstream: core's file naming, docblocks, `@since`, and i18n via `__()` with **no text domain**.

## Rules

- **Never change core-owned lines here.** A change to code that already exists in core lands upstream in wordpress-develop first and is then re-copied (applying the two transforms above).
- **Additions are allowed — and only additions.** A *staged* ability (one no WordPress release ships yet) may live in a file whose class core already ships: new registration calls, new private helpers, new constants. Relative to upstream, every change in such a file must be a pure addition, so re-syncing is conflict-free and the upstream patch is the additions as-is.
- A file **without** an upstream counterpart is entirely staged: written exactly as it will land in core (modulo the two transforms), with `@since` set to the targeted WordPress version and only `register()` public, callbacks behind closures.
- These files are intentionally **not** autoloaded (not in the Composer/Jetpack autoloader): `WP\MCP\Compat\CoreAbilitiesPolyfill` `require_once`s a file only when the running WordPress does not already provide every ability it holds.
- These files are **not** linted by the plugin's PHPCS ruleset or analyzed by PHPStan (core-owned code is validated upstream); staged code is linted with core rules via `composer lint:core`.

See `docs/guides/core-abilities.md` for how the polyfill behaves per WordPress version and the full staging workflow.
