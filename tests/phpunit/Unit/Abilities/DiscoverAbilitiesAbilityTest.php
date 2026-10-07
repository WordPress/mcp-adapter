<?php
/**
 * Tests for DiscoverAbilitiesAbility class.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Abilities;

use WP\MCP\Abilities\DiscoverAbilitiesAbility;
use WP\MCP\Abilities\McpAbilityExposure;
use WP\MCP\Tests\TestCase;
use WP_Error;

/**
 * Test DiscoverAbilitiesAbility functionality.
 */
final class DiscoverAbilitiesAbilityTest extends TestCase {

	/**
	 * User ID for authenticated tests.
	 *
	 * @var int
	 */
	private int $user_id;

	public function setUp(): void {
		parent::setUp();
		// Create a test user for authentication tests
		$this->user_id = self::factory()->user->create(
			array(
				'user_login' => 'testuser',
				'user_pass'  => 'testpass',
				'user_email' => 'test@example.com',
				'role'       => 'administrator',
			)
		);
		wp_set_current_user( $this->user_id );
	}

	public function tearDown(): void {
		// Reset current user after each test
		wp_set_current_user( 0 );
		wp_delete_user( $this->user_id );
		parent::tearDown();
	}

	public function test_register_creates_ability(): void {
		// The ability should already be registered by parent class
		$ability = wp_get_ability( 'mcp-adapter/discover-abilities' );

		$this->assertNotNull( $ability );
		$this->assertEquals( 'mcp-adapter/discover-abilities', $ability->get_name() );
		$this->assertEquals( 'Discover Abilities', $ability->get_label() );
		$this->assertStringContainsString( 'Discover all available WordPress abilities', $ability->get_description() );
	}

	public function test_check_permission_with_logged_in_user(): void {
		wp_set_current_user( 1 );

		$result = DiscoverAbilitiesAbility::check_permission( array() );

		$this->assertTrue( $result );
	}

	public function test_check_permission_with_logged_out_user(): void {
		wp_set_current_user( 0 );

		$result = DiscoverAbilitiesAbility::check_permission( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'authentication_required', $result->get_error_code() );
	}

	public function test_execute_with_public_mcp_filtering(): void {
		$result = DiscoverAbilitiesAbility::execute( array() );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'abilities', $result );
		$this->assertIsArray( $result['abilities'] );

		// Should only contain abilities with mcp.public=true
		$result = array_column( $result['abilities'], 'name' );
		$this->assertContains( 'test/always-allowed', $result );

		// test/permission-denied has mcp.public=true, so it should be included
		$this->assertContains( 'test/permission-denied', $result );

		// Create an ability without mcp.public and verify it's not included
		$this->register_ability_in_hook(
			'test/not-public',
			array(
				'label'               => 'Not Public Test',
				'description'         => 'Should not appear in discovery',
				'category'            => 'test',
				'input_schema'        => array( 'type' => 'object' ),
				'execute_callback'    => static function () {
					return array(); },
				'permission_callback' => static function () {
					return true; },
				// No mcp.public metadata
			)
		);

		$result2        = DiscoverAbilitiesAbility::execute( array() );
		$ability_names2 = array_column( $result2['abilities'], 'name' );
		$this->assertNotContains( 'test/not-public', $ability_names2 );

		// Clean up
		wp_unregister_ability( 'test/not-public' );
	}

	/**
	 * @dataProvider data_public_exposure
	 *
	 * @param array<string, mixed> $meta                Ability metadata.
	 * @param bool                 $expected_mcp_public Expected resolved MCP exposure.
	 */
	public function test_execute_inherits_public_exposure_and_respects_mcp_overrides(
		array $meta,
		bool $expected_mcp_public
	): void {
		$ability_name = 'test/public-exposure';

		$this->register_ability_in_hook(
			$ability_name,
			array(
				'label'               => 'Public Exposure Test',
				'description'         => 'Tests resolved MCP exposure metadata.',
				'category'            => 'test',
				'execute_callback'    => static function () {
					return array();
				},
				'permission_callback' => '__return_true',
				'meta'                => $meta,
			)
		);

		$registered_ability = wp_get_ability( $ability_name );
		$this->assertNotNull( $registered_ability );

		$resolved_mcp_public = McpAbilityExposure::is_public( $registered_ability );
		$result              = DiscoverAbilitiesAbility::execute( array() );
		$ability_names       = array_column( $result['abilities'], 'name' );

		wp_unregister_ability( $ability_name );

		$this->assertSame( $expected_mcp_public, $resolved_mcp_public );

		if ( $expected_mcp_public ) {
			$this->assertContains( $ability_name, $ability_names );
		} else {
			$this->assertNotContains( $ability_name, $ability_names );
		}
	}

	/**
	 * @return array<string, array{
	 *     meta: array<string, mixed>,
	 *     expected_mcp_public: bool
	 * }>
	 */
	public function data_public_exposure(): array {
		return array(
			'public ability inherits MCP exposure'  => array(
				'meta'                => array(
					'public' => true,
				),
				'expected_mcp_public' => true,
			),
			'explicit MCP opt-out takes precedence' => array(
				'meta'                => array(
					'public' => true,
					'mcp'    => array(
						'public' => false,
					),
				),
				'expected_mcp_public' => false,
			),
			'explicit MCP opt-in takes precedence'  => array(
				'meta'                => array(
					'public' => false,
					'mcp'    => array(
						'public' => true,
					),
				),
				'expected_mcp_public' => true,
			),
			'MCP-only opt-in does not require public metadata' => array(
				'meta'                => array(
					'mcp' => array(
						'public' => true,
					),
				),
				'expected_mcp_public' => true,
			),
			'null MCP exposure inherits the public setting' => array(
				'meta'                => array(
					'public' => true,
					'mcp'    => array(
						'public' => null,
					),
				),
				'expected_mcp_public' => true,
			),
			'malformed MCP metadata fails closed'   => array(
				'meta'                => array(
					'public' => true,
					'mcp'    => 'invalid',
				),
				'expected_mcp_public' => false,
			),
		);
	}

	/**
	 * @dataProvider data_late_public_exposure_changes
	 *
	 * @param bool $registered_public Initial `meta.public` value passed at registration.
	 * @param bool $filtered_public   Final `meta.public` value set by a later filter.
	 * @param int  $filter_priority   Priority the competing filter registers at.
	 */
	public function test_execute_resolves_exposure_from_late_filtered_metadata(
		bool $registered_public,
		bool $filtered_public,
		int $filter_priority
	): void {
		$ability_name = 'test/late-filtered-exposure';

		$late_filter = static function ( array $args, string $name ) use ( $ability_name, $filtered_public ): array {
			if ( $ability_name === $name ) {
				$args['meta']['public'] = $filtered_public;
			}

			return $args;
		};
		add_filter( 'wp_register_ability_args', $late_filter, $filter_priority, 2 );

		$this->register_ability_in_hook(
			$ability_name,
			array(
				'label'               => 'Late Filtered Exposure Test',
				'description'         => 'Tests exposure resolution after later filters run.',
				'category'            => 'test',
				'execute_callback'    => static function () {
					return array();
				},
				'permission_callback' => '__return_true',
				'meta'                => array(
					'public' => $registered_public,
				),
			)
		);

		$registered_ability = wp_get_ability( $ability_name );
		$this->assertNotNull( $registered_ability );

		$resolved_mcp_public = McpAbilityExposure::is_public( $registered_ability );
		$result              = DiscoverAbilitiesAbility::execute( array() );
		$ability_names       = array_column( $result['abilities'], 'name' );

		remove_filter( 'wp_register_ability_args', $late_filter, $filter_priority );
		wp_unregister_ability( $ability_name );

		if ( $filtered_public ) {
			$this->assertTrue( $resolved_mcp_public );
			$this->assertContains( $ability_name, $ability_names );
		} else {
			$this->assertFalse( $resolved_mcp_public );
			$this->assertNotContains( $ability_name, $ability_names );
		}
	}

	/**
	 * @return array<string, array{
	 *     registered_public: bool,
	 *     filtered_public: bool,
	 *     filter_priority: int
	 * }>
	 */
	public function data_late_public_exposure_changes(): array {
		return array(
			'late opt-out overrides registered public exposure' => array(
				'registered_public' => true,
				'filtered_public'   => false,
				'filter_priority'   => 20,
			),
			'late opt-in overrides registered private exposure' => array(
				'registered_public' => false,
				'filtered_public'   => true,
				'filter_priority'   => 20,
			),
			// A competing callback registered at the same priority still runs
			// last, because WordPress orders same-priority callbacks by
			// registration order. No priority can win this, so exposure must
			// not be resolved while the ability is being registered.
			'same-priority opt-out still wins'     => array(
				'registered_public' => true,
				'filtered_public'   => false,
				'filter_priority'   => PHP_INT_MAX,
			),
			'same-priority opt-in still wins'      => array(
				'registered_public' => false,
				'filtered_public'   => true,
				'filter_priority'   => PHP_INT_MAX,
			),
		);
	}

	public function test_check_permission_requires_capability(): void {
		// Create a user with no role (no capabilities)
		$limited_user_id = wp_insert_user(
			array(
				'user_login' => 'limiteduser',
				'user_pass'  => 'testpass',
				'user_email' => 'limited@example.com',
			)
		);

		// Explicitly remove all capabilities
		$user = new \WP_User( $limited_user_id );
		$user->set_role( '' ); // Remove all roles
		$user->remove_all_caps();

		wp_set_current_user( $limited_user_id );

		$result = DiscoverAbilitiesAbility::check_permission( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'insufficient_capability', $result->get_error_code() );

		// Clean up
		wp_delete_user( $limited_user_id );
		wp_set_current_user( $this->user_id );
	}

	public function test_execute_returns_abilities_list(): void {
		$result = DiscoverAbilitiesAbility::execute( array() );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'abilities', $result );
		$this->assertIsArray( $result['abilities'] );
		$this->assertNotEmpty( $result['abilities'] );

		// Check structure of first ability
		$first_ability = $result['abilities'][0];
		$this->assertArrayHasKey( 'name', $first_ability );
		$this->assertArrayHasKey( 'label', $first_ability );
		$this->assertArrayHasKey( 'description', $first_ability );
		$this->assertIsString( $first_ability['name'] );
		$this->assertIsString( $first_ability['label'] );
		$this->assertIsString( $first_ability['description'] );
	}

	public function test_execute_excludes_mcp_adapter_abilities(): void {
		$result = DiscoverAbilitiesAbility::execute( array() );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'abilities', $result );

		// Check that no abilities starting with 'mcp-adapter/' are included
		$ability_names         = array_column( $result['abilities'], 'name' );
		$mcp_adapter_abilities = array_filter(
			$ability_names,
			static function ( $name ) {
				return str_starts_with( $name, 'mcp-adapter/' );
			}
		);

		$this->assertEmpty( $mcp_adapter_abilities, 'Should not include self-referencing mcp-adapter abilities' );
	}

	public function test_execute_includes_test_abilities(): void {
		$result = DiscoverAbilitiesAbility::execute( array() );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'abilities', $result );

		// Check that test tool abilities are included
		$ability_names = array_column( $result['abilities'], 'name' );
		$this->assertContains( 'test/always-allowed', $ability_names );

		// Resources and prompts should NOT be included (only tools are discovered)
		$this->assertNotContains( 'test/resource', $ability_names );
		$this->assertNotContains( 'test/prompt', $ability_names );
	}

	public function test_execute_with_empty_input(): void {
		// Should work with empty input
		$result = DiscoverAbilitiesAbility::execute( array() );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'abilities', $result );
	}

	public function test_execute_ignores_input_parameters(): void {
		// Should ignore any input parameters since it discovers all abilities
		$result = DiscoverAbilitiesAbility::execute(
			array(
				'filter' => 'some-filter',
				'limit'  => 10,
				'unused' => 'parameter',
			)
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'abilities', $result );
		$this->assertNotEmpty( $result['abilities'] );
	}

	public function test_ability_has_correct_schema(): void {
		$ability = wp_get_ability( 'mcp-adapter/discover-abilities' );

		$input_schema = $ability->get_input_schema();
		$this->assertIsArray( $input_schema );
		$this->assertEmpty( $input_schema );

		$output_schema = $ability->get_output_schema();
		$this->assertIsArray( $output_schema );
		$this->assertEquals( 'object', $output_schema['type'] );
		$this->assertArrayHasKey( 'properties', $output_schema );
		$this->assertArrayHasKey( 'abilities', $output_schema['properties'] );
		$this->assertEquals( array( 'abilities' ), $output_schema['required'] );
	}

	public function test_ability_has_correct_annotations(): void {
		$ability = wp_get_ability( 'mcp-adapter/discover-abilities' );
		$meta    = $ability->get_meta();

		$this->assertIsArray( $meta );
		$this->assertArrayHasKey( 'annotations', $meta );

		$annotations = $meta['annotations'];
		$this->assertTrue( $annotations['readonly'] );
		$this->assertFalse( $annotations['destructive'] );
		$this->assertTrue( $annotations['idempotent'] );
	}
}
