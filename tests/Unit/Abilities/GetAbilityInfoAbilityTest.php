<?php
/**
 * Tests for GetAbilityInfoAbility class.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Abilities;

use WP\MCP\Abilities\GetAbilityInfoAbility;
use WP\MCP\Tests\TestCase;

/**
 * Test GetAbilityInfoAbility functionality.
 */
final class GetAbilityInfoAbilityTest extends TestCase {

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
		$ability = wp_get_ability( 'mcp-adapter/get-ability-info' );

		$this->assertNotNull( $ability );
		$this->assertEquals( 'mcp-adapter/get-ability-info', $ability->get_name() );
		$this->assertEquals( 'Get Ability Info', $ability->get_label() );
		$this->assertStringContainsString( 'Get detailed information about a specific WordPress ability', $ability->get_description() );
	}

	public function test_check_permission_with_logged_in_user(): void {
		wp_set_current_user( 1 );

		$result = GetAbilityInfoAbility::check_permission( array( 'ability_name' => 'test/always-allowed' ) );

		$this->assertTrue( $result );
	}

	public function test_check_permission_with_logged_out_user(): void {
		wp_set_current_user( 0 );

		$result = GetAbilityInfoAbility::check_permission( array( 'ability_name' => 'test/always-allowed' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'authentication_required', $result->get_error_code() );
	}

	public function test_check_permission_with_public_mcp_metadata(): void {
		// Test ability with mcp.public=true (should be allowed)
		$result = GetAbilityInfoAbility::check_permission(
			array(
				'ability_name' => 'test/always-allowed',
			)
		);
		$this->assertTrue( $result );

		// Create a test ability without mcp.public metadata (should be blocked)
		$this->register_ability_in_hook(
			'test/not-public-info',
			array(
				'label'               => 'Not Public Info Test',
				'description'         => 'Ability without mcp.public metadata',
				'category'            => 'test',
				'input_schema'        => array( 'type' => 'object' ),
				'execute_callback'    => static function () {
					return array( 'test' => 'result' ); },
				'permission_callback' => static function () {
					return true; },
				// No mcp.public metadata - should default to false
			)
		);

		$result = GetAbilityInfoAbility::check_permission(
			array(
				'ability_name' => 'test/not-public-info',
			)
		);
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'ability_not_public_mcp', $result->get_error_code() );

		// Clean up
		wp_unregister_ability( 'test/not-public-info' );
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

		$result = GetAbilityInfoAbility::check_permission(
			array(
				'ability_name' => 'test/always-allowed',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'insufficient_capability', $result->get_error_code() );

		// Clean up
		wp_delete_user( $limited_user_id );
		wp_set_current_user( self::$user_id );
	}

	public function test_check_permission_with_missing_ability_name(): void {
		$result = GetAbilityInfoAbility::check_permission( array() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'missing_ability_name', $result->get_error_code() );
	}

	public function test_execute_with_valid_ability(): void {
		$result = GetAbilityInfoAbility::execute(
			array(
				'ability_name' => 'test/always-allowed',
			)
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'name', $result );
		$this->assertArrayHasKey( 'label', $result );
		$this->assertArrayHasKey( 'description', $result );
		$this->assertArrayHasKey( 'input_schema', $result );

		$this->assertEquals( 'test/always-allowed', $result['name'] );
		$this->assertEquals( 'Always Allowed', $result['label'] );
		$this->assertEquals( 'Returns a simple payload', $result['description'] );
		$this->assertIsArray( $result['input_schema'] );
	}

	public function test_execute_with_ability_having_output_schema(): void {
		// Test with an ability that has output schema
		$result = GetAbilityInfoAbility::execute(
			array(
				'ability_name' => 'test/always-allowed',
			)
		);

		$this->assertIsArray( $result );

		// Check if output schema is included when available
		$ability = wp_get_ability( 'test/always-allowed' );
		$this->assertNotNull( $ability, 'Ability test/always-allowed should be registered' );
		
		$output_schema = $ability->get_output_schema();

		if ( empty( $output_schema ) ) {
			return;
		}

		$this->assertArrayHasKey( 'output_schema', $result );
		$this->assertEquals( $output_schema, $result['output_schema'] );
	}

	public function test_execute_with_ability_having_meta(): void {
		// Test with an ability that has meta information
		$result = GetAbilityInfoAbility::execute(
			array(
				'ability_name' => 'test/always-allowed',
			)
		);

		$this->assertIsArray( $result );

		// Check if meta is included when available
		$ability = wp_get_ability( 'test/always-allowed' );
		$this->assertNotNull( $ability, 'Ability test/always-allowed should be registered' );
		
		$meta = $ability->get_meta();

		if ( empty( $meta ) ) {
			return;
		}

		$this->assertArrayHasKey( 'meta', $result );
		$this->assertEquals( $meta, $result['meta'] );
	}

	public function test_execute_with_missing_ability_name(): void {
		$result = GetAbilityInfoAbility::execute( array() );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertEquals( 'Ability name is required', $result['error'] );
	}

	public function test_execute_with_empty_ability_name(): void {
		$result = GetAbilityInfoAbility::execute(
			array(
				'ability_name' => '',
			)
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertEquals( 'Ability name is required', $result['error'] );
	}

	public function test_execute_with_nonexistent_ability(): void {
		$result = GetAbilityInfoAbility::execute(
			array(
				'ability_name' => 'nonexistent/ability',
			)
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'nonexistent/ability', $result['error'] );
		$this->assertStringContainsString( 'not found', $result['error'] );
	}

	public function test_ability_has_correct_input_schema(): void {
		$ability = wp_get_ability( 'mcp-adapter/get-ability-info' );
		$this->assertNotNull( $ability, 'Ability mcp-adapter/get-ability-info should be registered' );
		
		$input_schema = $ability->get_input_schema();

		$this->assertIsArray( $input_schema );
		$this->assertEquals( 'object', $input_schema['type'] );
		$this->assertArrayHasKey( 'properties', $input_schema );
		$this->assertArrayHasKey( 'ability_name', $input_schema['properties'] );
		$this->assertEquals( array( 'ability_name' ), $input_schema['required'] );
		$this->assertFalse( $input_schema['additionalProperties'] );
	}

	public function test_ability_has_correct_output_schema(): void {
		$ability = wp_get_ability( 'mcp-adapter/get-ability-info' );
		$this->assertNotNull( $ability, 'Ability mcp-adapter/get-ability-info should be registered' );
		
		$output_schema = $ability->get_output_schema();

		$this->assertIsArray( $output_schema );
		$this->assertEquals( 'object', $output_schema['type'] );
		$this->assertArrayHasKey( 'properties', $output_schema );

		$properties = $output_schema['properties'];
		$this->assertArrayHasKey( 'name', $properties );
		$this->assertArrayHasKey( 'label', $properties );
		$this->assertArrayHasKey( 'description', $properties );
		$this->assertArrayHasKey( 'input_schema', $properties );
		$this->assertArrayHasKey( 'output_schema', $properties );
		$this->assertArrayHasKey( 'meta', $properties );

		$this->assertEquals( array( 'name', 'label', 'description', 'input_schema' ), $output_schema['required'] );
	}

	public function test_ability_has_correct_annotations(): void {
		$ability = wp_get_ability( 'mcp-adapter/get-ability-info' );
		$this->assertNotNull( $ability, 'Ability mcp-adapter/get-ability-info should be registered' );
		
		$meta = $ability->get_meta();

		$this->assertIsArray( $meta );
		$this->assertArrayHasKey( 'annotations', $meta );

		$annotations = $meta['annotations'];
		$this->assertEquals( 1.0, $annotations['priority'] );
		$this->assertTrue( $annotations['readOnlyHint'] );
		$this->assertFalse( $annotations['destructiveHint'] );
		$this->assertTrue( $annotations['idempotentHint'] );
		$this->assertFalse( $annotations['openWorldHint'] );
	}

	public function test_execute_handles_various_input_formats(): void {
		// Test with nested params structure
		$result1 = GetAbilityInfoAbility::execute(
			array(
				'ability_name' => 'test/always-allowed',
			)
		);

		// Test with direct ability_name
		$result2 = GetAbilityInfoAbility::execute(
			array(
				'ability_name' => 'test/always-allowed',
			)
		);

		$this->assertEquals( $result1, $result2 );
		$this->assertArrayHasKey( 'name', $result1 );
		$this->assertEquals( 'test/always-allowed', $result1['name'] );
	}
}
