<?php
/**
 * Tests for ExecuteAbilityAbility class.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Abilities;

use WP\MCP\Abilities\ExecuteAbilityAbility;
use WP\MCP\Tests\TestCase;

/**
 * Test ExecuteAbilityAbility functionality.
 */
final class ExecuteAbilityAbilityTest extends TestCase {

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
		$ability = wp_get_ability( 'mcp-adapter/execute-ability' );

		$this->assertNotNull( $ability );
		$this->assertEquals( 'mcp-adapter/execute-ability', $ability->get_name() );
		$this->assertEquals( 'Execute Ability', $ability->get_label() );
		$this->assertStringContainsString( 'Execute a WordPress ability with the provided parameters', $ability->get_description() );
	}

	public function test_check_permission_with_valid_ability(): void {
		$result = ExecuteAbilityAbility::check_permission(
			array(
				'ability_name' => 'test/always-allowed',
				'parameters'   => array(),
			)
		);

		$this->assertTrue( $result );
	}

	public function test_check_permission_with_permission_denied_ability(): void {
		$result = ExecuteAbilityAbility::check_permission(
			array(
				'ability_name' => 'test/permission-denied',
				'parameters'   => array(),
			)
		);

		$this->assertFalse( $result );
	}

	public function test_check_permission_with_missing_ability_name(): void {
		$result = ExecuteAbilityAbility::check_permission(
			array(
				'parameters' => array(),
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'missing_ability_name', $result->get_error_code() );
	}

	public function test_check_permission_with_empty_ability_name(): void {
		$result = ExecuteAbilityAbility::check_permission(
			array(
				'ability_name' => '',
				'parameters'   => array(),
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'missing_ability_name', $result->get_error_code() );
	}

	public function test_check_permission_with_nonexistent_ability(): void {
		$result = ExecuteAbilityAbility::check_permission(
			array(
				'ability_name' => 'nonexistent/ability',
				'parameters'   => array(),
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'ability_not_found', $result->get_error_code() );
	}

	public function test_check_permission_with_wp_error_result(): void {
		// Create a mock ability that returns WP_Error for permission check
		$this->register_ability_in_hook(
			'test/wp-error-permission',
			array(
				'label'               => 'WP Error Permission Test',
				'description'         => 'Returns WP_Error for permission',
				'category'            => 'test',
				'execute_callback'    => static function () {
					return array( 'test' => 'result' ); },
				'permission_callback' => static function () {
					return new \WP_Error( 'permission_denied', 'Custom permission error' );
				},
				'meta'                => array(
					'mcp' => array(
						'public' => true, // Expose via MCP for testing
					),
				),
			)
		);

		$result = ExecuteAbilityAbility::check_permission(
			array(
				'ability_name' => 'test/wp-error-permission',
				'parameters'   => array(),
			)
		);

		// WP_Error should be returned as-is
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'permission_denied', $result->get_error_code() );
		$this->assertEquals( 'Custom permission error', $result->get_error_message() );

		// Clean up
		wp_unregister_ability( 'test/wp-error-permission' );
	}

	public function test_check_permission_requires_authentication(): void {
		// Test with no authenticated user
		wp_set_current_user( 0 );

		$result = ExecuteAbilityAbility::check_permission(
			array(
				'ability_name' => 'test/always-allowed',
				'parameters'   => array(),
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'authentication_required', $result->get_error_code() );

		// Restore authenticated user for other tests
		wp_set_current_user( self::$user_id );
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

		$result = ExecuteAbilityAbility::check_permission(
			array(
				'ability_name' => 'test/always-allowed',
				'parameters'   => array(),
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'insufficient_capability', $result->get_error_code() );

		// Clean up
		wp_delete_user( $limited_user_id );
		wp_set_current_user( self::$user_id );
	}

	public function test_check_permission_with_public_mcp_metadata(): void {
		// Test ability with mcp.public=true (should be allowed)
		$result = ExecuteAbilityAbility::check_permission(
			array(
				'ability_name' => 'test/always-allowed',
				'parameters'   => array(),
			)
		);
		$this->assertTrue( $result );

		// Create a test ability without mcp.public metadata (should be blocked)
		$this->register_ability_in_hook(
			'test/not-public-mcp',
			array(
				'label'               => 'Not Public MCP Test',
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

		$result = ExecuteAbilityAbility::check_permission(
			array(
				'ability_name' => 'test/not-public-mcp',
				'parameters'   => array(),
			)
		);
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'ability_not_public_mcp', $result->get_error_code() );

		// Clean up
		wp_unregister_ability( 'test/not-public-mcp' );
	}

	public function test_check_permission_with_nonexistent_ability_for_mcp_check(): void {
		// Test with an ability that doesn't exist (should fail at MCP exposure check)
		$result = ExecuteAbilityAbility::check_permission(
			array(
				'ability_name' => 'nonexistent/test-ability',
				'parameters'   => array(),
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'ability_not_found', $result->get_error_code() );
	}

	public function test_execute_with_valid_ability(): void {
		$result = ExecuteAbilityAbility::execute(
			array(
				'ability_name' => 'test/always-allowed',
				'parameters'   => array(),
			)
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertArrayHasKey( 'data', $result );
		$this->assertTrue( $result['success'] );

		$data = $result['data'];
		$this->assertArrayHasKey( 'ok', $data );
		$this->assertArrayHasKey( 'echo', $data );
		$this->assertTrue( $data['ok'] );
		$this->assertEquals( array(), $data['echo'] );
	}

	public function test_execute_with_missing_ability_name(): void {
		$result = ExecuteAbilityAbility::execute(
			array(
				'parameters' => array(),
			)
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertFalse( $result['success'] );
		$this->assertEquals( 'Ability name is required', $result['error'] );
	}

	public function test_execute_with_empty_ability_name(): void {
		$result = ExecuteAbilityAbility::execute(
			array(
				'ability_name' => '',
				'parameters'   => array(),
			)
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertFalse( $result['success'] );
		$this->assertEquals( 'Ability name is required', $result['error'] );
	}

	public function test_execute_with_nonexistent_ability(): void {
		$result = ExecuteAbilityAbility::execute(
			array(
				'ability_name' => 'nonexistent/ability',
				'parameters'   => array(),
			)
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'nonexistent/ability', $result['error'] );
		$this->assertStringContainsString( 'not found', $result['error'] );
	}

	public function test_execute_with_ability_returning_wp_error(): void {
		// Create a mock ability that returns WP_Error
		$this->register_ability_in_hook(
			'test/wp-error-execution',
			array(
				'label'               => 'WP Error Execution Test',
				'description'         => 'Returns WP_Error for execution',
				'category'            => 'test',
				'execute_callback'    => static function () {
					return new \WP_Error( 'execution_failed', 'Custom execution error' );
				},
				'permission_callback' => static function () {
					return true; },
				'meta'                => array(
					'mcp' => array(
						'public' => true, // Expose via MCP for testing
					),
				),
			)
		);

		$result = ExecuteAbilityAbility::execute(
			array(
				'ability_name' => 'test/wp-error-execution',
				'parameters'   => array(),
			)
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertFalse( $result['success'] );
		$this->assertEquals( 'Custom execution error', $result['error'] );

		// Clean up
		wp_unregister_ability( 'test/wp-error-execution' );
	}

	public function test_execute_with_ability_throwing_exception(): void {
		// Create a mock ability that throws exception
		$this->register_ability_in_hook(
			'test/exception-execution',
			array(
				'label'               => 'Exception Execution Test',
				'description'         => 'Throws exception for execution',
				'category'            => 'test',
				'execute_callback'    => static function () {
					throw new \RuntimeException( 'Test execution exception' );
				},
				'permission_callback' => static function () {
					return true; },
				'meta'                => array(
					'mcp' => array(
						'public' => true, // Expose via MCP for testing
					),
				),
			)
		);

		$result = ExecuteAbilityAbility::execute(
			array(
				'ability_name' => 'test/exception-execution',
				'parameters'   => array(),
			)
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertFalse( $result['success'] );
		$this->assertEquals( 'Test execution exception', $result['error'] );

		// Clean up
		wp_unregister_ability( 'test/exception-execution' );
	}

	public function test_ability_has_correct_schema(): void {
		$ability = wp_get_ability( 'mcp-adapter/execute-ability' );

		$input_schema = $ability->get_input_schema();
		$this->assertIsArray( $input_schema );
		$this->assertEquals( 'object', $input_schema['type'] );
		$this->assertArrayHasKey( 'properties', $input_schema );
		$this->assertArrayHasKey( 'ability_name', $input_schema['properties'] );
		$this->assertArrayHasKey( 'parameters', $input_schema['properties'] );
		$this->assertEquals( array( 'ability_name', 'parameters' ), $input_schema['required'] );

		$output_schema = $ability->get_output_schema();
		$this->assertIsArray( $output_schema );
		$this->assertEquals( 'object', $output_schema['type'] );
		$this->assertArrayHasKey( 'properties', $output_schema );
		$this->assertArrayHasKey( 'success', $output_schema['properties'] );
		$this->assertEquals( array( 'success' ), $output_schema['required'] );
	}

	public function test_ability_has_correct_annotations(): void {
		$ability = wp_get_ability( 'mcp-adapter/execute-ability' );
		$meta    = $ability->get_meta();

		$this->assertIsArray( $meta );
		$this->assertArrayHasKey( 'annotations', $meta );

		$annotations = $meta['annotations'];
		$this->assertEquals( 1.0, $annotations['priority'] );
		$this->assertFalse( $annotations['readOnlyHint'] );
		$this->assertTrue( $annotations['openWorldHint'] );
	}
}
