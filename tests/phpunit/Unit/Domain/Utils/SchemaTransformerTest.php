<?php
/**
 * Tests for SchemaTransformer class.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Domain\Utils;

use WP\MCP\Domain\Utils\SchemaTransformer;
use WP\MCP\Tests\TestCase;

/**
 * Test SchemaTransformer functionality.
 */
final class SchemaTransformerTest extends TestCase {

	public function test_transform_string_schema_to_object(): void {
		$string_schema = array(
			'type'        => 'string',
			'description' => 'A string parameter',
			'minLength'   => 1,
			'maxLength'   => 100,
		);

		$result = SchemaTransformer::transform_to_object_schema( $string_schema );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'schema', $result );
		$this->assertArrayHasKey( 'was_transformed', $result );
		$this->assertTrue( $result['was_transformed'] );

		$schema = $result['schema'];
		$this->assertEquals( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'input', $schema['properties'] );
		$this->assertEquals( $string_schema, $schema['properties']['input'] );
		$this->assertArrayHasKey( 'required', $schema );
		$this->assertContains( 'input', $schema['required'] );
	}

	public function test_transform_number_schema_to_object(): void {
		$number_schema = array(
			'type'        => 'number',
			'description' => 'A number parameter',
			'minimum'     => 0,
			'maximum'     => 100,
		);

		$result = SchemaTransformer::transform_to_object_schema( $number_schema );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['was_transformed'] );

		$schema = $result['schema'];
		$this->assertEquals( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'input', $schema['properties'] );
		$this->assertEquals( $number_schema, $schema['properties']['input'] );
		$this->assertArrayHasKey( 'required', $schema );
		$this->assertContains( 'input', $schema['required'] );
	}

	public function test_transform_integer_schema_to_object(): void {
		$integer_schema = array(
			'type'        => 'integer',
			'description' => 'An integer parameter',
			'minimum'     => 1,
		);

		$result = SchemaTransformer::transform_to_object_schema( $integer_schema );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['was_transformed'] );

		$schema = $result['schema'];
		$this->assertEquals( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'input', $schema['properties'] );
		$this->assertEquals( $integer_schema, $schema['properties']['input'] );
	}

	public function test_transform_boolean_schema_to_object(): void {
		$boolean_schema = array(
			'type'        => 'boolean',
			'description' => 'A boolean parameter',
		);

		$result = SchemaTransformer::transform_to_object_schema( $boolean_schema );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['was_transformed'] );

		$schema = $result['schema'];
		$this->assertEquals( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'input', $schema['properties'] );
		$this->assertEquals( $boolean_schema, $schema['properties']['input'] );
		$this->assertContains( 'input', $schema['required'] );
	}

	public function test_transform_array_schema_to_object(): void {
		$array_schema = array(
			'type'        => 'array',
			'description' => 'An array parameter',
			'items'       => array(
				'type' => 'string',
			),
			'minItems'    => 1,
		);

		$result = SchemaTransformer::transform_to_object_schema( $array_schema );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['was_transformed'] );

		$schema = $result['schema'];
		$this->assertEquals( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'input', $schema['properties'] );
		$this->assertEquals( $array_schema, $schema['properties']['input'] );
		$this->assertContains( 'input', $schema['required'] );
	}

	public function test_object_schema_passes_through_unchanged(): void {
		$object_schema = array(
			'type'       => 'object',
			'properties' => array(
				'name' => array(
					'type'        => 'string',
					'description' => 'User name',
				),
				'age'  => array(
					'type'    => 'number',
					'minimum' => 0,
				),
			),
			'required'   => array( 'name' ),
		);

		$result = SchemaTransformer::transform_to_object_schema( $object_schema );

		$this->assertFalse( $result['was_transformed'] );
		$this->assertEquals( $object_schema, $result['schema'] );
	}

	public function test_null_schema_returns_minimal_object(): void {
		$result = SchemaTransformer::transform_to_object_schema( null );

		$this->assertIsArray( $result );
		$this->assertFalse( $result['was_transformed'] );
		$this->assertNull( $result['wrapper_property'] );

		$schema = $result['schema'];
		$this->assertEquals( array( 'type' => 'object' ), $schema );
	}

	public function test_empty_array_schema_returns_minimal_object(): void {
		$result = SchemaTransformer::transform_to_object_schema( array() );

		$this->assertIsArray( $result );
		$this->assertFalse( $result['was_transformed'] );
		$this->assertNull( $result['wrapper_property'] );

		$schema = $result['schema'];
		$this->assertEquals( array( 'type' => 'object' ), $schema );
	}

	public function test_schema_without_type_gets_object_type_added(): void {
		$schema_without_type = array(
			'properties' => array(
				'param1' => array( 'type' => 'string' ),
			),
		);

		$result = SchemaTransformer::transform_to_object_schema( $schema_without_type );

		$this->assertFalse( $result['was_transformed'] );
		$this->assertNull( $result['wrapper_property'] );

		// Type should be added to the schema.
		$this->assertEquals( 'object', $result['schema']['type'] );
		$this->assertArrayHasKey( 'properties', $result['schema'] );
		$this->assertEquals( $schema_without_type['properties'], $result['schema']['properties'] );
	}

	public function test_string_schema_with_enum_preserves_constraints(): void {
		$string_enum_schema = array(
			'type'        => 'string',
			'description' => 'Post type',
			'enum'        => array( 'post', 'page', 'attachment' ),
		);

		$result = SchemaTransformer::transform_to_object_schema( $string_enum_schema );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['was_transformed'] );

		$schema = $result['schema'];
		$this->assertEquals( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'input', $schema['properties'] );
		$this->assertEquals( $string_enum_schema, $schema['properties']['input'] );
		$this->assertArrayHasKey( 'enum', $schema['properties']['input'] );
		$this->assertEquals( array( 'post', 'page', 'attachment' ), $schema['properties']['input']['enum'] );
	}

	public function test_number_schema_with_constraints_preserves_all_metadata(): void {
		$number_schema = array(
			'type'             => 'number',
			'description'      => 'Age in years',
			'minimum'          => 0,
			'maximum'          => 150,
			'exclusiveMinimum' => true,
			'multipleOf'       => 0.5,
		);

		$result = SchemaTransformer::transform_to_object_schema( $number_schema );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['was_transformed'] );

		$schema = $result['schema'];
		$this->assertEquals( 'object', $schema['type'] );
		$this->assertEquals( $number_schema, $schema['properties']['input'] );
		$this->assertEquals( 0, $schema['properties']['input']['minimum'] );
		$this->assertEquals( 150, $schema['properties']['input']['maximum'] );
		$this->assertTrue( $schema['properties']['input']['exclusiveMinimum'] );
		$this->assertEquals( 0.5, $schema['properties']['input']['multipleOf'] );
	}

	public function test_complex_array_schema_with_nested_objects(): void {
		$complex_array_schema = array(
			'type'        => 'array',
			'description' => 'List of users',
			'items'       => array(
				'type'       => 'object',
				'properties' => array(
					'id'   => array( 'type' => 'number' ),
					'name' => array( 'type' => 'string' ),
				),
				'required'   => array( 'id', 'name' ),
			),
			'minItems'    => 1,
			'maxItems'    => 10,
		);

		$result = SchemaTransformer::transform_to_object_schema( $complex_array_schema );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['was_transformed'] );

		$schema = $result['schema'];
		$this->assertEquals( 'object', $schema['type'] );
		$this->assertEquals( $complex_array_schema, $schema['properties']['input'] );
		$this->assertArrayHasKey( 'items', $schema['properties']['input'] );
		$this->assertEquals( 'object', $schema['properties']['input']['items']['type'] );
	}

	public function test_schema_with_additional_properties(): void {
		$schema_with_additional = array(
			'type'        => 'string',
			'description' => 'A test string',
			'pattern'     => '^[a-z]+$',
			'format'      => 'email',
			'default'     => 'test',
		);

		$result = SchemaTransformer::transform_to_object_schema( $schema_with_additional );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['was_transformed'] );

		$schema = $result['schema'];
		$this->assertEquals( $schema_with_additional, $schema['properties']['input'] );
		$this->assertArrayHasKey( 'pattern', $schema['properties']['input'] );
		$this->assertArrayHasKey( 'format', $schema['properties']['input'] );
		$this->assertArrayHasKey( 'default', $schema['properties']['input'] );
	}

	public function test_multiple_transformations_are_idempotent(): void {
		$string_schema = array(
			'type'        => 'string',
			'description' => 'Test string',
		);

		$first_transform  = SchemaTransformer::transform_to_object_schema( $string_schema );
		$second_transform = SchemaTransformer::transform_to_object_schema( $first_transform['schema'] );

		// First transform should wrap it
		$this->assertTrue( $first_transform['was_transformed'] );

		// Second transformation should recognize it's already an object
		$this->assertFalse( $second_transform['was_transformed'] );

		// Schemas should be the same after idempotent transformation
		$this->assertEquals( $first_transform['schema'], $second_transform['schema'] );
	}

	public function test_custom_wrapper_key_is_used_when_provided(): void {
		$string_schema = array(
			'type' => 'string',
		);

		$result = SchemaTransformer::transform_to_object_schema( $string_schema, 'result' );

		$this->assertTrue( $result['was_transformed'] );
		$this->assertSame( 'result', $result['wrapper_property'] );

		$schema = $result['schema'];
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'result', $schema['properties'] );
		$this->assertContains( 'result', $schema['required'] );
	}

	public function test_transform_withEmptyStdClassProperties_stripsProperties(): void {
		$schema = array(
			'type'       => 'object',
			'properties' => new \stdClass(),
		);

		$result = SchemaTransformer::transform_to_object_schema( $schema );

		$this->assertFalse( $result['was_transformed'] );
		$this->assertArrayNotHasKey( 'properties', $result['schema'] );
		$this->assertSame( 'object', $result['schema']['type'] );
	}

	public function test_transform_withEmptyArrayProperties_stripsProperties(): void {
		$schema = array(
			'type'       => 'object',
			'properties' => array(),
		);

		$result = SchemaTransformer::transform_to_object_schema( $schema );

		$this->assertFalse( $result['was_transformed'] );
		$this->assertArrayNotHasKey( 'properties', $result['schema'] );
	}

	public function test_transform_withStdClassProperties_convertsToArray(): void {
		$properties       = new \stdClass();
		$properties->name = array( 'type' => 'string' );

		$schema = array(
			'type'       => 'object',
			'properties' => $properties,
		);

		$result = SchemaTransformer::transform_to_object_schema( $schema );

		$this->assertFalse( $result['was_transformed'] );
		$this->assertArrayHasKey( 'properties', $result['schema'] );
		$this->assertIsArray( $result['schema']['properties'] );
		$this->assertSame( array( 'type' => 'string' ), $result['schema']['properties']['name'] );
	}

	public function test_transform_withDeeplyNestedStdClass_convertsAll(): void {
		$inner       = new \stdClass();
		$inner->type = 'string';

		$properties       = new \stdClass();
		$properties->name = $inner;

		$schema = array(
			'type'       => 'object',
			'properties' => $properties,
		);

		$result = SchemaTransformer::transform_to_object_schema( $schema );

		$this->assertIsArray( $result['schema']['properties']['name'] );
		$this->assertSame( 'string', $result['schema']['properties']['name']['type'] );
	}

	/**
	 * Issue #207: A schema property may carry an object-valued WP callback
	 * (e.g. sanitize_callback => array( $obj, 'method' )). If that object's
	 * graph contains a back-reference (cycle), convert_objects_to_arrays()
	 * recurses without a cycle guard until the PHP stack/memory blows.
	 *
	 * This test asserts the transform completes and returns a 'schema' key
	 * without a fatal. NOTE: on current (buggy) code a real stack overflow is
	 * an uncatchable fatal that crashes the whole PHPUnit process, so this can
	 * only turn green after the guard lands. The RED proof for this case is
	 * demonstrated via a constrained subprocess repro (see task notes).
	 */
	public function test_transform_withCyclicObjectValuedCallback_doesNotBlowStack(): void {
		$obj       = new \stdClass();
		$obj->self = $obj;

		$schema = array(
			'type'       => 'object',
			'properties' => array(
				'image' => array(
					'type'              => 'string',
					'sanitize_callback' => array( $obj, 'method' ),
				),
			),
		);

		$result = SchemaTransformer::transform_to_object_schema( $schema );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'schema', $result );
	}

	/**
	 * Issue #207: the cycle guard must hold independently of key-stripping.
	 * Here the back-reference lives under a legitimate JSON Schema key
	 * (properties), which is NOT stripped, so only the spl_object_id() guard
	 * can prevent the runaway recursion. This pins the guard directly rather
	 * than relying on the WP-only key being skipped before recursion.
	 */
	public function test_transform_withCycleUnderNonStrippedKey_doesNotBlowStack(): void {
		$node             = new \stdClass();
		$node->type       = 'object';
		$node->properties = new \stdClass();
		// Back-reference under 'properties' (a legit, non-stripped key).
		$node->properties->child = $node;

		$schema = array(
			'type'       => 'object',
			'properties' => array(
				'node' => $node,
			),
		);

		$result = SchemaTransformer::transform_to_object_schema( $schema );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'schema', $result );
	}

	/**
	 * Issue #207: WordPress-only keys (sanitize_callback, validate_callback,
	 * arg_options) are not part of the JSON Schema spec and must not leak into
	 * the emitted MCP schema. They should be stripped recursively while
	 * legitimate JSON Schema keys are preserved.
	 */
	public function test_transform_stripsWpOnlyKeysFromEmittedSchema(): void {
		$schema = array(
			'type'       => 'object',
			'properties' => array(
				'title' => array(
					'type'              => 'string',
					'description'       => 'Post title',
					'enum'              => array( 'a', 'b' ),
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => 'rest_validate_request_arg',
					'arg_options'       => array( 'sanitize_callback' => 'sanitize_text_field' ),
				),
			),
		);

		$result = SchemaTransformer::transform_to_object_schema( $schema );

		$emitted = $result['schema'];

		$this->assertFalse( self::schema_contains_key( $emitted, 'sanitize_callback' ), 'sanitize_callback must be stripped' );
		$this->assertFalse( self::schema_contains_key( $emitted, 'validate_callback' ), 'validate_callback must be stripped' );
		$this->assertFalse( self::schema_contains_key( $emitted, 'arg_options' ), 'arg_options must be stripped' );

		$property = $emitted['properties']['title'];
		$this->assertSame( 'string', $property['type'] );
		$this->assertSame( 'Post title', $property['description'] );
		$this->assertSame( array( 'a', 'b' ), $property['enum'] );
	}

	/**
	 * Issue #207 regression guard: a legitimate nested object schema, where
	 * properties arrive as stdClass instances (as from a JSON decode cycle)
	 * and carry no callbacks, must still convert to the expected array
	 * structure.
	 */
	public function test_transform_legitimateNestedObjectSchema_stillConverts(): void {
		$address               = new \stdClass();
		$address->type         = 'object';
		$street                = new \stdClass();
		$street->type          = 'string';
		$city                  = new \stdClass();
		$city->type            = 'string';
		$address_props         = new \stdClass();
		$address_props->street = $street;
		$address_props->city   = $city;
		$address->properties   = $address_props;

		$properties          = new \stdClass();
		$properties->address = $address;

		$schema = array(
			'type'       => 'object',
			'properties' => $properties,
		);

		$result = SchemaTransformer::transform_to_object_schema( $schema );

		$this->assertFalse( $result['was_transformed'] );

		$emitted = $result['schema'];
		$this->assertIsArray( $emitted['properties'] );
		$this->assertIsArray( $emitted['properties']['address'] );
		$this->assertSame( 'object', $emitted['properties']['address']['type'] );
		$this->assertIsArray( $emitted['properties']['address']['properties'] );
		$this->assertSame( 'string', $emitted['properties']['address']['properties']['street']['type'] );
		$this->assertSame( 'string', $emitted['properties']['address']['properties']['city']['type'] );
	}

	/**
	 * Recursively determine whether a schema array contains the given key
	 * anywhere in its structure.
	 *
	 * @param mixed  $value The value to search.
	 * @param string $key   The key to look for.
	 *
	 * @return bool True if the key exists anywhere.
	 */
	private static function schema_contains_key( $value, string $key ): bool {
		if ( ! is_array( $value ) ) {
			return false;
		}

		if ( array_key_exists( $key, $value ) ) {
			return true;
		}

		foreach ( $value as $item ) {
			if ( self::schema_contains_key( $item, $key ) ) {
				return true;
			}
		}

		return false;
	}
}
