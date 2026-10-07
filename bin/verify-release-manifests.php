<?php
/**
 * Fails when a Jetpack Autoloader manifest in an extracted release build
 * references a file the build does not contain.
 *
 * A dangling entry is fatal at runtime: the autoloader calls `require` on the
 * missing path as soon as anything asks for that class, for example
 * `class_exists( 'WP_CLI' )` on a normal web request (#283).
 *
 * Usage: php bin/verify-release-manifests.php <extracted-plugin-dir>
 */

declare( strict_types = 1 );

$plugin_dir = $argv[1] ?? '';
$manifests  = glob( rtrim( $plugin_dir, '/' ) . '/vendor/composer/jetpack_autoload_*.php' );

if ( '' === $plugin_dir || ! is_dir( $plugin_dir ) || empty( $manifests ) ) {
	fwrite( STDERR, "No Jetpack Autoloader manifests found under '{$plugin_dir}'.\n" );
	exit( 1 );
}

$missing = array();

foreach ( $manifests as $manifest ) {
	// Each manifest resolves its paths relative to its own location.
	$entries = require $manifest;

	array_walk_recursive(
		$entries,
		static function ( $value, $key ) use ( $manifest, &$missing ) {
			// Classmap and filemap entries hold a single 'path'; PSR-4 entries hold a list of paths.
			if ( ! is_string( $value ) || ( 'path' !== $key && ! is_int( $key ) ) ) {
				return;
			}

			if ( file_exists( $value ) ) {
				return;
			}

			$missing[] = basename( $manifest ) . ': ' . $value;
		}
	);
}

if ( ! empty( $missing ) ) {
	fwrite( STDERR, "Jetpack Autoloader manifests reference files missing from the build:\n" );
	fwrite( STDERR, '  ' . implode( "\n  ", $missing ) . "\n" );
	exit( 1 );
}

echo 'Checked ' . count( $manifests ) . " Jetpack Autoloader manifest(s): every referenced file exists.\n";
