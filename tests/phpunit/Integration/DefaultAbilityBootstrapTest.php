<?php
/**
 * Tests default ability registration through a fresh WordPress bootstrap.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Exercises default ability registration timing in separate WordPress requests.
 */
final class DefaultAbilityBootstrapTest extends TestCase {

	private const DEFAULT_ABILITIES = array(
		'mcp-adapter/discover-abilities',
		'mcp-adapter/get-ability-info',
		'mcp-adapter/execute-ability',
	);

	/**
	 * Tests that the default abilities register when the default server is enabled.
	 *
	 * @dataProvider provide_enabled_scenarios
	 *
	 * @param string $context Request context.
	 * @param bool   $early_registry Whether another plugin initializes the registry at init priority zero.
	 */
	public function test_default_abilities_register( string $context, bool $early_registry ): void {
		$result = $this->bootstrap_wordpress(
			array(
				'context'        => $context,
				'early_registry' => $early_registry,
				'enabled'        => true,
			)
		);

		foreach ( self::DEFAULT_ABILITIES as $name ) {
			$this->assertContains( $name, $result['abilities'] );
			if ( ! $early_registry ) {
				continue;
			}

			$this->assertContains( $name, $result['early_abilities'] );
		}
		$this->assertContains( 'mcp-adapter', $result['categories'] );
		$this->assertTrue( $result['server_created'] );
		$this->assertSame( array(), $result['notices'] );
		$this->assertSame( array(), $result['errors'] );
	}

	/**
	 * Provides lazy and early registry requests.
	 *
	 * @return array<string, array{string, bool}>
	 */
	public static function provide_enabled_scenarios(): array {
		return array(
			'lazy registry in REST'  => array( 'rest', false ),
			'early registry in REST' => array( 'rest', true ),
			'early registry in CLI'  => array( 'cli', true ),
		);
	}

	/**
	 * Tests that a theme disabling the default server also skips its abilities.
	 */
	public function test_theme_disabling_default_server_skips_default_abilities(): void {
		$result = $this->bootstrap_wordpress(
			array(
				'context'        => 'rest',
				'early_registry' => true,
				'enabled'        => false,
			)
		);

		foreach ( self::DEFAULT_ABILITIES as $name ) {
			$this->assertNotContains( $name, $result['abilities'] );
		}
		$this->assertNotContains( 'mcp-adapter', $result['categories'] );
		$this->assertFalse( $result['server_created'] );
		$this->assertSame( array(), $result['notices'] );
		$this->assertSame( array(), $result['errors'] );
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
			array( PHP_BINARY, dirname( __DIR__ ) . '/Fixtures/DefaultAbilityBootstrap.php', $test_root, wp_json_encode( $configuration ) ),
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
