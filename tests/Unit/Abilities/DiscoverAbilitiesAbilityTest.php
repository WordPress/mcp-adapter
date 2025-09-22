<?php
/**
 * Tests for DiscoverAbilitiesAbility class.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Abilities;

use WP\MCP\Abilities\DiscoverAbilitiesAbility;
use WP\MCP\Tests\Fixtures\DummyAbility;
use WP\MCP\Tests\TestCase;

/**
 * Test DiscoverAbilitiesAbility functionality.
 */
final class DiscoverAbilitiesAbilityTest extends TestCase {

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		do_action( 'abilities_api_init' );
		DummyAbility::register_all();
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

		$this->assertFalse( $result );
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
		$ability_names = array_column( $result['abilities'], 'name' );
		$mcp_adapter_abilities = array_filter( $ability_names, function( $name ) {
			return str_starts_with( $name, 'mcp-adapter/' );
		} );

		$this->assertEmpty( $mcp_adapter_abilities, 'Should not include self-referencing mcp-adapter abilities' );
	}

	public function test_execute_includes_test_abilities(): void {
		$result = DiscoverAbilitiesAbility::execute( array() );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'abilities', $result );

		// Check that test abilities are included
		$ability_names = array_column( $result['abilities'], 'name' );
		$this->assertContains( 'test/always-allowed', $ability_names );
		$this->assertContains( 'test/resource', $ability_names );
		$this->assertContains( 'test/prompt', $ability_names );
	}

	public function test_execute_with_empty_input(): void {
		// Should work with empty input
		$result = DiscoverAbilitiesAbility::execute( array() );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'abilities', $result );
	}

	public function test_execute_ignores_input_parameters(): void {
		// Should ignore any input parameters since it discovers all abilities
		$result = DiscoverAbilitiesAbility::execute( array(
			'filter' => 'some-filter',
			'limit'  => 10,
			'unused' => 'parameter'
		) );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'abilities', $result );
		$this->assertNotEmpty( $result['abilities'] );
	}

	public function test_ability_has_correct_schema(): void {
		$ability = wp_get_ability( 'mcp-adapter/discover-abilities' );

		$input_schema = $ability->get_input_schema();
		$this->assertIsArray( $input_schema );
		$this->assertEquals( 'object', $input_schema['type'] );
		$this->assertFalse( $input_schema['additionalProperties'] );

		$output_schema = $ability->get_output_schema();
		$this->assertIsArray( $output_schema );
		$this->assertEquals( 'object', $output_schema['type'] );
		$this->assertArrayHasKey( 'properties', $output_schema );
		$this->assertArrayHasKey( 'abilities', $output_schema['properties'] );
		$this->assertEquals( array( 'abilities' ), $output_schema['required'] );
	}

	public function test_ability_has_correct_annotations(): void {
		$ability = wp_get_ability( 'mcp-adapter/discover-abilities' );
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
}
