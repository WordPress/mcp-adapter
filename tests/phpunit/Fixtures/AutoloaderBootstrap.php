<?php
/**
 * Boots WordPress with a Jetpack-based bundler before the canonical plugin.
 *
 * @package WP\MCP\Tests
 * @since n.e.x.t
 *
 * phpcs:disable WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- Includes only repository sources and process-owned fixture files.
 * phpcs:disable WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- Reads local installed autoloader files, never remote URLs.
 * phpcs:disable WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents -- Writes disposable fixture files under the system temporary directory.
 * phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Serializes manifest arrays into PHP fixture files.
 */

declare( strict_types=1 );

use WP\MCP\Core\McpAdapter;

$wp_mcp_test_root      = dirname( __DIR__, 3 );
$wp_mcp_test_scenario  = $argv[2];
$wp_mcp_test_notices   = array();
$wp_mcp_test_before    = array();
$wp_mcp_test_bundler   = sys_get_temp_dir() . '/mcp-adapter-bundler-' . uniqid();
$wp_mcp_test_canonical = sys_get_temp_dir() . '/mcp-adapter-canonical-' . uniqid();

// Clean up even if bootstrap fails, before WordPress/Jetpack shutdown hooks can cache the fixture paths.
// phpcs:disable WordPressVIPMinimum.Functions.RestrictedFunctions.directory_rmdir, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink -- Removes only the directories and files created by this process.
register_shutdown_function(
	static function () use ( $wp_mcp_test_bundler, $wp_mcp_test_canonical ): void {
		foreach ( array( $wp_mcp_test_bundler, $wp_mcp_test_canonical ) as $directory ) {
			if ( ! is_dir( $directory ) ) {
				continue;
			}
			$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $files as $file ) {
				if ( $file->isDir() ) {
					rmdir( $file->getPathname() );
				} else {
					unlink( $file->getPathname() );
				}
			}
			rmdir( $directory );
		}
	}
);
// phpcs:enable WordPressVIPMinimum.Functions.RestrictedFunctions.directory_rmdir, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink

$wp_mcp_test_composer = require $wp_mcp_test_root . '/vendor/autoload.php';

// phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- Test library path supplied by the parent PHPUnit process.
require_once $argv[1] . '/includes/functions.php';

// Keep discovery and its cache deterministic without changing persistent plugin settings.
tests_add_filter( 'pre_transient_jetpack_autoloader_plugin_paths', '__return_empty_array' );
tests_add_filter(
	'pre_option_active_plugins',
	static function () use ( $wp_mcp_test_canonical, $wp_mcp_test_scenario, $wp_mcp_test_bundler ) {
		$plugins = array( $wp_mcp_test_bundler . '/plugin.php' );
		if ( in_array( $wp_mcp_test_scenario, array( 'discoverable', 'newer' ), true ) ) {
			$plugins[] = $wp_mcp_test_canonical . '/mcp-adapter.php';
		}
		return $plugins;
	}
);
tests_add_filter(
	'doing_it_wrong_run',
	static function ( $function_name, $message ) use ( &$wp_mcp_test_notices ) {
		if ( McpAdapter::class !== $function_name ) {
			return;
		}

		$wp_mcp_test_notices[] = $message;
	},
	10,
	2
);
tests_add_filter( 'doing_it_wrong_trigger_error', '__return_false' );

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $wp_mcp_test_root, $wp_mcp_test_canonical, $wp_mcp_test_scenario, $wp_mcp_test_bundler, $wp_mcp_test_composer, &$wp_mcp_test_before ) {
		// Reuse the installed Jetpack runtime in a separate namespace, as Composer does for each bundler.
		$packages = file_get_contents( $wp_mcp_test_root . '/vendor/autoload_packages.php' );
		preg_match( '/namespace ([^;]+);/', $packages, $matches );
		$namespace = $matches[1];
		foreach ( array(
			$wp_mcp_test_bundler   => '\\Bundler',
			$wp_mcp_test_canonical => '\\Canonical',
		) as $destination => $suffix ) {
			wp_mkdir_p( $destination . '/vendor/composer' );
			wp_mkdir_p( $destination . '/vendor/jetpack-autoloader' );
			foreach ( glob( $wp_mcp_test_root . '/vendor/jetpack-autoloader/*.php' ) as $source ) {
				file_put_contents(
					$destination . '/vendor/jetpack-autoloader/' . basename( $source ),
					str_replace( $namespace, $namespace . $suffix, file_get_contents( $source ) )
				);
			}
			file_put_contents( $destination . '/vendor/autoload_packages.php', str_replace( $namespace, $namespace . $suffix, $packages ) );
		}
		file_put_contents( $wp_mcp_test_bundler . '/plugin.php', '<?php' );
		// Only the legacy class identity is needed: its version predates the DIR constant.
		file_put_contents( $wp_mcp_test_bundler . '/McpAdapter.php', '<?php namespace WP\\MCP\\Core; final class McpAdapter { public const VERSION = "0.6.1"; }' );
		$bundled_version = '0.6.1';
		if ( 'newer' === $wp_mcp_test_scenario ) {
			// A newer copy that wins arbitration ships its own Plugin, which defines WP_MCP_DIR only if it is still undefined.
			$bundled_version = '9.9.9';
			file_put_contents( $wp_mcp_test_bundler . '/McpAdapter.php', '<?php namespace WP\\MCP\\Core; final class McpAdapter { public const VERSION = "9.9.9"; public const DIR = __DIR__; public static function instance() {} }' );
			file_put_contents( $wp_mcp_test_bundler . '/Plugin.php', '<?php namespace WP\\MCP; final class Plugin { public static function instance() { if ( ! defined( "WP_MCP_DIR" ) ) { define( "WP_MCP_DIR", __DIR__ . "/" ); } } }' );
		}

		// Copy the production entry point and loader classes; give their manifest release-build versions.
		foreach ( array( 'mcp-adapter.php', 'includes/Autoloader.php', 'includes/Core/McpAdapter.php', 'includes/Plugin.php' ) as $file ) {
			wp_mkdir_p( dirname( $wp_mcp_test_canonical . '/' . $file ) );
			copy( $wp_mcp_test_root . '/' . $file, $wp_mcp_test_canonical . '/' . $file );
		}
		preg_match( "/public const VERSION = '([^']+)'/", file_get_contents( $wp_mcp_test_root . '/includes/Core/McpAdapter.php' ), $matches );
		$release_version    = $matches[1];
		$canonical_manifest = require $wp_mcp_test_root . '/vendor/composer/jetpack_autoload_classmap.php';
		foreach ( $canonical_manifest as $class => &$entry ) {
			if ( str_starts_with( $entry['path'], $wp_mcp_test_root . '/includes/' ) ) {
				$entry['version'] = $release_version;
			}
			if ( ! in_array( $class, array( 'WP\\MCP\\Autoloader', McpAdapter::class, 'WP\\MCP\\Plugin' ), true ) ) {
				continue;
			}

			$entry['path'] = str_replace( $wp_mcp_test_root, $wp_mcp_test_canonical, $entry['path'] );
		}
		unset( $entry );
		file_put_contents( $wp_mcp_test_canonical . '/vendor/composer/jetpack_autoload_classmap.php', '<?php return ' . var_export( $canonical_manifest, true ) . ';' );
		$filemap = require $wp_mcp_test_root . '/vendor/composer/jetpack_autoload_filemap.php';
		file_put_contents( $wp_mcp_test_canonical . '/vendor/composer/jetpack_autoload_filemap.php', '<?php return ' . var_export( $filemap, true ) . ';' );
		$bundled_manifest = array(
			'Automattic\\Jetpack\\Autoloader\\AutoloadGenerator' => $canonical_manifest['Automattic\\Jetpack\\Autoloader\\AutoloadGenerator'],
			McpAdapter::class => array(
				'version' => $bundled_version,
				'path'    => $wp_mcp_test_bundler . '/McpAdapter.php',
			),
		);
		if ( 'newer' === $wp_mcp_test_scenario ) {
			$bundled_manifest['WP\\MCP\\Plugin'] = array(
				'version' => $bundled_version,
				'path'    => $wp_mcp_test_bundler . '/Plugin.php',
			);
		}
		file_put_contents( $wp_mcp_test_bundler . '/vendor/composer/jetpack_autoload_classmap.php', '<?php return ' . var_export( $bundled_manifest, true ) . ';' );

		// The canonical Composer fallback must not mask a plugin that fails to register its own manifest.
		$wp_mcp_test_composer->unregister();
		require $wp_mcp_test_bundler . '/vendor/autoload_packages.php';
		$wp_mcp_test_before['class_loaded'] = class_exists( McpAdapter::class, false );
		if ( 'preloaded' === $wp_mcp_test_scenario ) {
			class_exists( McpAdapter::class );
		}
		$load_canonical = static function () use ( $wp_mcp_test_canonical, $wp_mcp_test_composer ) {
			require $wp_mcp_test_canonical . '/mcp-adapter.php';
			$wp_mcp_test_composer->register();
		};
		if ( 'undiscovered' === $wp_mcp_test_scenario ) {
			// Hosts can load the canonical plugin after ordinary plugins have registered their loaders.
			add_action( 'plugins_loaded', $load_canonical );
		} else {
			$load_canonical();
		}
	}
);

ob_start();
// phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- The real WordPress test bootstrap, with installation skipped by the parent.
require $argv[1] . '/includes/bootstrap.php';
ob_end_clean();

$wp_mcp_test_result = array(
	'before_class_loaded' => $wp_mcp_test_before['class_loaded'],
	'version'             => McpAdapter::VERSION,
	'class_file'          => ( new ReflectionClass( McpAdapter::class ) )->getFileName(),
	'directory'           => defined( 'WP_MCP_DIR' ) ? WP_MCP_DIR : null,
	'plugin_loaded'       => class_exists( 'WP\\MCP\\Plugin', false ),
	'plugin_file'         => class_exists( 'WP\\MCP\\Plugin', false ) ? ( new ReflectionClass( 'WP\\MCP\\Plugin' ) )->getFileName() : null,
	'canonical_directory' => $wp_mcp_test_canonical . '/',
	'notices'             => $wp_mcp_test_notices,
);

echo wp_json_encode( $wp_mcp_test_result );
