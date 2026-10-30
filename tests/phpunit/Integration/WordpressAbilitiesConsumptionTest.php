<?php
/**
 * Tests the adapter consuming the `wordpress/abilities` package.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Integration;

use WP\MCP\Abilities\McpAbilityExposure;
use WP\MCP\Core\McpAdapter;
use WP\MCP\Servers\DefaultServerFactory;
use WP\MCP\Tests\TestCase;
use WP\McpSchema\Schemas;
use WordPress\Abilities\Registrar;

/**
 * Exercises the PoC wiring: the adapter registering the `wordpress/abilities`
 * package via `Registrar::register_all()` and surfacing it through the default
 * server's layered-discovery meta-tools.
 */
final class WordpressAbilitiesConsumptionTest extends TestCase {

	/**
	 * The three meta-tools, the only direct tools the default server must list.
	 *
	 * @var list<string>
	 */
	private const META_TOOLS = array(
		'mcp-adapter/discover-abilities',
		'mcp-adapter/get-ability-info',
		'mcp-adapter/execute-ability',
	);

	/**
	 * The three meta-tools as they appear on the MCP wire (slugs replace `/` with `-`).
	 *
	 * @var list<string>
	 */
	private const WIRE_META_TOOLS = array(
		'mcp-adapter-discover-abilities',
		'mcp-adapter-get-ability-info',
		'mcp-adapter-execute-ability',
	);

	/**
	 * Package abilities whose MCP exposure comes from the high-level `public` meta.
	 *
	 * @var list<string>
	 */
	private const HIGH_LEVEL_PUBLIC = array(
		'core/content-query',
		'core/content-create',
		'core/content-update',
		'core/content-delete',
		'core/settings-get',
		'core/users-query',
		'wp/settings-update',
		'wp/user-create',
		'wp/user-update',
		'wp/user-delete',
	);

	/**
	 * Package abilities that opt in through the MCP-specific `meta.mcp.public` flag.
	 *
	 * @var list<string>
	 */
	private const MCP_META_PUBLIC = array(
		'wp/media-query',
		'wp/media-upload',
		'wp/media-update',
		'wp/media-delete',
		'wp/terms-query',
		'wp/term-create',
		'wp/term-update',
		'wp/term-delete',
	);

	/**
	 * Every ability the package manifest ships.
	 *
	 * @var list<string>
	 */
	private const PACKAGE_ABILITIES = self::HIGH_LEVEL_PUBLIC + self::MCP_META_PUBLIC;

	public function setUp(): void {
		parent::setUp();

		// Reset the adapter's server registry so the default-server test starts clean,
		// mirroring the other factory/server tests.
		$reflection          = new \ReflectionClass( McpAdapter::instance() );
		$servers_property    = $reflection->getProperty( 'servers' );
		$initialized_property = $reflection->getProperty( 'initialized' );
		$servers_property->setValue( McpAdapter::instance(), array() );
		$initialized_property->setValue( null, false );
	}

	public function tearDown(): void {
		// Remove the default server created by the tools-list test.
		$reflection          = new \ReflectionClass( McpAdapter::instance() );
		$servers_property    = $reflection->getProperty( 'servers' );
		$initialized_property = $reflection->getProperty( 'initialized' );
		$servers_property->setValue( McpAdapter::instance(), array() );
		$initialized_property->setValue( null, false );

		remove_all_filters( 'mcp_adapter_default_server_config' );
		remove_all_filters( 'mcp_adapter_create_default_server' );
		remove_all_actions( 'mcp_adapter_init' );

		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Tests all 18 package abilities land in the registry with provenance meta.
	 *
	 * Samples one `core/*` and one `wp/*` copy in depth: `source`, `stability`,
	 * and `package_version` mark the package origin; the MCP-specific flag pins
	 * the open package PR's exposure opt-in.
	 */
	public function test_package_abilities_register_with_provenance_meta(): void {
		foreach ( self::PACKAGE_ABILITIES as $name ) {
			$this->assertTrue( wp_has_ability( $name ), "$name is not registered." );
		}

		$query = wp_get_ability( 'core/content-query' );
		$this->assertNotNull( $query );
		$this->assertSame( 'content', $query->get_category() );

		$query_meta = $query->get_meta();
		$this->assertSame( 'wordpress/abilities', $query_meta['source'] ?? null, 'Package abilities must be stamped with the package source.' );
		$this->assertSame( '0.1.0', $query_meta['package_version'] ?? null );
		$this->assertSame( 'core', $query_meta['stability'] ?? null );
		$this->assertTrue( $query_meta['public'] ?? false );

		$upload = wp_get_ability( 'wp/media-upload' );
		$this->assertNotNull( $upload );
		$this->assertSame( 'wordpress/abilities', $upload->get_meta()['source'] ?? null );
		$this->assertSame( 'experimental', $upload->get_meta()['stability'] ?? null );
		$this->assertSame( true, $upload->get_meta()['mcp']['public'] ?? null );
		// Core injects a default `public => false`; the package must not have opted in at that level.
		$this->assertFalse( $upload->get_meta()['public'] ?? true );
	}

	/**
	 * Tests the `content` category exists after boot without any consumer backfill.
	 *
	 * Core 7.1 registers only the `site` and `user` categories; the package's
	 * Registrar declares `content` and `media` itself during the categories init.
	 */
	public function test_package_declares_its_categories(): void {
		$this->assertTrue( wp_has_ability_category( 'content' ) );
		$this->assertTrue( wp_has_ability_category( 'media' ) );
		$this->assertTrue( wp_has_ability_category( 'site' ) );
		$this->assertTrue( wp_has_ability_category( 'user' ) );
	}

	/**
	 * Tests all 18 package abilities resolve as MCP public with the flag split intact.
	 */
	public function test_package_abilities_resolve_as_mcp_public(): void {
		foreach ( self::HIGH_LEVEL_PUBLIC as $name ) {
			$ability = wp_get_ability( $name );
			$this->assertNotNull( $ability, "$name is not registered." );
			$this->assertTrue( $ability->get_meta()['public'], "$name must carry meta.public." );
			$this->assertTrue( McpAbilityExposure::is_public( $ability ), "$name must resolve as MCP public." );
		}

		foreach ( self::MCP_META_PUBLIC as $name ) {
			$ability = wp_get_ability( $name );
			$this->assertNotNull( $ability, "$name is not registered." );
			$this->assertTrue( $ability->get_meta()['mcp']['public'] ?? false, "$name must carry meta.mcp.public." );
			// Core injects a default `public => false`; the open package PR leaves it untouched.
			$this->assertFalse( $ability->get_meta()['public'] ?? true, "$name must not change its high-level publicness." );
			$this->assertTrue( McpAbilityExposure::is_public( $ability ), "$name must resolve as MCP public." );
		}
	}

	/**
	 * Tests exposure fails closed on malformed `meta.mcp` and on absent flags.
	 */
	public function test_exposure_fails_closed(): void {
		$this->assertFalse( McpAbilityExposure::is_meta_public( array( 'mcp' => 'malformed' ) ) );
		$this->assertFalse( McpAbilityExposure::is_meta_public( array( 'mcp' => 'malformed', 'public' => true ) ) );
		$this->assertFalse( McpAbilityExposure::is_meta_public( array( 'annotations' => array( 'readonly' => true ) ) ) );
		$this->assertFalse( McpAbilityExposure::is_meta_public( array() ) );
	}

	/**
	 * Tests the default server's tools list holds exactly the three meta-tools.
	 *
	 * Package abilities surface through layered discovery instead: regressing P3
	 * (a `DefaultServerFactory` change flooding `tools/list` with the package's
	 * tools) shows up here.
	 */
	public function test_default_server_lists_exactly_the_three_meta_tools(): void {
		$server = $this->create_default_server();

		$tool_names = array_map(
			static function ( $tool ) {
				return $tool->getName();
			},
			$server->get_tools( $server->get_schemas()->forVersion( Schemas::V2025_11_25 ) )
		);

		// The exact-list pin covers P3: a package ability leaking into
		// `tools/list` (any direct auto-discovery) would break this assert.
		$this->assertSame( self::WIRE_META_TOOLS, array_values( $tool_names ) );
	}

	/**
	 * Tests `discover-abilities` lists every package ability with its identity.
	 */
	public function test_discover_abilities_lists_package_abilities(): void {
		$this->acting_as_admin();

		// The meta-ability defines no input schema: call it without input so the
		// framework skips input validation and runs the permission check directly.
		$result = wp_get_ability( 'mcp-adapter/discover-abilities' )->execute();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'abilities', $result );

		$listed = array_column( $result['abilities'], 'name' );
		foreach ( self::PACKAGE_ABILITIES as $name ) {
			$this->assertContains( $name, $listed, "$name is missing from discovery." );
		}

		foreach ( $result['abilities'] as $entry ) {
			$this->assertNotEmpty( $entry['label'], "{$entry['name']} has no label." );
			$this->assertNotEmpty( $entry['description'], "{$entry['name']} has no description." );
		}
	}

	/**
	 * Tests `get-ability-info` returns a package ability's full schema.
	 */
	public function test_get_ability_info_returns_package_ability_schema(): void {
		$this->acting_as_admin();

		$result = wp_get_ability( 'mcp-adapter/get-ability-info' )->execute(
			array( 'ability_name' => 'core/content-query' )
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'core/content-query', $result['name'] ?? null );
		$this->assertNotEmpty( $result['label'] ?? '' );
		$this->assertNotEmpty( $result['description'] ?? '' );
		$this->assertSame( 'object', $result['input_schema']['type'] ?? null );
		$this->assertNotEmpty( $result['input_schema']['properties'] ?? array() );
		$this->assertSame( 'wordpress/abilities', $result['meta']['source'] ?? null );
	}

	/**
	 * Tests `get-ability-info` refuses unlisted (not MCP-public) abilities.
	 */
	public function test_get_ability_info_fails_closed_for_non_exposed_abilities(): void {
		$this->acting_as_admin();

		// The full framework path normalizes every permission/exposure failure of
		// the meta-ability into `ability_invalid_permissions` and surfaces the
		// denial message through a doing-it-wrong notice.
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		$result = wp_get_ability( 'mcp-adapter/get-ability-info' )->execute(
			array( 'ability_name' => 'mcp-adapter/does-not-exist' )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Tests `execute-ability` runs a package read ability as an administrator.
	 */
	public function test_execute_ability_runs_package_ability(): void {
		$this->acting_as_admin();

		$result = wp_get_ability( 'mcp-adapter/execute-ability' )->execute(
			array(
				'ability_name' => 'core/settings-get',
				'parameters'   => array(),
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] ?? false, 'Executing core/settings-get failed: ' . ( $result['error'] ?? '' ) );
		$this->assertIsArray( $result['data'] ?? null );
	}

	/**
	 * Tests `execute-ability` enforces the target ability's permission beneath the adapter gate.
	 *
	 * A subscriber passes the adapter's own `read` gate but is rejected by the
	 * `manage_options` capability floor of the target ability.
	 */
	public function test_execute_ability_enforces_the_target_ability_permission(): void {
		$this->acting_as_subscriber();

		$result = wp_get_ability( 'mcp-adapter/execute-ability' )->execute(
			array(
				'ability_name' => 'wp/settings-update',
				'parameters'   => array(),
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Tests re-registering the package is idempotent: no notices, no duplicates.
	 */
	public function test_re_registration_is_idempotent(): void {
		$notices  = array();
		$listener = static function ( $function_name ) use ( &$notices ): void {
			$notices[] = $function_name;
		};
		add_filter( 'doing_it_wrong_run', $listener, 10, 1 );

		$before = wp_get_abilities();

		Registrar::register_all();
		Registrar::register( 'core/content-query' );

		remove_filter( 'doing_it_wrong_run', $listener, 10 );

		$this->assertSame( array(), $notices, 'Re-registration must not trigger doing-it-wrong notices.' );
		$this->assertCount( count( $before ), wp_get_abilities() );
		$this->assertTrue( wp_has_ability( 'core/content-query' ) );
		$this->assertSame( 'wordpress/abilities', wp_get_ability( 'core/content-query' )->get_meta()['source'] ?? null );
	}

	/**
	 * Tests a real plugin-load boot registers the package and pins the server tools.
	 *
	 * @dataProvider provide_fresh_boot_scenarios
	 *
	 * @param bool $opt_in Whether the package registration filter stays at its default.
	 */
	public function test_fresh_boot( bool $opt_in ): void {
		$result = $this->fresh_boot( $opt_in );

		$this->assertSame( array(), $result['notices'], $result['bootstrap_output'] );
		$this->assertSame( array(), $result['errors'], $result['bootstrap_output'] );
		$this->assertSame( self::WIRE_META_TOOLS, $result['default_tools'] );
		$this->assertTrue( $result['server_created'], $result['bootstrap_output'] );

		// The adapter's own trio always registers.
		foreach ( self::META_TOOLS as $name ) {
			$this->assertContains( $name, $result['abilities'] );
		}

		if ( $opt_in ) {
			$this->assertSame( self::PACKAGE_ABILITIES, array_values( array_intersect( self::PACKAGE_ABILITIES, $result['abilities'] ) ) );
			foreach ( array( 'content', 'media', 'site', 'user', 'mcp-adapter' ) as $category ) {
				$this->assertContains( $category, $result['categories'] );
			}

			return;
		}

		// Opt-out: no package registrations of any kind.
		$this->assertSame( array(), array_intersect( $result['abilities'], self::PACKAGE_ABILITIES ) );
		$this->assertSame( array(), array_intersect( $result['categories'], array( 'content', 'media' ) ) );
	}

	/**
	 * Provides the opt-in and opt-out fresh-boot scenarios.
	 *
	 * @return array<string, array{bool}>
	 */
	public static function provide_fresh_boot_scenarios(): array {
		return array(
			'default opt-in'    => array( true ),
			'filter opt-out'    => array( false ),
		);
	}

	/**
	 * Runs the adapter's default server creation in its canonical `mcp_adapter_init` window.
	 *
	 * @return \WP\MCP\Core\McpServer
	 */
	private function create_default_server(): \WP\MCP\Core\McpServer {
		global $wp_current_filter;
		$wp_current_filter[] = 'mcp_adapter_init';

		DefaultServerFactory::create();

		array_pop( $wp_current_filter );

		$server = McpAdapter::instance()->get_server( 'mcp-adapter-default-server' );
		$this->assertNotNull( $server, 'The default server was not created.' );

		return $server;
	}

	/**
	 * Switches the current user to an administrator for the rest of the test.
	 *
	 * @return int The user ID.
	 */
	private function acting_as_admin(): int {
		$user_id = self::factory()->user->create(
			array(
				'user_login' => 'abilities-consume-admin',
				'role'       => 'administrator',
			)
		);
		wp_set_current_user( $user_id );

		return $user_id;
	}

	/**
	 * Switches the current user to a subscriber for the rest of the test.
	 *
	 * @return int The user ID.
	 */
	private function acting_as_subscriber(): int {
		$user_id = self::factory()->user->create(
			array(
				'user_login' => 'abilities-consume-subscriber',
				'role'       => 'subscriber',
			)
		);
		wp_set_current_user( $user_id );

		return $user_id;
	}

	/**
	 * Runs production bootstrap in a fresh WordPress process.
	 *
	 * @param bool $opt_in Whether the package registration filter stays at its default.
	 * @return array<string, mixed>
	 */
	private function fresh_boot( bool $opt_in ): array {
		$this->assertTrue( function_exists( 'proc_open' ), 'Consumption regression tests require proc_open().' );
		$core_test_class                      = new \ReflectionClass( \WP_UnitTestCase::class );
		$test_root                            = dirname( $core_test_class->getFileName(), 2 );
		$environment                          = getenv();
		$environment['WP_TESTS_SKIP_INSTALL'] = '1';
		$pipes                                = array();

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Fresh processes exercise the actual one-time WordPress registration hooks.
		$process = proc_open(
			array( PHP_BINARY, dirname( __DIR__ ) . '/Fixtures/WordpressAbilitiesBootstrap.php', $test_root, wp_json_encode( array( 'opt_in' => $opt_in ) ) ),
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
		$result = json_decode( (string) $stdout, true );
		$this->assertIsArray( $result, $stdout . $stderr );

		return $result;
	}
}