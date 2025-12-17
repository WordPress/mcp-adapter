<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Tools;

use WP\MCP\Domain\Tools\RegisterAbilityAsMcpTool;
use WP\MCP\Domain\Tools\ToolMetadataHelper;
use WP\MCP\Tests\TestCase;
use WP\McpSchema\Server\Tools\Tool;

final class RegisterAbilityAsMcpToolTest extends TestCase {

	public function test_make_builds_tool_from_ability(): void {
		$ability = wp_get_ability( 'test/always-allowed' );
		$this->assertNotNull( $ability, 'Ability test/always-allowed should be registered' );
		$tool = RegisterAbilityAsMcpTool::make( $ability );
		$this->assertInstanceOf( Tool::class, $tool );
		$arr = $tool->toArray();
		$this->assertSame( 'test-always-allowed', $arr['name'] );
		$this->assertArrayHasKey( 'inputSchema', $arr );
	}

	public function test_annotations_are_mapped_to_mcp_format(): void {
		$ability = wp_get_ability( 'test/annotated-ability' );
		$this->assertNotNull( $ability, 'Ability test/annotated-ability should be registered' );

		$tool = RegisterAbilityAsMcpTool::make( $ability );
		$this->assertNotWPError( $tool );

		$arr = $tool->toArray();

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

		$tool = RegisterAbilityAsMcpTool::make( $ability );
		$this->assertNotWPError( $tool );

		$arr = $tool->toArray();

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

		$tool = RegisterAbilityAsMcpTool::make( $ability );
		$this->assertNotWPError( $tool );

		$arr = $tool->toArray();

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

		$tool = RegisterAbilityAsMcpTool::make( $ability );
		$this->assertNotWPError( $tool );

		$arr = $tool->toArray();

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

		$tool = RegisterAbilityAsMcpTool::make( $ability );
		$this->assertNotWPError( $tool );

		$arr = $tool->toArray();

		// Verify annotations field is not present.
		$this->assertArrayNotHasKey( 'annotations', $arr );
	}

	public function test_all_null_annotations_result_in_no_annotations_field(): void {
		$ability = wp_get_ability( 'test/all-null-annotations' );
		$this->assertNotNull( $ability, 'Ability test/all-null-annotations should be registered' );

		$tool = RegisterAbilityAsMcpTool::make( $ability );
		$this->assertNotWPError( $tool );

		$arr = $tool->toArray();

		// Verify annotations field is not present when all values are null.
		$this->assertArrayNotHasKey( 'annotations', $arr );
	}

	public function test_wordpress_format_fields_are_filtered_out(): void {
		$ability = wp_get_ability( 'test/annotated-ability' );
		$this->assertNotNull( $ability, 'Ability test/annotated-ability should be registered' );

		$tool = RegisterAbilityAsMcpTool::make( $ability );
		$this->assertNotWPError( $tool );

		$arr = $tool->toArray();

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

		$tool = RegisterAbilityAsMcpTool::make( $ability );
		$this->assertNotWPError( $tool );

		$arr = $tool->toArray();

		if ( ! isset( $arr['annotations'] ) ) {
			// If no annotations, test passes.
			return;
		}

		// Verify ONLY MCP ToolAnnotations fields are present.
		// Per MCP 2025-11-25 spec, ToolAnnotations contains only these fields.
		// Shared Annotations (audience, lastModified, priority) are for Resources/Prompts, NOT Tools.
		$valid_tool_annotation_fields = array(
			'readOnlyHint',
			'destructiveHint',
			'idempotentHint',
			'openWorldHint',
			'title',
		);
		foreach ( array_keys( $arr['annotations'] ) as $field ) {
			$this->assertContains( $field, $valid_tool_annotation_fields, "Field '{$field}' is not a valid ToolAnnotations field per MCP spec" );
		}

		// Verify no WordPress-format fields.
		$this->assertArrayNotHasKey( 'readonly', $arr['annotations'] );
		$this->assertArrayNotHasKey( 'destructive', $arr['annotations'] );
		$this->assertArrayNotHasKey( 'idempotent', $arr['annotations'] );

		// Verify shared Annotations fields are NOT present (they're for Resources, not Tools).
		$this->assertArrayNotHasKey( 'audience', $arr['annotations'], 'Shared annotation audience should not be mapped for tools' );
		$this->assertArrayNotHasKey( 'lastModified', $arr['annotations'], 'Shared annotation lastModified should not be mapped for tools' );
		$this->assertArrayNotHasKey( 'priority', $arr['annotations'], 'Shared annotation priority should not be mapped for tools' );
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
		$tool = RegisterAbilityAsMcpTool::make( $ability );
		$this->assertInstanceOf( Tool::class, $tool );

		$this->assertTrue( ToolMetadataHelper::is_input_transformed( $tool ) );
		$this->assertTrue( ToolMetadataHelper::is_output_transformed( $tool ) );
		$this->assertSame( 'input', ToolMetadataHelper::get_input_wrapper( $tool ) );
		$this->assertSame( 'result', ToolMetadataHelper::get_output_wrapper( $tool ) );

		wp_unregister_ability( 'test/flat-transformed-tool' );
	}

	// Tool Name Filter and Validation Tests

	public function test_tool_name_filter_applied(): void {
		$this->register_ability_in_hook(
			'test/filter-name-ability',
			array(
				'label'               => 'Filter Test',
				'description'         => 'Tests filter hook',
				'category'            => 'test',
				'input_schema'        => array( 'type' => 'object' ),
				'execute_callback'    => static function () {
					return 'ok';
				},
				'permission_callback' => static function () {
					return true;
				},
				'meta'                => array( 'mcp' => array( 'public' => true ) ),
			)
		);

		// Add filter to modify tool name.
		$filter_callback = static function ( string $name ) {
			return 'custom-filtered-name';
		};
		add_filter( 'mcp_adapter_tool_name', $filter_callback );

		$ability = wp_get_ability( 'test/filter-name-ability' );
		$this->assertNotNull( $ability );

		$tool = RegisterAbilityAsMcpTool::make( $ability );
		$this->assertNotWPError( $tool );
		$this->assertInstanceOf( Tool::class, $tool );

		$arr = $tool->toArray();
		$this->assertSame( 'custom-filtered-name', $arr['name'] );

		remove_filter( 'mcp_adapter_tool_name', $filter_callback );
		wp_unregister_ability( 'test/filter-name-ability' );
	}

	public function test_tool_name_filter_validation_rejects_invalid(): void {
		$this->register_ability_in_hook(
			'test/filter-invalid-ability',
			array(
				'label'               => 'Filter Invalid Test',
				'description'         => 'Tests filter validation',
				'category'            => 'test',
				'input_schema'        => array( 'type' => 'object' ),
				'execute_callback'    => static function () {
					return 'ok';
				},
				'permission_callback' => static function () {
					return true;
				},
				'meta'                => array( 'mcp' => array( 'public' => true ) ),
			)
		);

		// Add filter that returns invalid name (with spaces).
		$filter_callback = static function () {
			return 'invalid name with spaces';
		};
		add_filter( 'mcp_adapter_tool_name', $filter_callback );

		$ability = wp_get_ability( 'test/filter-invalid-ability' );
		$this->assertNotNull( $ability );

		$tool = RegisterAbilityAsMcpTool::make( $ability );
		$this->assertWPError( $tool );
		$this->assertSame( 'mcp_tool_name_filter_invalid', $tool->get_error_code() );

		remove_filter( 'mcp_adapter_tool_name', $filter_callback );
		wp_unregister_ability( 'test/filter-invalid-ability' );
	}

	public function test_tool_name_sanitizes_slash_to_hyphen(): void {
		// Verify basic slash-to-hyphen sanitization works.
		$this->register_ability_in_hook(
			'test/slash-ability',
			array(
				'label'               => 'Slash Test',
				'description'         => 'Tests slash to hyphen conversion',
				'category'            => 'test',
				'input_schema'        => array( 'type' => 'object' ),
				'execute_callback'    => static function () {
					return 'ok';
				},
				'permission_callback' => static function () {
					return true;
				},
				'meta'                => array( 'mcp' => array( 'public' => true ) ),
			)
		);

		$ability = wp_get_ability( 'test/slash-ability' );
		$this->assertNotNull( $ability );

		$tool = RegisterAbilityAsMcpTool::make( $ability );
		$this->assertNotWPError( $tool );

		$arr = $tool->toArray();
		$this->assertSame( 'test-slash-ability', $arr['name'] );

		wp_unregister_ability( 'test/slash-ability' );
	}

	// Metadata correctness tests (semantic accuracy of _meta['mcp_adapter']).

	public function test_metadata_omits_output_keys_when_output_schema_absent(): void {
		// Scenario: input transformed (flat string), NO output schema.
		// Expected: input_schema_* keys present, NO output_schema_* keys.
		$this->register_ability_in_hook(
			'test/input-only-transformed',
			array(
				'label'               => 'Input Only Transformed',
				'description'         => 'Has flat input schema, no output schema',
				'category'            => 'test',
				'input_schema'        => array( 'type' => 'string' ), // Will be transformed.
				// No output_schema.
				'execute_callback'    => static function ( $input ) {
					return $input;
				},
				'permission_callback' => static function () {
					return true;
				},
				'meta'                => array( 'mcp' => array( 'public' => true ) ),
			)
		);

		$ability = wp_get_ability( 'test/input-only-transformed' );
		$this->assertNotNull( $ability, 'Ability test/input-only-transformed should be registered' );

		$tool = RegisterAbilityAsMcpTool::make( $ability );
		$this->assertInstanceOf( Tool::class, $tool );

		// Verify input transformation metadata is present.
		$this->assertTrue( ToolMetadataHelper::is_input_transformed( $tool ) );
		$this->assertSame( 'input', ToolMetadataHelper::get_input_wrapper( $tool ) );

		// Verify output transformation metadata is NOT present (no outputSchema).
		$meta         = $tool->get_meta();
		$adapter_meta = $meta['mcp_adapter'] ?? array();

		$this->assertArrayNotHasKey( 'output_schema_transformed', $adapter_meta, 'output_schema_transformed should be omitted when no output schema exists' );
		$this->assertArrayNotHasKey( 'output_schema_wrapper', $adapter_meta, 'output_schema_wrapper should be omitted when no output schema exists' );

		// Verify helper returns stable defaults even without metadata.
		$this->assertFalse( ToolMetadataHelper::is_output_transformed( $tool ) );
		$this->assertSame( 'result', ToolMetadataHelper::get_output_wrapper( $tool ) );

		wp_unregister_ability( 'test/input-only-transformed' );
	}

	public function test_metadata_omits_input_wrapper_when_not_transformed(): void {
		// Scenario: input NOT transformed (object), output transformed (flat string).
		// Expected: NO input_schema_* keys, output_schema_* keys present.
		$this->register_ability_in_hook(
			'test/output-only-transformed',
			array(
				'label'               => 'Output Only Transformed',
				'description'         => 'Has object input schema, flat output schema',
				'category'            => 'test',
				'input_schema'        => array( 'type' => 'object' ), // Will NOT be transformed.
				'output_schema'       => array( 'type' => 'string' ), // Will be transformed.
				'execute_callback'    => static function () {
					return 'result';
				},
				'permission_callback' => static function () {
					return true;
				},
				'meta'                => array( 'mcp' => array( 'public' => true ) ),
			)
		);

		$ability = wp_get_ability( 'test/output-only-transformed' );
		$this->assertNotNull( $ability, 'Ability test/output-only-transformed should be registered' );

		$tool = RegisterAbilityAsMcpTool::make( $ability );
		$this->assertInstanceOf( Tool::class, $tool );

		$meta         = $tool->get_meta();
		$adapter_meta = $meta['mcp_adapter'] ?? array();

		// Verify input transformation metadata is NOT present (not transformed).
		$this->assertArrayNotHasKey( 'input_schema_transformed', $adapter_meta, 'input_schema_transformed should be omitted when input was not transformed' );
		$this->assertArrayNotHasKey( 'input_schema_wrapper', $adapter_meta, 'input_schema_wrapper should be omitted when input was not transformed' );

		// Verify output transformation metadata IS present.
		$this->assertArrayHasKey( 'output_schema_transformed', $adapter_meta, 'output_schema_transformed should be present when output was transformed' );
		$this->assertTrue( $adapter_meta['output_schema_transformed'] );
		$this->assertArrayHasKey( 'output_schema_wrapper', $adapter_meta, 'output_schema_wrapper should be present when output was transformed' );
		$this->assertSame( 'result', $adapter_meta['output_schema_wrapper'] );

		// Verify helper returns stable defaults for input even without metadata.
		$this->assertFalse( ToolMetadataHelper::is_input_transformed( $tool ) );
		$this->assertSame( 'input', ToolMetadataHelper::get_input_wrapper( $tool ) );

		// Verify helper returns correct values for output.
		$this->assertTrue( ToolMetadataHelper::is_output_transformed( $tool ) );
		$this->assertSame( 'result', ToolMetadataHelper::get_output_wrapper( $tool ) );

		wp_unregister_ability( 'test/output-only-transformed' );
	}

	public function test_metadata_only_has_ability_when_no_transformations(): void {
		// Scenario: neither input nor output transformed (both are objects).
		// Expected: only 'ability' key, no transformation keys.
		$this->register_ability_in_hook(
			'test/no-transformations',
			array(
				'label'               => 'No Transformations',
				'description'         => 'Both schemas are already objects',
				'category'            => 'test',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'name' => array( 'type' => 'string' ),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'message' => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => static function ( $input ) {
					return array( 'message' => 'Hello ' . ( $input['name'] ?? 'world' ) );
				},
				'permission_callback' => static function () {
					return true;
				},
				'meta'                => array( 'mcp' => array( 'public' => true ) ),
			)
		);

		$ability = wp_get_ability( 'test/no-transformations' );
		$this->assertNotNull( $ability, 'Ability test/no-transformations should be registered' );

		$tool = RegisterAbilityAsMcpTool::make( $ability );
		$this->assertInstanceOf( Tool::class, $tool );

		$meta         = $tool->get_meta();
		$adapter_meta = $meta['mcp_adapter'] ?? array();

		// Verify only 'ability' key is present.
		$this->assertArrayHasKey( 'ability', $adapter_meta );
		$this->assertSame( 'test/no-transformations', $adapter_meta['ability'] );

		// Verify no transformation keys.
		$this->assertArrayNotHasKey( 'input_schema_transformed', $adapter_meta );
		$this->assertArrayNotHasKey( 'input_schema_wrapper', $adapter_meta );
		$this->assertArrayNotHasKey( 'output_schema_transformed', $adapter_meta );
		$this->assertArrayNotHasKey( 'output_schema_wrapper', $adapter_meta );

		// Verify helpers return stable defaults.
		$this->assertFalse( ToolMetadataHelper::is_input_transformed( $tool ) );
		$this->assertFalse( ToolMetadataHelper::is_output_transformed( $tool ) );
		$this->assertSame( 'input', ToolMetadataHelper::get_input_wrapper( $tool ) );
		$this->assertSame( 'result', ToolMetadataHelper::get_output_wrapper( $tool ) );

		wp_unregister_ability( 'test/no-transformations' );
	}
}
