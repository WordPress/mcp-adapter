<?php
/**
 * Tests for the Autoloader.
 *
 * @package WP\MCP\Tests
 *
 * @covers \WP\MCP\Autoloader
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit;

use WP\MCP\Autoloader;
use WP\MCP\Core\McpAdapter;
use WP\MCP\Plugin;
use WP\MCP\Tests\TestCase;
use WP\McpSchema\Schemas;

/**
 * Class - AutoloaderTest
 */
final class AutoloaderTest extends TestCase {

	/**
	 * {@inheritDoc}
	 */
	public function tearDown(): void {
		self::reset_is_loaded();

		parent::tearDown();
	}

	public function test_autoload_returns_true_when_composer_autoloader_is_available(): void {
		$this->assertTrue( Autoloader::autoload() );

		$this->assertTrue( class_exists( Plugin::class ), 'The plugin classes should be registered.' );
		$this->assertTrue( class_exists( McpAdapter::class ), 'The plugin core classes should be registered.' );
		$this->assertTrue( class_exists( Schemas::class ), 'The Composer dependencies should be registered.' );

		$this->assertTrue( Autoloader::autoload(), 'Repeated calls to autoload() should succeed.' );
	}

	/**
	 * A copy of the plugin whose classes are already registered by another autoloader must not fail: the existing registration is reused.
	 */
	public function test_autoload_returns_true_when_classes_are_already_registered_elsewhere(): void {
		$this->assertTrue( class_exists( McpAdapter::class ), 'Precondition: the classes are registered.' );

		self::reset_is_loaded();

		$this->assertTrue( Autoloader::autoload() );

		$this->setExpectedIncorrectUsage( McpAdapter::class );
		self::run_deferred_init_notice();

		$this->assertNotFalse( has_action( 'admin_notices' ) );
		$this->assertNotFalse( has_action( 'network_admin_notices' ) );

		$this->expectOutputRegex( '/Another version of MCP Adapter is already loaded/' );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Rendering the notice registered on the core hook.
		do_action( 'admin_notices' );
	}

	/**
	 * The plugins_loaded recheck must not report the plugin's own classes, which the plugin loads itself before that hook fires.
	 *
	 * @since n.e.x.t
	 */
	public function test_plugins_loaded_recheck_does_not_report_own_classes(): void {
		$init_callbacks = count( $GLOBALS['wp_filter']['init']->callbacks[10] );

		$method = new \ReflectionMethod( Autoloader::class, 'recheck_loaded_elsewhere' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}
		$method->invoke( null );

		$this->assertCount( $init_callbacks, $GLOBALS['wp_filter']['init']->callbacks[10], 'No notice should be queued for the plugin\'s own classes.' );
	}

	/**
	 * A class file outside the plugin directory belongs to another copy of the plugin.
	 *
	 * @since n.e.x.t
	 */
	public function test_is_other_copy_file_returns_true_for_file_outside_plugin(): void {
		$class_file = get_temp_dir() . uniqid( 'mcp-other-copy-', true ) . '.php';
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents -- Test-owned file under the system temporary directory.
		file_put_contents( $class_file, '<?php' );

		$this->assertTrue( self::is_other_copy_file( $class_file ) );

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink -- Test-owned file under the system temporary directory.
		unlink( $class_file );
	}

	/**
	 * The plugin's own class file does not belong to another copy.
	 *
	 * @since n.e.x.t
	 */
	public function test_is_other_copy_file_returns_false_for_own_file(): void {
		$this->assertFalse( self::is_other_copy_file( ( new \ReflectionClass( McpAdapter::class ) )->getFileName() ) );
	}

	/**
	 * A class file that cannot be resolved cannot be identified as another copy.
	 *
	 * @since n.e.x.t
	 */
	public function test_is_other_copy_file_returns_false_for_unresolved_file(): void {
		$this->assertFalse( self::is_other_copy_file( false ) );
		$this->assertFalse( self::is_other_copy_file( get_temp_dir() . uniqid( 'mcp-missing-', true ) . '/McpAdapter.php' ) );
	}

	/**
	 * Requiring a readable autoloader file succeeds.
	 */
	public function test_require_autoloader_returns_true_for_readable_file(): void {
		$autoloader_file = get_temp_dir() . uniqid( 'mcp-autoloader-', true ) . '.php';
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents -- Test-owned file under the system temporary directory.
		file_put_contents( $autoloader_file, '<?php return true;' );

		$this->assertTrue( self::require_autoloader( $autoloader_file ) );

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink -- Test-owned file under the system temporary directory.
		unlink( $autoloader_file );
	}

	/**
	 * A missing autoloader file is reported as a failure.
	 */
	public function test_require_autoloader_fails_and_warns_for_missing_file(): void {
		$autoloader_file = get_temp_dir() . uniqid( 'mcp-autoloader-missing-', true ) . '/autoload_packages.php';

		$this->assertFalse( self::require_autoloader( $autoloader_file ) );

		$this->setExpectedIncorrectUsage( Autoloader::class );
		self::run_deferred_init_notice();

		$this->assertNotFalse( has_action( 'admin_notices' ) );
		$this->assertNotFalse( has_action( 'network_admin_notices' ) );

		$this->expectOutputRegex( '/composer install/' );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Rendering the notice registered on the core hook.
		do_action( 'admin_notices' );
	}

	/**
	 * Invokes the private Autoloader::require_autoloader() method.
	 *
	 * @param string $autoloader_file Path to the autoloader file.
	 * @return bool Whether requiring the autoloader file succeeded.
	 */
	private static function require_autoloader( string $autoloader_file ): bool {
		$method = new \ReflectionMethod( Autoloader::class, 'require_autoloader' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		return (bool) $method->invoke( null, $autoloader_file );
	}

	/**
	 * Invokes the private Autoloader::is_other_copy_file() method.
	 *
	 * @param string|false $class_file The class file path, or false if it is unknown.
	 * @return bool Whether the file belongs to another copy of the plugin.
	 */
	private static function is_other_copy_file( $class_file ): bool {
		$method = new \ReflectionMethod( Autoloader::class, 'is_other_copy_file' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		return (bool) $method->invoke( null, $class_file );
	}

	/**
	 * Resets the loaded flag the bootstrap set, so autoload() replays the
	 * checks a second copy of the plugin would go through.
	 */
	private static function reset_is_loaded(): void {
		$property = new \ReflectionProperty( Autoloader::class, 'is_loaded' );
		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}
		$property->setValue( null, false );
	}

	/**
	 * Runs the notice closure the Autoloader registered on the init hook.
	 *
	 * init has already fired when tests run, and re-firing it would repeat
	 * unrelated core setup, so the most recently registered callback at the
	 * default priority is invoked directly.
	 */
	private static function run_deferred_init_notice(): void {
		$entries = $GLOBALS['wp_filter']['init']->callbacks[10];
		$notice  = end( $entries )['function'];
		$notice();
	}
}
