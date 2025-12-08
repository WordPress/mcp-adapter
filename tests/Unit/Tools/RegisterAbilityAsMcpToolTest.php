<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Tools;

use WP\MCP\Domain\Tools\RegisterAbilityAsMcpTool;
use WP\MCP\Tests\TestCase;

final class RegisterAbilityAsMcpToolTest extends TestCase {

	public function test_make_builds_tool_from_ability(): void {
		$ability = wp_get_ability( 'test/always-allowed' );
		$this->assertNotNull( $ability, 'Ability test/always-allowed should be registered' );
		$tool = RegisterAbilityAsMcpTool::make( $ability, $this->makeServer() );
		$arr  = $tool->to_array();
		$this->assertSame( 'test-always-allowed', $arr['name'] );
		$this->assertArrayHasKey( 'inputSchema', $arr );
	}

	public function test_annotations_are_mapped_to_mcp_format(): void {
		$ability = wp_get_ability( 'test/annotated-ability' );
		$this->assertNotNull( $ability, 'Ability test/annotated-ability should be registered' );

		$tool = RegisterAbilityAsMcpTool::make( $ability, $this->makeServer() );
		$this->assertNotWPError( $tool );

		$arr = $tool->to_array();

		// Verify MCP-format annotations.
		$this->assertArrayHasKey( 'annotations', $arr );
		$this->assertArrayHasKey( 'readOnlyHint', $arr['annotations'] );
		$this->assertArrayHasKey( 'destructiveHint', $arr['annotations'] );
		$this->assertArrayHasKey( 'idempotentHint', $arr['annotations'] );

		// Verify values.
		$this->assertTrue( $arr['annotations']['readOnlyHint'] );
		$this->assertFalse( $arr['annotations']['destructiveHint'] );
		$this->assertTrue( $arr['annotations']['idempotentHint'] );

		// Verify WordPress-format fields are not present.
		$this->assertArrayNotHasKey( 'readonly', $arr['annotations'] );
		$this->assertArrayNotHasKey( 'destructive', $arr['annotations'] );
		$this->assertArrayNotHasKey( 'idempotent', $arr['annotations'] );
	}

	public function test_null_annotations_are_filtered_out(): void {
		$ability = wp_get_ability( 'test/null-annotations' );
		$this->assertNotNull( $ability, 'Ability test/null-annotations should be registered' );

		$tool = RegisterAbilityAsMcpTool::make( $ability, $this->makeServer() );
		$this->assertNotWPError( $tool );

		$arr = $tool->to_array();

		// Verify only non-null annotations are present.
		$this->assertArrayHasKey( 'annotations', $arr );
		$this->assertArrayNotHasKey( 'readOnlyHint', $arr['annotations'] );
		$this->assertArrayNotHasKey( 'destructiveHint', $arr['annotations'] );
		$this->assertArrayHasKey( 'idempotentHint', $arr['annotations'] );
		$this->assertFalse( $arr['annotations']['idempotentHint'] );
	}

	public function test_instructions_field_is_ignored(): void {
		$ability = wp_get_ability( 'test/with-instructions' );
		$this->assertNotNull( $ability, 'Ability test/with-instructions should be registered' );

		$tool = RegisterAbilityAsMcpTool::make( $ability, $this->makeServer() );
		$this->assertNotWPError( $tool );

		$arr = $tool->to_array();

		// Verify instructions field is not in the output.
		$this->assertArrayHasKey( 'annotations', $arr );
		$this->assertArrayNotHasKey( 'instructions', $arr['annotations'] );

		// Verify other annotations are mapped correctly.
		$this->assertArrayHasKey( 'readOnlyHint', $arr['annotations'] );
		$this->assertTrue( $arr['annotations']['readOnlyHint'] );
	}

	public function test_mcp_native_fields_are_preserved(): void {
		$ability = wp_get_ability( 'test/mcp-native' );
		$this->assertNotNull( $ability, 'Ability test/mcp-native should be registered' );

		$tool = RegisterAbilityAsMcpTool::make( $ability, $this->makeServer() );
		$this->assertNotWPError( $tool );

		$arr = $tool->to_array();

		// Verify MCP-native fields are preserved.
		$this->assertArrayHasKey( 'annotations', $arr );
		$this->assertArrayHasKey( 'openWorldHint', $arr['annotations'] );
		$this->assertTrue( $arr['annotations']['openWorldHint'] );
		$this->assertArrayHasKey( 'title', $arr['annotations'] );
		$this->assertSame( 'Custom Annotation Title', $arr['annotations']['title'] );

		// Verify WordPress annotations are still mapped.
		$this->assertArrayHasKey( 'readOnlyHint', $arr['annotations'] );
		$this->assertFalse( $arr['annotations']['readOnlyHint'] );
	}

	public function test_empty_annotations_are_not_included(): void {
		$ability = wp_get_ability( 'test/no-annotations' );
		$this->assertNotNull( $ability, 'Ability test/no-annotations should be registered' );

		$tool = RegisterAbilityAsMcpTool::make( $ability, $this->makeServer() );
		$this->assertNotWPError( $tool );

		$arr = $tool->to_array();

		// Verify annotations field is not present.
		$this->assertArrayNotHasKey( 'annotations', $arr );
	}

	public function test_all_null_annotations_result_in_no_annotations_field(): void {
		$ability = wp_get_ability( 'test/all-null-annotations' );
		$this->assertNotNull( $ability, 'Ability test/all-null-annotations should be registered' );

		$tool = RegisterAbilityAsMcpTool::make( $ability, $this->makeServer() );
		$this->assertNotWPError( $tool );

		$arr = $tool->to_array();

		// Verify annotations field is not present when all values are null.
		$this->assertArrayNotHasKey( 'annotations', $arr );
	}

	public function test_wordpress_format_fields_are_filtered_out(): void {
		$ability = wp_get_ability( 'test/annotated-ability' );
		$this->assertNotNull( $ability, 'Ability test/annotated-ability should be registered' );

		$tool = RegisterAbilityAsMcpTool::make( $ability, $this->makeServer() );
		$this->assertNotWPError( $tool );

		$arr = $tool->to_array();

		// Verify annotations exist.
		$this->assertArrayHasKey( 'annotations', $arr );

		// Verify WordPress-format fields are NOT present.
		$this->assertArrayNotHasKey( 'readonly', $arr['annotations'], 'WordPress format "readonly" should be filtered out' );
		$this->assertArrayNotHasKey( 'destructive', $arr['annotations'], 'WordPress format "destructive" should be filtered out' );
		$this->assertArrayNotHasKey( 'idempotent', $arr['annotations'], 'WordPress format "idempotent" should be filtered out' );
		$this->assertArrayNotHasKey( 'instructions', $arr['annotations'], 'Deprecated "instructions" field should be filtered out' );

		// Verify ONLY MCP-format fields are present.
		$valid_mcp_fields = array( 'readOnlyHint', 'destructiveHint', 'idempotentHint', 'openWorldHint', 'title' );
		foreach ( array_keys( $arr['annotations'] ) as $field ) {
			$this->assertContains( $field, $valid_mcp_fields, "Annotation field '{$field}' is not a valid MCP field" );
		}
	}

	public function test_non_mcp_fields_are_filtered_out(): void {
		// The built-in mcp-adapter abilities use MCP format and might have extra fields like 'priority'.
		$ability = wp_get_ability( 'mcp-adapter/get-ability-info' );
		if ( ! $ability ) {
			$this->markTestSkipped( 'Built-in ability mcp-adapter/get-ability-info not found' );
		}

		$tool = RegisterAbilityAsMcpTool::make( $ability, $this->makeServer() );
		$this->assertNotWPError( $tool );

		$arr = $tool->to_array();

		if ( ! isset( $arr['annotations'] ) ) {
			// If no annotations, test passes.
			return;
		}

		// Verify ONLY MCP-spec fields are present.
		// Includes tool-specific hints and shared annotations (audience, lastModified, priority).
		$valid_mcp_fields = array(
			'readOnlyHint',
			'destructiveHint',
			'idempotentHint',
			'openWorldHint',
			'title',
			'audience',
			'lastModified',
			'priority',
		);
		foreach ( array_keys( $arr['annotations'] ) as $field ) {
			$this->assertContains( $field, $valid_mcp_fields, "Non-MCP field '{$field}' should be filtered out" );
		}

		// Verify no WordPress-format fields.
		$this->assertArrayNotHasKey( 'readonly', $arr['annotations'] );
		$this->assertArrayNotHasKey( 'destructive', $arr['annotations'] );
		$this->assertArrayNotHasKey( 'idempotent', $arr['annotations'] );
	}

	public function test_transformation_flags_are_stored_in_metadata(): void {
		$this->register_ability_in_hook(
			'test/flat-transformed-tool',
			array(
				'label'               => 'Flat Transformed Tool',
				'description'         => 'Uses flat schemas',
				'category'            => 'test',
				'input_schema'        => array( 'type' => 'string' ),
				'output_schema'       => array( 'type' => 'string' ),
				'execute_callback'    => static function ( $input ) {
					return $input;
				},
				'permission_callback' => static function () {
					return true;
				},
				'meta'                => array(
					'mcp' => array( 'public' => true ),
				),
			)
		);

		$ability = wp_get_ability( 'test/flat-transformed-tool' );
		$this->assertNotNull( $ability, 'Ability test/flat-transformed-tool should be registered' );
		$tool = RegisterAbilityAsMcpTool::make( $ability, $this->makeServer() );

		$this->assertInstanceOf( \WP\MCP\Domain\Tools\McpTool::class, $tool );

		$metadata = $tool->get_metadata();
		$this->assertTrue( $metadata['_input_schema_transformed'] ?? false );
		$this->assertTrue( $metadata['_output_schema_transformed'] ?? false );
		$this->assertSame( 'input', $metadata['_input_schema_wrapper'] ?? '' );
		$this->assertSame( 'result', $metadata['_output_schema_wrapper'] ?? '' );

		wp_unregister_ability( 'test/flat-transformed-tool' );
	}
}
