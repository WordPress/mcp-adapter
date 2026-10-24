<?php
/**
 * The notice shown when another copy of MCP Adapter is the one running.
 *
 * This file declares no class or function: it may run while an older copy's
 * classes and functions are already loaded, where any declaration with the same
 * name would be a fatal error. It returns a closure instead.
 *
 * @package WP\MCP
 */

declare( strict_types=1 );

namespace WP\MCP;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Registers the notice for the copy whose file is given.
 *
 * @param string $running_file A file of the copy that is running.
 */
return static function ( string $running_file ): void {
	add_action(
		'init',
		static function () use ( $running_file ) {
			$running_version = '';
			if ( class_exists( Core\McpAdapter::class, false ) && defined( Core\McpAdapter::class . '::VERSION' ) ) {
				$running_version = (string) Core\McpAdapter::VERSION;
			} elseif ( defined( 'WP_MCP_VERSION' ) ) {
				$running_version = (string) WP_MCP_VERSION;
			}

			// Which plugin, must-use plugin or theme carries the running copy.
			$file    = wp_normalize_path( (string) realpath( $running_file ) );
			$carrier = '';
			$slug    = '';
			foreach (
				array(
					'plugin'    => defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : '',
					'mu-plugin' => defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : '',
					'theme'     => get_theme_root(),
				) as $kind => $root
			) {
				$root = wp_normalize_path( (string) realpath( (string) $root ) );
				if ( '' === $root || 0 !== strpos( $file, trailingslashit( $root ) ) ) {
					continue;
				}
				$parts   = explode( '/', substr( $file, strlen( trailingslashit( $root ) ) ) );
				$slug    = $parts[0];
				$carrier = $kind;
				break;
			}

			$name = $slug;
			if ( 'plugin' === $carrier ) {
				// Reads the headers of one plugin folder, and only when another copy is running.
				if ( ! function_exists( 'get_plugins' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}
				foreach ( get_plugins( '/' . $slug ) as $data ) {
					$name = $data['Name'] ? $data['Name'] : $slug;
					break;
				}
			} elseif ( 'theme' === $carrier ) {
				$name = wp_get_theme( $slug )->get( 'Name' ) ? wp_get_theme( $slug )->get( 'Name' ) : $slug;
			}

			$message = __( 'Another version of MCP Adapter is already loaded and may cause conflicts.', 'mcp-adapter' );
			if ( '' !== $name ) {
				$message .= ' ' . sprintf(
					/* translators: 1: plugin, must-use plugin or theme name, 2: the version it bundles. */
					__( '%1$s loads its own bundled copy (%2$s) before the installed plugin, so that copy is the one running. Update %1$s, or ask its developer to require the MCP Adapter plugin instead of bundling it.', 'mcp-adapter' ),
					$name,
					'' !== $running_version ? $running_version : __( 'an unknown version', 'mcp-adapter' )
				);
			} else {
				$message .= ' ' . __( 'This is usually caused by an outdated plugin bundling its own copy MCP Adapter as a dependency. Update that plugin to the latest version to ensure compatibility.', 'mcp-adapter' );
			}

			// For developers, in debug logs.
			_doing_it_wrong(
				Core\McpAdapter::class,
				esc_html( $message ),
				'0.7.0'
			);

			// For people who can act on it, on the screen where they can: the Plugins screen.
			$show = static function () use ( $message ) {
				$screen  = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
				$can_act = is_network_admin() ? current_user_can( 'manage_network_plugins' ) : current_user_can( 'activate_plugins' );
				if ( ! $can_act || ! $screen || ! in_array( $screen->id, array( 'plugins', 'plugins-network' ), true ) ) {
					return;
				}
				wp_admin_notice(
					esc_html( $message ),
					array(
						'type'        => 'warning',
						'dismissible' => true,
					)
				);
			};
			add_action( 'admin_notices', $show );
			add_action( 'network_admin_notices', $show );
		}
	);
};
