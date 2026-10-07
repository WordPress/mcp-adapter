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
 * Tests property name mapping and feature selection. Values are carried as given.
 */
final class McpAnnotationMapperTest extends TestCase {

	public function test_map_with_empty_array(): void {
		$result = McpAnnotationMapper::map( array(), 'resource' );
		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	public function test_map_filters_out_non_mcp_fields(): void {
		$annotations = array(
			'customField'  => 'value',
			'invalidField' => 123,
			'audience'     => array( 'user' ),
		);

		$result = McpAnnotationMapper::map( $annotations, 'resource' );

		$this->assertArrayHasKey( 'audience', $result );
		$this->assertArrayNotHasKey( 'customField', $result );
		$this->assertArrayNotHasKey( 'invalidField', $result );
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

	/**
	 * Per MCP 2025-11-25 spec, Prompt templates do NOT support annotations.
	 * Only content blocks inside prompt messages support annotations.
	 * The mapper returns empty for 'prompt' feature type.
	 */
	public function test_map_returns_empty_for_prompt_feature_type(): void {
		$annotations = array(
			'audience'     => array( 'user' ),
			'lastModified' => '2024-01-15T10:30:00Z',
			'priority'     => 0.5,
		);

		$result = McpAnnotationMapper::map( $annotations, 'prompt' );

		$this->assertEmpty( $result, 'Prompt templates do not support annotations per MCP spec' );
	}

	public function test_map_includes_tool_specific_fields(): void {
		$annotations = array(
			'readonly'      => true,
			'destructive'   => false,
			'idempotent'    => true,
			'openWorldHint' => false,
			'title'         => 'Tool Title',
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

	/**
	 * Per MCP 2025-11-25 spec, Prompt templates do NOT support any annotations.
	 * Neither tool-specific nor shared annotations are mapped.
	 */
	public function test_map_excludes_all_fields_for_prompt(): void {
		$annotations = array(
			'readonly' => true,
			'title'    => 'Some Title',
			'priority' => 0.5,
			'audience' => array( 'user' ),
		);

		$result = McpAnnotationMapper::map( $annotations, 'prompt' );

		// No annotations should be included for prompts
		$this->assertEmpty( $result, 'Prompt templates do not support any annotations per MCP spec' );
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

	public function test_map_carries_resource_values_as_given(): void {
		// Values are not coerced, trimmed, or range-checked; the schema decides.
		$annotations = array(
			'audience'     => array( 'user', 'invalid-role' ),
			'lastModified' => '  whitespace  ',
			'priority'     => -0.5,
		);

		$result = McpAnnotationMapper::map( $annotations, 'resource' );

		$this->assertSame( $annotations, $result );
	}

	public function test_map_carries_tool_values_as_given(): void {
		$annotations = array(
			'readonly' => 'true',
			'title'    => '  untrimmed  ',
		);

		$result = McpAnnotationMapper::map( $annotations, 'tool' );

		$this->assertSame( 'true', $result['readOnlyHint'] );
		$this->assertSame( '  untrimmed  ', $result['title'] );
	}

	public function test_map_with_null_ability_property_uses_mcp_field_name_for_tools(): void {
		$annotations = array(
			// Fields with null ability_property should map 1:1.
			// For tools, only openWorldHint and title have null ability_property.
			'openWorldHint' => true,
			'title'         => 'Test',
		);

		$result = McpAnnotationMapper::map( $annotations, 'tool' );

		// These should map 1:1 (ability_property is null)
		$this->assertArrayHasKey( 'openWorldHint', $result );
		$this->assertArrayHasKey( 'title', $result );
	}

	public function test_map_with_null_ability_property_uses_mcp_field_name_for_resources(): void {
		$annotations = array(
			// Fields with null ability_property should map 1:1.
			// Shared annotations (audience, lastModified, priority) apply to resources.
			'audience'     => array( 'user' ),
			'lastModified' => '2024-01-15T10:30:00Z',
			'priority'     => 0.5,
		);

		$result = McpAnnotationMapper::map( $annotations, 'resource' );

		// These should map 1:1 (ability_property is null)
		$this->assertArrayHasKey( 'audience', $result );
		$this->assertArrayHasKey( 'lastModified', $result );
		$this->assertArrayHasKey( 'priority', $result );
	}

	public function test_map_excludes_shared_annotations_from_tools(): void {
		// Per MCP 2025-11-25 spec, ToolAnnotations does NOT include shared Annotations fields.
		$annotations = array(
			'audience'     => array( 'user' ),        // Shared annotation - NOT for tools
			'lastModified' => '2024-01-15T10:30:00Z', // Shared annotation - NOT for tools
			'priority'     => 0.5,                     // Shared annotation - NOT for tools
			'readOnlyHint' => true,                    // Tool annotation - should be included
			'title'        => 'Test Title',           // Tool annotation - should be included
		);

		$result = McpAnnotationMapper::map( $annotations, 'tool' );

		// Shared annotations should NOT be mapped for tools
		$this->assertArrayNotHasKey( 'audience', $result, 'Shared annotation audience should not be mapped for tools' );
		$this->assertArrayNotHasKey( 'lastModified', $result, 'Shared annotation lastModified should not be mapped for tools' );
		$this->assertArrayNotHasKey( 'priority', $result, 'Shared annotation priority should not be mapped for tools' );

		// Tool-specific annotations SHOULD be mapped
		$this->assertArrayHasKey( 'readOnlyHint', $result );
		$this->assertArrayHasKey( 'title', $result );
	}
}
