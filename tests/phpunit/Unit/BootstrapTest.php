<?php
/**
 * Tests for the plugin bootstrap file.
 *
 * @package WP\MCP\Tests
 */

declare(strict_types=1);

namespace WP\MCP\Tests\Unit;

use WP\MCP\Autoloader;
use WP\MCP\Core\McpAdapter;
use WP\MCP\Plugin;
use WP\MCP\Tests\TestCase;

/**
 * Covers the guards that keep a second copy of MCP Adapter from taking the
 * whole site down.
 *
 * Composer publishes `wordpress/mcp-adapter` as a `wordpress-plugin`, so a
 * project that requires it as a library alongside `composer/installers` gets it
 * relocated to `wp-content/plugins/mcp-adapter/`. That copy carries a plugin
 * header, which means it can be activated next to the canonical plugin and
 * WordPress will load `mcp-adapter.php` twice from two different paths.
 */
final class BootstrapTest extends TestCase {

	/**
	 * Path to the second copy of the bootstrap file, if one was made.
	 *
	 * @var string
	 */
	private string $second_copy = '';

	/**
	 * Removes the temporary copy created by the tests.
	 */
	public function tear_down(): void {
		if ( '' !== $this->second_copy && file_exists( $this->second_copy ) ) {
			unlink( $this->second_copy );
		}

		$this->second_copy = '';

		parent::tear_down();
	}

	/**
	 * The bootstrap file must be a no-op when a copy has already loaded.
	 *
	 * Without the `WP_MCP_DIR` guard this fatals with "Cannot redeclare
	 * WP\MCP\constants()" while the second file is compiled — before any
	 * runtime check could intervene.
	 */
	public function test_second_copy_of_bootstrap_file_does_not_redeclare_symbols(): void {
		$original_dir     = WP_MCP_DIR;
		$original_version = WP_MCP_VERSION;

		require $this->make_second_copy();

		// Reaching this line at all is the assertion: a redeclaration would have
		// been fatal. The constants must still point at the first copy.
		$this->assertSame( $original_dir, WP_MCP_DIR );
		$this->assertSame( $original_version, WP_MCP_VERSION );
	}

	/**
	 * The already-loaded copy must keep ownership of the singleton.
	 */
	public function test_second_copy_does_not_replace_the_loaded_plugin_instance(): void {
		$instance = Plugin::instance();

		require $this->make_second_copy();

		$this->assertSame( $instance, Plugin::instance() );
	}

	/**
	 * A copy without its own `vendor/` directory is not a broken install when
	 * the classes are already registered with another project's autoloader.
	 *
	 * Composer flattens dependencies into the root project's `vendor/`
	 * directory, so a dependency copy never has one of its own. Telling those
	 * users to run `composer install` sends them chasing a file that is not
	 * supposed to exist. This asserts the signal the autoloader uses to tell
	 * that case apart from a genuinely incomplete source install.
	 */
	public function test_loaded_classes_are_detected_as_autoloaded_elsewhere(): void {
		$method = new \ReflectionMethod( Autoloader::class, 'is_autoloaded_elsewhere' );
		$method->setAccessible( true );

		$this->assertTrue( $method->invoke( null ) );
		$this->assertTrue( Autoloader::autoload() );
	}

	/**
	 * A normal plugin install must never be reported as non-canonical usage.
	 *
	 * This is the failure mode that would actually hurt people: every site
	 * running the plugin the intended way logging a `_doing_it_wrong()` notice.
	 * `WP_MCP_DIR` is defined by the bootstrap, which the test suite loads, so
	 * this exercises the same state a real plugin install is in.
	 */
	public function test_canonical_plugin_install_is_not_reported_as_doing_it_wrong(): void {
		$this->assertTrue( defined( 'WP_MCP_DIR' ), 'Precondition: the plugin bootstrap has run.' );

		// setExpectedIncorrectUsage() is deliberately not called — if the check
		// fires here, WP_UnitTestCase fails the test on the unexpected notice.
		McpAdapter::warn_if_not_canonical_plugin();

		$this->assertTrue( true );
	}

	/**
	 * The check must still run when the adapter is instantiated after `init`.
	 *
	 * It is queued on `init`, so a late `McpAdapter::instance()` — from
	 * `rest_api_init`, say — would otherwise hook an action that has already
	 * fired and silently never report anything.
	 */
	public function test_check_is_not_queued_on_an_init_hook_that_already_fired(): void {
		$this->assertGreaterThan( 0, did_action( 'init' ), 'Precondition: init has already fired.' );

		$before = has_action( 'init', array( McpAdapter::class, 'warn_if_not_canonical_plugin' ) );

		$method = new \ReflectionMethod( McpAdapter::class, 'schedule_canonical_plugin_check' );
		$method->setAccessible( true );
		$method->invoke( null );

		$this->assertSame(
			$before,
			has_action( 'init', array( McpAdapter::class, 'warn_if_not_canonical_plugin' ) ),
			'The check should run immediately rather than hook an init action that already fired.'
		);
	}

	/**
	 * Writes a byte-for-byte copy of the bootstrap file to a second path.
	 *
	 * The second path is what makes this faithful to the real scenario: two
	 * installed copies of the plugin, each loaded from its own directory by
	 * WordPress.
	 *
	 * @return string Absolute path to the copied bootstrap file.
	 */
	private function make_second_copy(): string {
		$this->second_copy = get_temp_dir() . uniqid( 'mcp-adapter-copy-', true ) . '.php';

		copy( WP_MCP_DIR . 'mcp-adapter.php', $this->second_copy );

		return $this->second_copy;
	}
}
