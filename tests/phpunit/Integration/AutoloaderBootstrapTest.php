<?php
/**
 * Tests canonical plugin selection alongside a Jetpack-based bundler.
 *
 * @package WP\MCP\Tests
 * @since n.e.x.t
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Integration;

use PHPUnit\Framework\TestCase;
use WP\MCP\Core\McpAdapter;

/**
 * Exercises real plugin loading in fresh WordPress processes.
 *
 * @since n.e.x.t
 */
final class AutoloaderBootstrapTest extends TestCase {

	/**
	 * The canonical plugin supplies its classes even when a bundler registers first.
	 *
	 * @dataProvider provide_canonical_scenarios
	 * @param string $scenario Whether Jetpack can initially discover the canonical plugin.
	 * @since n.e.x.t
	 */
	public function test_canonical_plugin_wins_before_a_bundled_class_is_loaded( string $scenario ): void {
		$result = $this->bootstrap_wordpress( $scenario );

		$this->assertFalse( $result['before_class_loaded'] );
		$this->assertSame( McpAdapter::VERSION, $result['version'] );
		$this->assertSame( $result['canonical_directory'] . 'includes/Core/McpAdapter.php', $result['class_file'] );
		$this->assertSame( $result['canonical_directory'], $result['directory'] );
		$this->assertTrue( $result['plugin_loaded'] );
		$this->assertSame( array(), $result['notices'] );
	}

	/**
	 * Supplies ordinary activation and host-loaded plugin scenarios.
	 *
	 * @return array<string, array{string}>
	 * @since n.e.x.t
	 */
	public static function provide_canonical_scenarios(): array {
		return array(
			'ordinary active plugin' => array( 'discoverable' ),
			'host-loaded plugin'     => array( 'undiscovered' ),
		);
	}

	/**
	 * A class that PHP has already loaded cannot be replaced by version arbitration.
	 *
	 * @since n.e.x.t
	 */
	public function test_already_loaded_bundled_class_is_preserved_and_reported(): void {
		$result = $this->bootstrap_wordpress( 'preloaded' );

		$this->assertSame( '0.6.1', $result['version'] );
		$this->assertStringContainsString( '/mcp-adapter-bundler-', $result['class_file'] );
		$this->assertNull( $result['directory'] );
		$this->assertCount( 1, $result['notices'] );
		$this->assertStringContainsString( 'Another version of MCP Adapter is already loaded', $result['notices'][0] );
	}

	/**
	 * Boots WordPress without carrying loaded Adapter classes into the child process.
	 *
	 * @param string $scenario Plugin discovery and class loading scenario.
	 * @return array<string, mixed>
	 * @since n.e.x.t
	 */
	private function bootstrap_wordpress( string $scenario ): array {
		$this->assertTrue( function_exists( 'proc_open' ), 'Autoloader regression tests require proc_open().' );
		$core_test_class                      = new \ReflectionClass( \WP_UnitTestCase::class );
		$test_root                            = dirname( $core_test_class->getFileName(), 2 );
		$environment                          = getenv();
		$environment['WP_TESTS_SKIP_INSTALL'] = '1';
		$pipes                                = array();

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Loaded PHP classes cannot be reset within the PHPUnit process.
		$process = proc_open(
			array( PHP_BINARY, dirname( __DIR__ ) . '/Fixtures/AutoloaderBootstrap.php', $test_root, $scenario ),
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
		$this->assertSame( 0, proc_close( $process ), $stdout . $stderr );
		$result = json_decode( $stdout, true );
		$this->assertIsArray( $result, $stdout . $stderr );
		return $result;
	}
}
