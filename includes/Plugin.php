<?php
/**
 * The main plugin file.
 *
 * If we evolve from a canonical plugin into WordPress core, this file would be left behind.
 *
 * @package WP\MCP
 */

declare(strict_types = 1);

namespace WP\MCP;

use WP\MCP\Core\McpAdapter;

/**
 * Class - Plugin
 */
final class Plugin {
	/**
	 * The one true plugin.
	 *
	 * @var static
	 */
	private static self $instance;

	/**
	 * {@inheritDoc}
	 */
	public static function instance(): self {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
			self::$instance->setup();

			/**
			 * Fires after the main plugin class has been initialized.
			 *
			 * @param self $instance The main plugin class instance.
			 */
			do_action( 'wp_mcp_init', self::$instance );
		}

		return self::$instance;
	}

	/**
	 * Setup the plugin.
	 */
	private function setup(): void {
		// Bail if dependencies are not met.
		if ( ! $this->has_dependencies() ) {
			return;
		}

		McpAdapter::instance();
	}

	/**
	 * Check if all required dependencies are available.
	 *
	 * Will log an admin notice if dependencies are missing.
	 *
	 * @return bool True if all dependencies are met, false otherwise.
	 */
	private function has_dependencies(): bool {
		// Check if Abilities API is available.
		if ( ! function_exists( 'wp_register_ability' ) ) {
			add_action(
				'admin_notices',
				static function () {
					wp_admin_notice(
						__( 'Abilities API not available (wp_register_ability function not found)', 'mcp-adapter' ),
						array(
							'type'    => 'error',
							'dismiss' => false,
						),
					);
				}
			);

			return false;
		}

		return true;
	}

	/**
	 * Prevent the class from being cloned.
	 */
	public function __clone() {
		_doing_it_wrong(
			__FUNCTION__,
			sprintf(
				// translators: %s: Class name.
				esc_html__( 'The %s class should not be cloned.', 'mcp-adapter' ),
				esc_html( self::class ),
			),
			'0.1.0'
		);
	}

	/**
	 * Prevent the class from being deserialized.
	 */
	public function __wakeup() {
		_doing_it_wrong(
			__FUNCTION__,
			sprintf(
				// translators: %s: Class name.
				esc_html__( 'De-serializing instances of %s is not allowed.', 'mcp-adapter' ),
				esc_html( self::class ),
			),
			'0.1.0'
		);
	}
}
