<?php
/**
 * PSR-4 Autoloader for PHP classes inside plugin.
 *
 * Ensures that autoloaders are present, and logs an Admin notice if not.
 *
 * Can be bypassed by defining the WP_MCP_AUTOLOAD constant to false.
 *
 * @package WP\MCP
 */

declare( strict_types=1 );

namespace WP\MCP;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - Autoloader
 */
final class Autoloader {
	/**
	 * Whether the autoloader has been loaded.
	 *
	 * @var bool
	 */
	protected static bool $is_loaded = false;

	/**
	 * Attempt to autoload the Composer dependencies.
	 */
	public static function autoload(): bool {
		// If we're not *supposed* to autoload anything, then return true.
		if ( defined( 'WP_MCP_AUTOLOAD' ) && false === WP_MCP_AUTOLOAD ) {
			return true;
		}

		if ( self::$is_loaded ) {
			return self::$is_loaded;
		}

		// If the class already exists that means another copy of the plugin is already loaded.
		if ( self::is_loaded_elsewhere() ) {
			self::$is_loaded = true;

			return self::$is_loaded;
		}

		// This is conditionally redefined in Plugin::constants().
		define( 'WP_MCP_DIR', plugin_dir_path( __DIR__ ) );

		// Jetpack Autoloader uses `autoload_packages.php` instead of `autoload.php`.
		$autoloader = WP_MCP_DIR . '/vendor/autoload_packages.php';

		if ( is_readable( $autoloader ) ) {
			self::$is_loaded = self::require_autoloader( $autoloader );

			return self::$is_loaded;
		}

		self::missing_autoloader_notice();

		return false;
	}

	/**
	 * Checks if the the plugin classes are already registered with another autoloader.
	 */
	private static function is_loaded_elsewhere(): bool {
		if ( ! class_exists( Core\McpAdapter::class ) ) {
			// Recheck this later in case other plugins haven't been loaded. No reason to block the autoloader.
			add_action(
				'plugins_loaded',
				static function () {
					self::is_loaded_elsewhere();
				}
			);
			return false;
		}

		self::loaded_elsewhere_notice();
		return true;
	}

	/**
	 * Displays a notice if the plugin classes are already registered with another autoloader.
	 */
	private static function loaded_elsewhere_notice(): void {
		// Defer notice until translation functions are available.
		add_action(
			'init',
			static function () {
				$error_message = __( 'Another version of MCP Adapter is already loaded and may cause conflicts. This is usually caused by an outdated plugin bundling its own copy MCP Adapter as a dependency. Update that plugin to the latest version to ensure compatibility.', 'mcp-adapter' );

				// Log a notice.
				_doing_it_wrong(
					Core\McpAdapter::class,
					esc_html( $error_message ),
					'x.y.z'
				);

				// Log an admin notice.
				$hooks = array(
					'admin_notices',
					'network_admin_notices',
				);
				foreach ( $hooks as $hook ) {
					add_action(
						$hook,
						static function () use ( $error_message ) {
							wp_admin_notice(
								esc_html( $error_message ),
								array(
									'type'    => 'error',
									'dismiss' => false,
								),
							);
						}
					);
				}
			}
		);
	}

	/**
	 * Attempts to load the autoloader file, if it exists.
	 *
	 * @param string $autoloader_file The path to the autoloader file.
	 */
	private static function require_autoloader( string $autoloader_file ): bool {
		if ( ! is_readable( $autoloader_file ) ) {
			self::missing_autoloader_notice();

			return false;
		}

		return (bool) require_once $autoloader_file; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- Autoloader is a Composer file.
	}

	/**
	 * Displays a notice if the autoloader is missing.
	 */
	private static function missing_autoloader_notice(): void {
		// Defer notice until translation functions are available.
		add_action(
			'init',
			static function () {
				$error_message = __( 'MCP Adapter: The Composer autoloader was not found. If you installed the plugin from the GitHub source code, make sure to run `composer install`.', 'mcp-adapter' );

				// Log a notice.
				_doing_it_wrong(
					self::class,
					esc_html( $error_message ),
					'x.y.z'
				);

				// Log an admin notice.
				$hooks = array(
					'admin_notices',
					'network_admin_notices',
				);
				foreach ( $hooks as $hook ) {
					add_action(
						$hook,
						static function () use ( $error_message ) {
							wp_admin_notice(
								esc_html( $error_message ),
								array(
									'type'    => 'error',
									'dismiss' => false,
								),
							);
						}
					);
				}
			}
		);
	}
}
