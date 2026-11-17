<?php
/**
 * OAuth server loader.
 *
 * A self-contained OAuth 2.1 authorization server backed by Application
 * Passwords. It lives in its own folder and namespace, with no dependency on
 * the rest of this plugin, so it can move to its own plugin or core later.
 *
 * Only the standalone plugin file loads this, so copies of the package that
 * other plugins bundle through Composer never start a second server.
 *
 * @package WP\OAuth
 */

declare( strict_types=1 );

namespace WP\OAuth;

defined( 'ABSPATH' ) || exit;

if ( class_exists( __NAMESPACE__ . '\\Server', false ) ) {
	return;
}

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = __NAMESPACE__ . '\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		$file     = __DIR__ . '/src/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( ! is_readable( $file ) ) {
			return;
		}

		require $file; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- Path is built from a namespaced class name inside this folder.
	}
);

add_action( 'plugins_loaded', array( Server::class, 'init' ) );
