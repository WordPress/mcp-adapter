<?php
/**
 * Registers the WordPress 7.2 core abilities on WordPress versions that do not ship them.
 *
 * The ability implementations themselves are verbatim copies of core files, kept in the
 * plugin's `core/` directory (see `core/README.md`). This class is the plugin-owned glue
 * that loads and registers them on WordPress 6.9–7.1 and bridges the registration
 * surface those files expect from WordPress 7.2. It never moves into core: once the
 * plugin's minimum WordPress version reaches the release that ships an ability, the
 * ability's copy and its entry here are deleted.
 *
 * Everything is feature-detected, never version-compared, so each shim disables itself
 * as soon as core (or another plugin, such as a feature plugin backporting parts of the
 * Abilities API) provides the capability it bridges.
 *
 * @package WP\MCP
 */

declare( strict_types=1 );

namespace WP\MCP\Compat;

use WP_Ability;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Loads and registers core abilities that the running WordPress version does not provide.
 */
final class CoreAbilitiesPolyfill {

	/**
	 * The core ability classes this plugin can polyfill, in core's registration order.
	 *
	 * Each entry maps a global core class name to its file in `core/abilities/` and the
	 * ability names the class registers. A class is loaded and registered only when the
	 * class does not already exist and none of its abilities are registered.
	 *
	 * @var array<string, array{file: string, abilities: list<string>}>
	 */
	// phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- False positive: sniff mistakes array() commas for multi-const commas (only handles short syntax).
	private const ABILITY_CLASSES = array(
		'WP_Abilities_Settings' => array(
			'file'      => 'class-wp-abilities-settings.php',
			'abilities' => array( 'core/settings-get' ),
		),
		'WP_Abilities_Users'    => array(
			'file'      => 'class-wp-abilities-users.php',
			'abilities' => array( 'core/users-query' ),
		),
		'WP_Abilities_Content'  => array(
			'file'      => 'class-wp-abilities-content.php',
			'abilities' => array( 'core/content-query' ),
		),
	);

	/**
	 * The ability categories the polyfilled abilities are registered under, in core's order.
	 *
	 * Each is registered only when missing: WordPress 6.9–7.1 provides `site` and `user`
	 * itself and 7.2 adds `content` and `uncategorized`, but none of them can be assumed —
	 * the WordPress test framework, for one, unhooks core's category registration entirely.
	 *
	 * Labels and descriptions match core's English strings. They are intentionally not
	 * translated: the originals use core's text domain, so no plugin translations exist.
	 *
	 * @var array<string, array{label: string, description: string}>
	 */
	// phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- False positive: sniff mistakes array() commas for multi-const commas (only handles short syntax).
	private const CATEGORIES = array(
		'site'          => array(
			'label'       => 'Site',
			'description' => 'Abilities that retrieve or modify site information and settings.',
		),
		'user'          => array(
			'label'       => 'User',
			'description' => 'Abilities that retrieve or modify user information and settings.',
		),
		'content'       => array(
			'label'       => 'Content',
			'description' => 'Abilities that retrieve or manage posts and other content.',
		),
		'uncategorized' => array(
			'label'       => 'Uncategorized',
			'description' => 'Abilities that have not been assigned to a specific category.',
		),
	);

	/**
	 * The initial settings WordPress 7.2 flags with `show_in_abilities`, keyed by group.
	 *
	 * Mirrors the flags core adds in `register_initial_settings()` since 7.2.
	 *
	 * @var array<string, list<string>>
	 */
	// phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- False positive: sniff mistakes array() commas for multi-const commas (only handles short syntax).
	private const SETTINGS_FLAG_MAP = array(
		'general'    => array( 'blogname', 'blogdescription', 'siteurl', 'admin_email', 'timezone_string', 'date_format', 'time_format', 'start_of_week', 'WPLANG' ),
		'writing'    => array( 'use_smilies', 'default_category', 'default_post_format' ),
		'reading'    => array( 'posts_per_page', 'show_on_front', 'page_on_front', 'page_for_posts', 'wp_page_for_privacy_policy' ),
		'discussion' => array( 'default_ping_status', 'default_comment_status' ),
	);

	/**
	 * The post types WordPress 7.2 registers with `show_in_abilities => true`.
	 *
	 * @var list<string>
	 */
	// phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- False positive: sniff mistakes array() commas for multi-const commas (only handles short syntax).
	private const POST_TYPES = array( 'post', 'page' );

	/**
	 * Subscribes the polyfill to the hooks it needs.
	 *
	 * Must run while the plugin file is being included: the Abilities API registration
	 * actions fire lazily and only once, any time after `init` starts, so subscribing on
	 * a later hook could miss them (the same constraint applies in McpAdapter::instance()).
	 *
	 * When every class in ABILITY_CLASSES already exists — core loads its own copies from
	 * wp-settings.php before any plugin — there is nothing to polyfill and no hook is added.
	 */
	public static function register_hooks(): void {
		if ( ! self::has_missing_ability_class() ) {
			return;
		}

		add_filter( 'register_post_type_args', array( self::class, 'add_post_type_arg' ), 10, 2 );
		add_filter( 'register_setting_args', array( self::class, 'add_setting_arg' ), 10, 4 );
		add_filter( 'wp_register_ability_args', array( self::class, 'map_required_capabilities' ), 10, 2 );
		add_action( 'wp_abilities_api_categories_init', array( self::class, 'register_categories' ), 20 );
		// Priority 1 mirrors core's own hook for `_wp_register_initial_settings_for_abilities()`,
		// so the flagged settings exist before WP_Abilities_Settings::register() snapshots them.
		add_action( 'wp_abilities_api_init', array( self::class, 'register_initial_settings_for_abilities' ), 1 );
		// Priority 20: core registers at 10, so when core provides an ability it always wins
		// before the wp_has_ability() guard in register_core_abilities() runs.
		add_action( 'wp_abilities_api_init', array( self::class, 'register_core_abilities' ), 20 );
	}

	/**
	 * Whether at least one polyfillable core ability class is missing from this WordPress.
	 */
	private static function has_missing_ability_class(): bool {
		foreach ( array_keys( self::ABILITY_CLASSES ) as $class_name ) {
			if ( ! class_exists( $class_name ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the polyfill is enabled.
	 */
	private static function is_enabled(): bool {
		/**
		 * Filters whether the plugin registers the core abilities missing from this WordPress version.
		 *
		 * Return false to prevent the plugin from registering any `core/*` ability and from
		 * applying the registration shims (the `show_in_abilities` flags on post types and
		 * settings, and the `required_capabilities` mapping) that support them.
		 *
		 * @since 0.8.0
		 *
		 * @param bool $register Whether to register the missing core abilities. Default true.
		 */
		return (bool) apply_filters( 'mcp_adapter_register_core_abilities', true );
	}

	/**
	 * Flags the post types WordPress 7.2 exposes to abilities with `show_in_abilities`.
	 *
	 * In production the plugin loads after wp-settings.php already called
	 * `create_initial_post_types()` once, so this filter misses that call — harmlessly,
	 * because core runs `create_initial_post_types()` again at `init` priority 0 and the
	 * Abilities API cannot initialize before `init`.
	 *
	 * @param array<string, mixed> $args      The post type registration arguments.
	 * @param string               $post_type The post type name.
	 * @return array<string, mixed> The filtered arguments.
	 */
	public static function add_post_type_arg( array $args, string $post_type ): array {
		if ( ! self::is_enabled() || isset( $args['show_in_abilities'] ) || ! in_array( $post_type, self::POST_TYPES, true ) ) {
			return $args;
		}

		$args['show_in_abilities'] = true;

		return $args;
	}

	/**
	 * Flags the settings WordPress 7.2 exposes to abilities with `show_in_abilities`.
	 *
	 * @param array<string, mixed> $args         The setting registration arguments.
	 * @param array<string, mixed> $defaults     The default registration arguments.
	 * @param string               $option_group The settings group.
	 * @param string               $option_name  The option name.
	 * @return array<string, mixed> The filtered arguments.
	 */
	public static function add_setting_arg( array $args, array $defaults, string $option_group, string $option_name ): array {
		if ( ! self::is_enabled() || isset( $args['show_in_abilities'] ) ) {
			return $args;
		}

		if ( ! isset( self::SETTINGS_FLAG_MAP[ $option_group ] ) || ! in_array( $option_name, self::SETTINGS_FLAG_MAP[ $option_group ], true ) ) {
			return $args;
		}

		$args['show_in_abilities'] = true;

		return $args;
	}

	/**
	 * Converts the WordPress 7.2 `required_capabilities` ability argument for older versions.
	 *
	 * Before 7.2, `WP_Ability` rejects unknown arguments with a `_doing_it_wrong` notice and
	 * requires a `permission_callback`. This maps the capability list to an equivalent
	 * callback with 7.2 semantics — each capability checked with `current_user_can()`,
	 * failing fast, and any explicit `permission_callback` running only after all
	 * capabilities pass — and removes the unknown argument.
	 *
	 * Only abilities in the `core/` namespace are mapped, so the registration behavior of
	 * third-party abilities is never changed.
	 *
	 * @param array<string, mixed> $args The ability registration arguments.
	 * @param string               $name The ability name.
	 * @return array<string, mixed> The filtered arguments.
	 */
	public static function map_required_capabilities( array $args, string $name ): array {
		if ( ! self::is_enabled() || ! isset( $args['required_capabilities'] ) || ! str_starts_with( $name, 'core/' ) ) {
			return $args;
		}

		// This WordPress (or a feature plugin's WP_Ability) supports the argument natively.
		if ( property_exists( WP_Ability::class, 'required_capabilities' ) ) {
			return $args;
		}

		$capabilities = $args['required_capabilities'];
		unset( $args['required_capabilities'] );

		if ( ! is_array( $capabilities ) || array() === $capabilities ) {
			return $args;
		}

		$permission_callback = $args['permission_callback'] ?? null;

		$args['permission_callback'] = static function ( $input = null ) use ( $capabilities, $permission_callback ) {
			foreach ( $capabilities as $capability ) {
				// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Capabilities come from the ability's own registration arguments.
				if ( ! is_string( $capability ) || ! current_user_can( $capability ) ) {
					return false;
				}
			}

			if ( null !== $permission_callback && is_callable( $permission_callback ) ) {
				return $permission_callback( $input );
			}

			return true;
		};

		return $args;
	}

	/**
	 * Registers the ability categories the polyfilled abilities need that nothing else provides.
	 *
	 * Runs on `wp_abilities_api_categories_init` after core and other plugins (priority
	 * 20), registering only the categories nothing else has claimed.
	 */
	public static function register_categories(): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		foreach ( self::CATEGORIES as $slug => $args ) {
			if ( wp_has_ability_category( $slug ) ) {
				continue;
			}

			wp_register_ability_category( $slug, $args );
		}
	}

	/**
	 * Makes sure the settings the core abilities read are registered in time.
	 *
	 * Mirrors core's private `_wp_register_initial_settings_for_abilities()` (WordPress
	 * 7.2): the initial settings normally register on `rest_api_init`, which fires lazily
	 * and independently of `wp_abilities_api_init`, so they may not exist yet when
	 * WP_Abilities_Settings::register() snapshots the exposed settings. Re-registering
	 * them later on `rest_api_init` is harmless.
	 *
	 * Additionally registers the `wp_page_for_privacy_policy` setting, which WordPress
	 * before 7.2 never registers — not even on `rest_api_init` — exposed to abilities
	 * under the same `page_for_privacy_policy` name as in 7.2. It is deliberately not
	 * given `show_in_rest`, so the REST API settings endpoint behaves exactly as the
	 * running WordPress version shipped it.
	 *
	 * @global array<int|string, mixed> $new_allowed_options
	 */
	public static function register_initial_settings_for_abilities(): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		global $new_allowed_options;

		$allowed_options = $new_allowed_options;

		if ( ! did_action( 'rest_api_init' ) || doing_action( 'rest_api_init' ) ) {
			register_initial_settings();
		}

		$registered_settings = get_registered_settings();
		if ( ! isset( $registered_settings['wp_page_for_privacy_policy'] ) ) {
			register_setting(
				'reading',
				'wp_page_for_privacy_policy',
				array(
					'show_in_abilities' => array( 'name' => 'page_for_privacy_policy' ),
					'type'              => 'integer',
					// Core's string, untranslated here: the original uses core's text domain.
					'description'       => 'The ID of the page that should be displayed as the privacy policy page',
				)
			);
		}

		/*
		 * Registering a setting also allows it on the options screen of its group. Restore
		 * the list, so saving Settings > General does not try to save `admin_email`, which
		 * that screen sends as `new_admin_email`.
		 */
		$new_allowed_options = $allowed_options; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Restoring the WordPress core global, not defining a plugin one.
	}

	/**
	 * Loads and registers the core abilities this WordPress version does not provide.
	 *
	 * Runs on `wp_abilities_api_init` after core and other plugins (priority 20). A class
	 * is skipped when it is already declared elsewhere and any of its abilities is already
	 * registered; its file is loaded only when the class does not exist, so a WordPress
	 * (or another plugin) that ships it is never redeclared.
	 */
	public static function register_core_abilities(): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		foreach ( self::ABILITY_CLASSES as $class_name => $entry ) {
			if ( self::has_any_ability( $entry['abilities'] ) ) {
				continue;
			}

			if ( ! class_exists( $class_name ) ) {
				require_once dirname( __DIR__, 2 ) . '/core/abilities/' . $entry['file'];
			}

			( new $class_name() )->register();
		}
	}

	/**
	 * Whether any of the given abilities is already registered.
	 *
	 * @param list<string> $abilities The ability names.
	 */
	private static function has_any_ability( array $abilities ): bool {
		foreach ( $abilities as $name ) {
			if ( wp_has_ability( $name ) ) {
				return true;
			}
		}

		return false;
	}
}
