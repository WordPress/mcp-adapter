<?php
/**
 * Tests for DiscoverAbilitiesAbility class.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Abilities;

use WP\MCP\Abilities\DiscoverAbilitiesAbility;
use WP\MCP\Tests\TestCase;

/**
 * Test DiscoverAbilitiesAbility functionality.
 */
final class DiscoverAbilitiesAbilityTest extends TestCase {

	/**
	 * User ID for authenticated tests.
	 *
	 * @var int
	 */
	private static $user_id;

	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		// Create a test user for authentication tests
		self::$user_id = wp_insert_user(
			array(
				'user_login' => 'testuser',
				'user_pass'  => 'testpass',
				'user_email' => 'test@example.com',
				'role'       => 'administrator',
			)
		);
	}

	public static function tear_down_after_class(): void {
		// Clean up test user
		if ( self::$user_id ) {
			wp_delete_user( self::$user_id );
		}
		parent::tear_down_after_class();
	}

	public function set_up(): void {
		parent::set_up();
		// Set current user for each test
		wp_set_current_user( self::$user_id );
	}

	public function tear_down(): void {
		// Reset current user after each test
		wp_set_current_user( 0 );
		parent::tear_down();
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

		$this->assertInstanceOf( \WP_Error::class, $result );
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

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'insufficient_capability', $result->get_error_code() );

		// Clean up
		wp_delete_user( $limited_user_id );
		wp_set_current_user( self::$user_id );
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
		$this->assertEquals( 1.0, $annotations['priority'] );
		$this->assertTrue( $annotations['readOnlyHint'] );
		$this->assertFalse( $annotations['destructiveHint'] );
		$this->assertTrue( $annotations['idempotentHint'] );
		$this->assertFalse( $annotations['openWorldHint'] );
	}
}
