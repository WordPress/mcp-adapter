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

declare( strict_types = 1 );

namespace WP\MCP;

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

		$autoloader      = WP_MCP_DIR . '/vendor/autoload.php';
		self::$is_loaded = self::require_autoloader( $autoloader );

		return self::$is_loaded;
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

		$hooks = array(
			'admin_notices',
			'network_admin_notices',
		);

		foreach ( $hooks as $hook ) {
			add_action(
				$hook,
				static function () {
					$error_message = __( 'MCP Adapter: The Composer autoloader was not found. If you installed the plugin from the GitHub source code, make sure to run `composer install`.', 'mcp-adapter' );

					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						error_log( esc_html( $error_message ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- This is a development notice.
					}
					?>
					<div class="error notice">
						<p>
							<?php echo esc_html( $error_message ); ?>
						</p>
					</div>
					<?php
				}
			);
		}
	}
}
