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
	 * Classes loaded from this plugin's own directory are not another copy, so no notice is queued.
	 *
	 * This covers the plugins_loaded recheck, which runs after the plugin has loaded its own classes.
	 *
	 * @since 0.7.0
	 */
	public function test_is_loaded_elsewhere_does_not_report_own_classes(): void {
		$this->assertTrue( class_exists( McpAdapter::class ), 'Precondition: the plugin classes are registered.' );

		$init_callbacks = count( $GLOBALS['wp_filter']['init']->callbacks[10] );

		$this->assertFalse( self::invoke_private( 'is_loaded_elsewhere' ) );
		$this->assertCount( $init_callbacks, $GLOBALS['wp_filter']['init']->callbacks[10], 'No notice should be queued for the plugin\'s own classes.' );
	}

	/**
	 * The notice for another copy is logged and rendered as an admin notice.
	 */
	public function test_loaded_elsewhere_notice_logs_and_renders_admin_notice(): void {
		self::invoke_private( 'loaded_elsewhere_notice' );

		$this->setExpectedIncorrectUsage( McpAdapter::class );
		self::run_deferred_init_notice();

		$this->assertNotFalse( has_action( 'admin_notices' ) );
		$this->assertNotFalse( has_action( 'network_admin_notices' ) );

		$this->expectOutputRegex( '/Another version of MCP Adapter is already loaded/' );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Rendering the notice registered on the core hook.
		do_action( 'admin_notices' );
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
	 * Invokes a private Autoloader method that takes no arguments.
	 *
	 * @param string $method_name The method name.
	 * @return mixed The method's return value.
	 */
	private static function invoke_private( string $method_name ) {
		$method = new \ReflectionMethod( Autoloader::class, $method_name );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		return $method->invoke( null );
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
