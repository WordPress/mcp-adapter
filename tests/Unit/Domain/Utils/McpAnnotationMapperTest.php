<?php
/**
 * Tests for McpAnnotationMapper class.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Domain\Utils;

use WP\MCP\Domain\Utils\McpAnnotationMapper;
use WP\MCP\Tests\TestCase;

/**
 * Test McpAnnotationMapper functionality.
 *
 * Tests only property name mapping. Normalization and validation are tested separately.
 */
final class McpAnnotationMapperTest extends TestCase {

	public function test_map_with_empty_array(): void {
		$result = McpAnnotationMapper::map( array(), 'resource' );
		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	public function test_map_passes_through_unknown_fields(): void {
		$annotations = array(
			'customField'  => 'value',
			'invalidField' => 123,
			'audience'     => array( 'user' ),
		);

		$result = McpAnnotationMapper::map( $annotations, 'resource' );

		$this->assertArrayHasKey( 'audience', $result );
		$this->assertArrayHasKey( 'customField', $result );
		$this->assertSame( 'value', $result['customField'] );
		$this->assertArrayHasKey( 'invalidField', $result );
		$this->assertSame( 123, $result['invalidField'] );
	}

	public function test_map_includes_valid_fields_for_resource(): void {
		$annotations = array(
			'audience'     => array( 'user', 'assistant' ),
			'lastModified' => '2024-01-15T10:30:00Z',
			'priority'     => 0.8,
		);

		$result = McpAnnotationMapper::map( $annotations, 'resource' );

		$this->assertArrayHasKey( 'audience', $result );
		$this->assertArrayHasKey( 'lastModified', $result );
		$this->assertArrayHasKey( 'priority', $result );
		$this->assertCount( 3, $result );
	}

	public function test_map_includes_valid_fields_for_prompt(): void {
		$annotations = array(
			'audience'     => array( 'user' ),
			'lastModified' => '2024-01-15T10:30:00Z',
			'priority'     => 0.5,
		);

		$result = McpAnnotationMapper::map( $annotations, 'prompt' );

		$this->assertArrayHasKey( 'audience', $result );
		$this->assertArrayHasKey( 'lastModified', $result );
		$this->assertArrayHasKey( 'priority', $result );
		$this->assertCount( 3, $result );
	}

	public function test_map_includes_tool_specific_fields(): void {
		$annotations = array(
			'readonly'     => true,
			'destructive'  => false,
			'idempotent'   => true,
			'openWorldHint' => false,
			'title'        => 'Tool Title',
		);

		$result = McpAnnotationMapper::map( $annotations, 'tool' );

		// Tool-specific fields should be included for tools
		$this->assertArrayHasKey( 'readOnlyHint', $result );
		$this->assertArrayHasKey( 'destructiveHint', $result );
		$this->assertArrayHasKey( 'idempotentHint', $result );
		$this->assertArrayHasKey( 'openWorldHint', $result );
		$this->assertArrayHasKey( 'title', $result );
		$this->assertCount( 5, $result );
	}

	public function test_map_maps_readonly_to_readonlyhint(): void {
		$annotations = array(
			'readonly' => true,
		);

		$result = McpAnnotationMapper::map( $annotations, 'tool' );

		$this->assertArrayHasKey( 'readOnlyHint', $result );
		$this->assertArrayNotHasKey( 'readonly', $result );
		$this->assertTrue( $result['readOnlyHint'] );
	}

	public function test_map_retains_existing_mcp_field_when_no_override(): void {
		$annotations = array(
			'readOnlyHint' => false,
		);

		$result = McpAnnotationMapper::map( $annotations, 'tool' );

		$this->assertArrayHasKey( 'readOnlyHint', $result );
		$this->assertFalse( $result['readOnlyHint'] );
	}

	public function test_readonly_override_takes_precedence_over_readonlyhint(): void {
		$annotations = array(
			'readOnlyHint' => false,
			'readonly'     => true,
		);

		$result = McpAnnotationMapper::map( $annotations, 'tool' );

		$this->assertArrayHasKey( 'readOnlyHint', $result );
		$this->assertTrue( $result['readOnlyHint'], 'WordPress-format readonly should override readOnlyHint value' );
	}

	public function test_map_maps_destructive_to_destructivehint(): void {
		$annotations = array(
			'destructive' => false,
		);

		$result = McpAnnotationMapper::map( $annotations, 'tool' );

		$this->assertArrayHasKey( 'destructiveHint', $result );
		$this->assertArrayNotHasKey( 'destructive', $result );
		$this->assertFalse( $result['destructiveHint'] );
	}

	public function test_map_maps_idempotent_to_idempotenthint(): void {
		$annotations = array(
			'idempotent' => true,
		);

		$result = McpAnnotationMapper::map( $annotations, 'tool' );

		$this->assertArrayHasKey( 'idempotentHint', $result );
		$this->assertArrayNotHasKey( 'idempotent', $result );
		$this->assertTrue( $result['idempotentHint'] );
	}

	public function test_map_excludes_tool_fields_for_resource(): void {
		$annotations = array(
			'readonly'    => true,
			'destructive' => false,
			'title'       => 'Some Title',
			'audience'    => array( 'user' ),
		);

		$result = McpAnnotationMapper::map( $annotations, 'resource' );

		// Tool-specific fields should NOT be included for resources
		$this->assertArrayNotHasKey( 'readOnlyHint', $result );
		$this->assertArrayNotHasKey( 'destructiveHint', $result );
		$this->assertArrayNotHasKey( 'title', $result );

		// But shared fields should be included
		$this->assertArrayHasKey( 'audience', $result );
		$this->assertCount( 1, $result );
	}

	public function test_map_excludes_tool_fields_for_prompt(): void {
		$annotations = array(
			'readonly'    => true,
			'title'       => 'Some Title',
			'priority'    => 0.5,
		);

		$result = McpAnnotationMapper::map( $annotations, 'prompt' );

		// Tool-specific fields should NOT be included for prompts
		$this->assertArrayNotHasKey( 'readOnlyHint', $result );
		$this->assertArrayNotHasKey( 'title', $result );

		// But shared fields should be included
		$this->assertArrayHasKey( 'priority', $result );
		$this->assertCount( 1, $result );
	}

	public function test_map_filters_out_null_values(): void {
		$annotations = array(
			'audience'     => null,
			'lastModified' => '2024-01-15T10:30:00Z',
			'priority'     => null,
		);

		$result = McpAnnotationMapper::map( $annotations, 'resource' );

		$this->assertArrayNotHasKey( 'audience', $result );
		$this->assertArrayHasKey( 'lastModified', $result );
		$this->assertArrayNotHasKey( 'priority', $result );
		$this->assertCount( 1, $result );
	}

	public function test_map_performs_light_type_validation(): void {
		$annotations = array(
			'audience'     => array( 'user', 'invalid-role' ), // Invalid role passed through
			'lastModified' => '  whitespace  ',                // Strings get trimmed
			'priority'     => -0.5,                            // Numbers cast to float, not clamped
			'title'        => '  untrimmed  ',                 // Strings get trimmed
		);

		$result = McpAnnotationMapper::map( $annotations, 'tool' );

		// Light validation: basic type checks and trimming only
		$this->assertSame( array( 'user', 'invalid-role' ), $result['audience'] ); // Array passed through
		$this->assertSame( 'whitespace', $result['lastModified'] );                 // String trimmed
		$this->assertSame( -0.5, $result['priority'] );                             // Number not clamped
		$this->assertSame( 'untrimmed', $result['title'] );                         // String trimmed
	}

	public function test_map_with_null_ability_property_uses_mcp_field_name(): void {
		$annotations = array(
			// Fields with null ability_property should map 1:1
			'audience'     => array( 'user' ),
			'lastModified' => '2024-01-15T10:30:00Z',
			'priority'     => 0.5,
			'openWorldHint' => true,
			'title'        => 'Test',
		);

		$result = McpAnnotationMapper::map( $annotations, 'tool' );

		// These should map 1:1 (ability_property is null)
		$this->assertArrayHasKey( 'audience', $result );
		$this->assertArrayHasKey( 'lastModified', $result );
		$this->assertArrayHasKey( 'priority', $result );
		$this->assertArrayHasKey( 'openWorldHint', $result );
		$this->assertArrayHasKey( 'title', $result );
	}

	// Custom annotation pass-through tests.

	public function test_map_passes_through_unknown_fields_for_tools(): void {
		$annotations = array(
			'readonly'     => true,
			'x-vendor-tag' => 'my-value',
			'customMeta'   => array( 'key' => 'val' ),
		);

		$result = McpAnnotationMapper::map( $annotations, 'tool' );

		$this->assertArrayHasKey( 'readOnlyHint', $result );
		$this->assertArrayHasKey( 'x-vendor-tag', $result );
		$this->assertSame( 'my-value', $result['x-vendor-tag'] );
		$this->assertArrayHasKey( 'customMeta', $result );
		$this->assertSame( array( 'key' => 'val' ), $result['customMeta'] );
	}

	public function test_map_passes_through_unknown_fields_for_resources(): void {
		$annotations = array(
			'audience'     => array( 'user' ),
			'x-vendor-tag' => 42,
		);

		$result = McpAnnotationMapper::map( $annotations, 'resource' );

		$this->assertArrayHasKey( 'audience', $result );
		$this->assertArrayHasKey( 'x-vendor-tag', $result );
		$this->assertSame( 42, $result['x-vendor-tag'] );
	}

	public function test_map_does_not_pass_through_unknown_fields_for_prompts(): void {
		$annotations = array(
			'audience'     => array( 'user' ),
			'x-vendor-tag' => 'should-not-appear',
			'customMeta'   => 'also-excluded',
		);

		$result = McpAnnotationMapper::map( $annotations, 'prompt' );

		$this->assertArrayHasKey( 'audience', $result );
		$this->assertArrayNotHasKey( 'x-vendor-tag', $result );
		$this->assertArrayNotHasKey( 'customMeta', $result );
		$this->assertCount( 1, $result );
	}

	public function test_map_does_not_pass_through_known_fields_excluded_by_feature_type(): void {
		$annotations = array(
			'audience'      => array( 'user' ),
			'readOnlyHint'  => true,
			'title'         => 'Tool Title',
			'x-vendor-tag'  => 'custom',
		);

		$result = McpAnnotationMapper::map( $annotations, 'resource' );

		// Shared annotations should be included.
		$this->assertArrayHasKey( 'audience', $result );

		// Tool-specific known fields should NOT appear — they are excluded by feature type, not passed through.
		$this->assertArrayNotHasKey( 'readOnlyHint', $result );
		$this->assertArrayNotHasKey( 'title', $result );

		// Unknown fields should pass through.
		$this->assertArrayHasKey( 'x-vendor-tag', $result );
		$this->assertSame( 'custom', $result['x-vendor-tag'] );
	}

	public function test_map_excludes_null_unknown_fields(): void {
		$annotations = array(
			'x-vendor-tag' => null,
			'x-other'      => 'present',
		);

		$result = McpAnnotationMapper::map( $annotations, 'tool' );

		$this->assertArrayNotHasKey( 'x-vendor-tag', $result );
		$this->assertArrayHasKey( 'x-other', $result );
		$this->assertSame( 'present', $result['x-other'] );
	}

	public function test_map_handles_mixed_known_and_unknown_fields(): void {
		$annotations = array(
			'readonly'      => true,
			'destructive'   => false,
			'title'         => 'My Tool',
			'x-category'    => 'search',
			'x-deprecated'  => false,
			'audience'      => array( 'user' ),
		);

		$result = McpAnnotationMapper::map( $annotations, 'tool' );

		// Known fields mapped normally.
		$this->assertArrayHasKey( 'readOnlyHint', $result );
		$this->assertTrue( $result['readOnlyHint'] );
		$this->assertArrayHasKey( 'destructiveHint', $result );
		$this->assertArrayHasKey( 'title', $result );
		$this->assertArrayHasKey( 'audience', $result );

		// Unknown fields passed through as-is.
		$this->assertArrayHasKey( 'x-category', $result );
		$this->assertSame( 'search', $result['x-category'] );
		$this->assertArrayHasKey( 'x-deprecated', $result );
		$this->assertFalse( $result['x-deprecated'] );

		// WordPress aliases should NOT appear as separate keys.
		$this->assertArrayNotHasKey( 'readonly', $result );
		$this->assertArrayNotHasKey( 'destructive', $result );
	}

	public function test_map_does_not_pass_through_wordpress_ability_property_aliases(): void {
		$annotations = array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		);

		$result = McpAnnotationMapper::map( $annotations, 'resource' );

		// WordPress aliases are known keys — they should NOT pass through as unknown.
		$this->assertArrayNotHasKey( 'readonly', $result );
		$this->assertArrayNotHasKey( 'destructive', $result );
		$this->assertArrayNotHasKey( 'idempotent', $result );
	}
}
