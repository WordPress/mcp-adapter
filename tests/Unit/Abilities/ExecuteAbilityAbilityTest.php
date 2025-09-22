<?php
/**
 * Tests for ExecuteAbilityAbility class.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Abilities;

use WP\MCP\Abilities\ExecuteAbilityAbility;
use WP\MCP\Tests\Fixtures\DummyAbility;
use WP\MCP\Tests\TestCase;

/**
 * Test ExecuteAbilityAbility functionality.
 */
final class ExecuteAbilityAbilityTest extends TestCase {

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		do_action( 'abilities_api_init' );
		DummyAbility::register_all();
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
		$result = ExecuteAbilityAbility::check_permission( array(
			'ability_name' => 'test/always-allowed',
			'parameters'   => array()
		) );

		$this->assertTrue( $result );
	}

	public function test_check_permission_with_permission_denied_ability(): void {
		$result = ExecuteAbilityAbility::check_permission( array(
			'ability_name' => 'test/permission-denied',
			'parameters'   => array()
		) );

		$this->assertFalse( $result );
	}

	public function test_check_permission_with_missing_ability_name(): void {
		$result = ExecuteAbilityAbility::check_permission( array(
			'parameters' => array()
		) );

		$this->assertFalse( $result );
	}

	public function test_check_permission_with_empty_ability_name(): void {
		$result = ExecuteAbilityAbility::check_permission( array(
			'ability_name' => '',
			'parameters'   => array()
		) );

		$this->assertFalse( $result );
	}

	public function test_check_permission_with_nonexistent_ability(): void {
		$result = ExecuteAbilityAbility::check_permission( array(
			'ability_name' => 'nonexistent/ability',
			'parameters'   => array()
		) );

		$this->assertFalse( $result );
	}

	public function test_check_permission_with_wp_error_result(): void {
		// Create a mock ability that returns WP_Error for permission check
		wp_register_ability(
			'test/wp-error-permission',
			array(
				'label'               => 'WP Error Permission Test',
				'description'         => 'Returns WP_Error for permission',
				'input_schema'        => array( 'type' => 'object' ),
				'execute_callback'    => function() { return array( 'test' => 'result' ); },
				'permission_callback' => function() { 
					return new \WP_Error( 'permission_denied', 'Custom permission error' ); 
				},
			)
		);

		$result = ExecuteAbilityAbility::check_permission( array(
			'ability_name' => 'test/wp-error-permission',
			'parameters'   => array()
		) );

		// WP_Error should be returned as-is
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'permission_denied', $result->get_error_code() );
		$this->assertEquals( 'Custom permission error', $result->get_error_message() );

		// Clean up
		wp_unregister_ability( 'test/wp-error-permission' );
	}

	public function test_execute_with_valid_ability(): void {
		$result = ExecuteAbilityAbility::execute( array(
			'ability_name' => 'test/always-allowed',
			'parameters'   => array( 'test_param' => 'test_value' )
		) );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertArrayHasKey( 'data', $result );
		$this->assertTrue( $result['success'] );

		$data = $result['data'];
		$this->assertArrayHasKey( 'ok', $data );
		$this->assertArrayHasKey( 'echo', $data );
		$this->assertTrue( $data['ok'] );
		$this->assertEquals( array( 'test_param' => 'test_value' ), $data['echo'] );
	}

	public function test_execute_with_missing_ability_name(): void {
		$result = ExecuteAbilityAbility::execute( array(
			'parameters' => array()
		) );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertFalse( $result['success'] );
		$this->assertEquals( 'Ability name is required', $result['error'] );
	}

	public function test_execute_with_empty_ability_name(): void {
		$result = ExecuteAbilityAbility::execute( array(
			'ability_name' => '',
			'parameters'   => array()
		) );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertFalse( $result['success'] );
		$this->assertEquals( 'Ability name is required', $result['error'] );
	}

	public function test_execute_with_nonexistent_ability(): void {
		$result = ExecuteAbilityAbility::execute( array(
			'ability_name' => 'nonexistent/ability',
			'parameters'   => array()
		) );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'nonexistent/ability', $result['error'] );
		$this->assertStringContainsString( 'not found', $result['error'] );
	}

	public function test_execute_with_ability_returning_wp_error(): void {
		// Create a mock ability that returns WP_Error
		wp_register_ability(
			'test/wp-error-execution',
			array(
				'label'               => 'WP Error Execution Test',
				'description'         => 'Returns WP_Error for execution',
				'input_schema'        => array( 'type' => 'object' ),
				'execute_callback'    => function() { 
					return new \WP_Error( 'execution_failed', 'Custom execution error' ); 
				},
				'permission_callback' => function() { return true; },
			)
		);

		$result = ExecuteAbilityAbility::execute( array(
			'ability_name' => 'test/wp-error-execution',
			'parameters'   => array()
		) );

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
		wp_register_ability(
			'test/exception-execution',
			array(
				'label'               => 'Exception Execution Test',
				'description'         => 'Throws exception for execution',
				'input_schema'        => array( 'type' => 'object' ),
				'execute_callback'    => function() { 
					throw new \RuntimeException( 'Test execution exception' ); 
				},
				'permission_callback' => function() { return true; },
			)
		);

		$result = ExecuteAbilityAbility::execute( array(
			'ability_name' => 'test/exception-execution',
			'parameters'   => array()
		) );

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
		$meta = $ability->get_meta();

		$this->assertIsArray( $meta );
		$this->assertArrayHasKey( 'annotations', $meta );

		$annotations = $meta['annotations'];
		$this->assertEquals( 1.0, $annotations['priority'] );
		$this->assertFalse( $annotations['readOnlyHint'] );
		$this->assertFalse( $annotations['destructiveHint'] );
		$this->assertFalse( $annotations['idempotentHint'] );
		$this->assertTrue( $annotations['openWorldHint'] );
	}
}
