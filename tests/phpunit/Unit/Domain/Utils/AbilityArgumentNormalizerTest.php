<?php
/**
 * Tests for AbilityArgumentNormalizer class.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Domain\Utils;

use WP\MCP\Domain\Utils\AbilityArgumentNormalizer;
use WP\MCP\Tests\TestCase;

/**
 * Test AbilityArgumentNormalizer functionality.
 *
 * The normalizer converts empty arrays to null for abilities without input schemas.
 * This handles MCP protocol compatibility where clients send {} for parameterless tools.
 */
final class AbilityArgumentNormalizerTest extends TestCase {

	/**
	 * Test that empty array is normalized to null when ability has empty input schema.
	 */
	public function test_empty_array_normalized_to_null_when_empty_input_schema(): void {
		$ability = $this->create_ability_mock( array() );

		$result = AbilityArgumentNormalizer::normalize( $ability, array() );

		$this->assertNull( $result );
	}

	/**
	 * Test that empty array is preserved when ability has an input schema.
	 */
	public function test_empty_array_preserved_when_ability_has_input_schema(): void {
		$ability = $this->create_ability_mock(
			array(
				'type'       => 'object',
				'properties' => array(
					'name' => array( 'type' => 'string' ),
				),
			)
		);

		$result = AbilityArgumentNormalizer::normalize( $ability, array() );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * Test that non-empty parameters are passed through unchanged regardless of schema.
	 */
	public function test_non_empty_parameters_passed_through_with_schema(): void {
		$ability    = $this->create_ability_mock(
			array(
				'type'       => 'object',
				'properties' => array(
					'name' => array( 'type' => 'string' ),
				),
			)
		);
		$parameters = array( 'name' => 'test' );

		$result = AbilityArgumentNormalizer::normalize( $ability, $parameters );

		$this->assertEquals( $parameters, $result );
	}

	/**
	 * Test that non-empty parameters are passed through when ability has no schema.
	 */
	public function test_non_empty_parameters_passed_through_without_schema(): void {
		$ability    = $this->create_ability_mock( array() );
		$parameters = array( 'unexpected' => 'value' );

		$result = AbilityArgumentNormalizer::normalize( $ability, $parameters );

		$this->assertEquals( $parameters, $result );
	}

	/**
	 * Test that null parameters remain null.
	 */
	public function test_null_parameters_remain_null(): void {
		$ability = $this->create_ability_mock( array() );

		$result = AbilityArgumentNormalizer::normalize( $ability, null );

		$this->assertNull( $result );
	}

	/**
	 * Test that null parameters normalize to an empty array when the ability has an input schema.
	 *
	 * A schema-defining ability validates its input against the schema. null is
	 * rejected as "not of type object"; an empty array is a valid empty object.
	 * See issue #229.
	 */
	public function test_null_parameters_normalized_to_empty_array_with_schema(): void {
		$ability = $this->create_ability_mock(
			array(
				'type'       => 'object',
				'properties' => array(
					'name' => array( 'type' => 'string' ),
				),
			)
		);

		$result = AbilityArgumentNormalizer::normalize( $ability, null );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * Test that null is preserved when the schema's type explicitly permits null.
	 *
	 * A schema typed ["null","object"] declares null as a valid value. Forcing []
	 * would discard the author's intent, so null must pass through unchanged.
	 * See issue #229 discussion.
	 */
	public function test_null_parameters_preserved_when_schema_permits_null(): void {
		$ability = $this->create_ability_mock(
			array(
				'type'       => array( 'null', 'object' ),
				'properties' => array(
					'name' => array( 'type' => 'string' ),
				),
			)
		);

		$result = AbilityArgumentNormalizer::normalize( $ability, null );

		$this->assertNull( $result );
	}

	/**
	 * Test that null is preserved when the schema declares a top-level default.
	 *
	 * WP_Ability::normalize_input() fills a top-level default only when input is
	 * null. Forcing [] would skip the default, so null must pass through for the
	 * Abilities API to apply it. See issue #229 discussion.
	 */
	public function test_null_parameters_preserved_when_schema_has_default(): void {
		$ability = $this->create_ability_mock(
			array(
				'type'    => 'array',
				'items'   => array( 'type' => 'string' ),
				'default' => array( 'a', 'b' ),
			)
		);

		$result = AbilityArgumentNormalizer::normalize( $ability, null );

		$this->assertNull( $result );
	}

	/**
	 * Test that an empty {} is kept as null when the schema declares a default.
	 *
	 * WP_Ability::normalize_input() applies a top-level default only when input
	 * is null. An MCP client sends {} to mean "no arguments", so {} (decoded as
	 * []) must also return null here, otherwise the default is skipped and a
	 * schema with required fields fails. Issue #229's title covers "empty {} /
	 * omitted parameters" alike.
	 */
	public function test_empty_array_preserved_as_null_when_schema_has_default(): void {
		$ability = $this->create_ability_mock(
			array(
				'type'       => 'object',
				'required'   => array( 'x' ),
				'default'    => array( 'x' => 1 ),
				'properties' => array(
					'x' => array( 'type' => 'integer' ),
				),
			)
		);

		$result = AbilityArgumentNormalizer::normalize( $ability, array() );

		$this->assertNull( $result );
	}

	/**
	 * Test that an empty array still normalizes to [] even when the schema permits null.
	 *
	 * Only a null value is preserved; an explicit empty {} from the client stays [].
	 */
	public function test_empty_array_normalized_to_empty_array_when_schema_permits_null(): void {
		$ability = $this->create_ability_mock(
			array(
				'type'       => array( 'null', 'object' ),
				'properties' => array(
					'name' => array( 'type' => 'string' ),
				),
			)
		);

		$result = AbilityArgumentNormalizer::normalize( $ability, array() );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * Test that non-array parameters are passed through unchanged.
	 */
	public function test_non_array_parameters_passed_through(): void {
		$ability = $this->create_ability_mock( array() );

		// String parameter
		$result = AbilityArgumentNormalizer::normalize( $ability, 'string-value' );
		$this->assertEquals( 'string-value', $result );

		// Integer parameter
		$result = AbilityArgumentNormalizer::normalize( $ability, 42 );
		$this->assertEquals( 42, $result );

		// Boolean parameter
		$result = AbilityArgumentNormalizer::normalize( $ability, true );
		$this->assertTrue( $result );
	}

	/**
	 * Test with real registered ability that has no input schema.
	 */
	public function test_with_real_ability_without_input_schema(): void {
		$ability = wp_get_ability( 'test/always-allowed' );
		$this->assertNotNull( $ability, 'Test ability should be registered' );

		// Verify the ability has no input schema
		$this->assertEmpty( $ability->get_input_schema() );

		// Empty array should be normalized to null
		$result = AbilityArgumentNormalizer::normalize( $ability, array() );
		$this->assertNull( $result );
	}

	/**
	 * Test with real registered ability that has an input schema.
	 */
	public function test_with_real_ability_with_input_schema(): void {
		$ability = wp_get_ability( 'test/permission-exception' );
		$this->assertNotNull( $ability, 'Test permission-exception ability should be registered' );

		// Verify the ability has an input schema
		$this->assertNotEmpty( $ability->get_input_schema() );

		// Empty array should be preserved
		$result = AbilityArgumentNormalizer::normalize( $ability, array() );
		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * Create a mock WP_Ability with the specified input schema.
	 *
	 * @param array $input_schema The input schema to return from get_input_schema().
	 * @return \WP_Ability Mock ability object.
	 */
	private function create_ability_mock( array $input_schema ): \WP_Ability {
		$ability = $this->getMockBuilder( \WP_Ability::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_input_schema' ) )
			->getMock();

		$ability->method( 'get_input_schema' )
			->willReturn( $input_schema );

		return $ability;
	}
}
