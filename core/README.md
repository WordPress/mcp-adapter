# Verbatim WordPress core files

This directory holds byte-identical copies of WordPress core files, plus core abilities that are developed in this plugin first and are destined for WordPress core. Paths mirror `wp-includes/` in [wordpress-develop](https://github.com/WordPress/wordpress-develop):

```
core/abilities/class-wp-abilities-content.php  ↔  src/wp-includes/abilities/class-wp-abilities-content.php
```

The copies were last synced from wordpress-develop trunk at commit `658bcc55325e75d2c2f6a38d06e57d8b454e8e52` (7.2-beta4).

## Rules

- **Never edit a copied file here.** A change to a file that already exists in core lands upstream in wordpress-develop first and is then re-copied, so the files stay byte-identical.
- A file **without** an upstream counterpart is a *staged* ability being developed plugin-first for a future WordPress release. It is written exactly as it will land in core: global `final` class, core file naming, `declare( strict_types=1 )`, `@since` set to the targeted WordPress version, i18n via `__()` with **no text domain**, and only `register()` public with the callbacks behind closures. When core merges it, the file becomes the core patch as-is.
- These files are intentionally **not** autoloaded (not in the Composer/Jetpack autoloader): `WP\MCP\Compat\CoreAbilitiesPolyfill` `require_once`s a file only when the running WordPress does not already declare its class.
- These files are **not** linted by the plugin's PHPCS ruleset or analyzed by PHPStan (they are validated upstream); staged files are linted with core rules via `composer lint:core`.

See `docs/guides/core-abilities.md` for how the polyfill behaves per WordPress version and the full staging workflow.
