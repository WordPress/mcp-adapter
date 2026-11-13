<?php
/**
 * Tests core abilities polyfill registration through a fresh WordPress bootstrap.
 *
 * The CI matrix runs this against WordPress versions that ship the core abilities
 * (trunk) and versions that do not (6.9, 7.0), so every assertion branches on what
 * the booted WordPress actually provides — never on a version number.
 *
 * The WordPress test framework unhooks core's own ability and category registration,
 * so on a WordPress that ships the abilities the fixture process has none registered;
 * what that path proves is that the plugin's load-time gate kept the polyfill fully
 * out and nothing misfired. The polyfilled path exercises the real registrations.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Exercises core ability polyfill timing in separate WordPress requests.
 */
final class CoreAbilitiesPolyfillBootstrapTest extends TestCase {

	private const CORE_ABILITIES = array(
		'core/settings-get',
		'core/users-query',
		'core/content-query',
	);

	/**
	 * Tests that the polyfill registers the core abilities exactly when core does not provide them.
	 */
	public function test_core_abilities_available_when_enabled(): void {
		$result = $this->bootstrap_wordpress( array( 'enabled' => true ) );

		$this->assertSame( array(), $result['notices'] );
		$this->assertSame( array(), $result['errors'] );
		// Core (7.2+) and the polyfill (before that) both flag post and page.
		$this->assertEqualsCanonicalizing( array( 'post', 'page' ), $result['abilities_post_types'] );

		if ( $result['core_provides_abilities'] ) {
			// The load-time gate must keep the polyfill fully unhooked; with core's own
			// registration stripped by the test framework, nothing registers the abilities.
			$this->assertFalse( $result['polyfill_hooked'] );
			foreach ( self::CORE_ABILITIES as $name ) {
				$this->assertNotContains( $name, $result['abilities'] );
			}

			return;
		}

		$this->assertTrue( $result['polyfill_hooked'] );
		foreach ( self::CORE_ABILITIES as $name ) {
			$this->assertContains( $name, $result['abilities'] );
		}
		foreach ( array( 'site', 'user', 'content' ) as $category ) {
			$this->assertContains( $category, $result['categories'] );
		}

		$this->assertTrue( $result['settings_get_denies_anonymous'] );
		$this->assertIsArray( $result['settings_get_admin_keys'] );
		$this->assertContains( 'title', $result['settings_get_admin_keys'] );
		$this->assertContains( 'posts_per_page', $result['settings_get_admin_keys'] );
		$this->assertTrue( $result['users_query_ok'] );
	}

	/**
	 * Tests that the kill switch disables everything the polyfill would register.
	 */
	public function test_kill_switch_disables_the_polyfill(): void {
		$result = $this->bootstrap_wordpress( array( 'enabled' => false ) );

		$this->assertSame( array(), $result['notices'] );
		$this->assertSame( array(), $result['errors'] );

		foreach ( self::CORE_ABILITIES as $name ) {
			$this->assertNotContains( $name, $result['abilities'] );
		}

		if ( $result['core_provides_abilities'] ) {
			// Core's own post-type flags are not the polyfill's and must be untouched.
			$this->assertEqualsCanonicalizing( array( 'post', 'page' ), $result['abilities_post_types'] );

			return;
		}

		$this->assertNotContains( 'content', $result['categories'] );
		$this->assertSame( array(), $result['abilities_post_types'] );
	}

	/**
	 * Runs production bootstrap with fresh singleton and registry state.
	 *
	 * @param array<string, mixed> $configuration Bootstrap scenario.
	 * @return array<string, mixed>
	 */
	private function bootstrap_wordpress( array $configuration ): array {
		$this->assertTrue( function_exists( 'proc_open' ), 'Lifecycle regression tests require proc_open().' );
		$core_test_class                      = new \ReflectionClass( \WP_UnitTestCase::class );
		$test_root                            = dirname( $core_test_class->getFileName(), 2 );
		$environment                          = getenv();
		$environment['WP_TESTS_SKIP_INSTALL'] = '1';
		$pipes                                = array();

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Fresh processes exercise the actual one-time WordPress registration hooks.
		$process = proc_open(
			array( PHP_BINARY, dirname( __DIR__ ) . '/Fixtures/CoreAbilitiesPolyfillBootstrap.php', $test_root, wp_json_encode( $configuration ) ),
			array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes,
			null,
			$environment
		);
		$this->assertIsResource( $process );
		fclose( $pipes[0] );
		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$exit_code = proc_close( $process );
		$this->assertSame( 0, $exit_code, $stdout . $stderr );
		$result = json_decode( $stdout, true );
		$this->assertIsArray( $result, $stdout . $stderr );
		return $result;
	}
}
