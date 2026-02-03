<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Tools;

use WP\MCP\Domain\Tools\RegisterAbilityAsMcpTool;
use WP\MCP\Tests\TestCase;
use WP\McpSchema\Server\Tools\DTO\Tool as ToolDto;

final class RegisterAbilityAsMcpToolTest extends TestCase {

	/**
	 * @param \WP_Ability $ability The ability to build into a Tool DTO.
	 */
	private function build_tool_from_ability( \WP_Ability $ability ): ToolDto {
		$built = RegisterAbilityAsMcpTool::build( $ability );
		$this->assertNotWPError( $built );
		$this->assertIsArray( $built );
		$this->assertArrayHasKey( 'tool', $built );
		$this->assertInstanceOf( ToolDto::class, $built['tool'] );

		return $built['tool'];
	}

	public function test_build_builds_tool_from_ability(): void {
		$ability = wp_get_ability( 'test/always-allowed' );
		$this->assertNotNull( $ability, 'Ability test/always-allowed should be registered' );
		$tool = $this->build_tool_from_ability( $ability );
		$arr  = $tool->toArray();
		$this->assertSame( 'test-always-allowed', $arr['name'] );
		$this->assertArrayHasKey( 'inputSchema', $arr );
	}

	public function test_annotations_are_mapped_to_mcp_format(): void {
		$ability = wp_get_ability( 'test/annotated-ability' );
		$this->assertNotNull( $ability, 'Ability test/annotated-ability should be registered' );

		$tool = $this->build_tool_from_ability( $ability );

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

		$tool = $this->build_tool_from_ability( $ability );

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

		$tool = $this->build_tool_from_ability( $ability );

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

		$tool = $this->build_tool_from_ability( $ability );

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

		$tool = $this->build_tool_from_ability( $ability );

		$arr = $tool->toArray();

		// Verify annotations field is not present.
		$this->assertArrayNotHasKey( 'annotations', $arr );
	}

	public function test_all_null_annotations_result_in_no_annotations_field(): void {
		$ability = wp_get_ability( 'test/all-null-annotations' );
		$this->assertNotNull( $ability, 'Ability test/all-null-annotations should be registered' );

		$tool = $this->build_tool_from_ability( $ability );

		$arr = $tool->toArray();

		// Verify annotations field is not present when all values are null.
		$this->assertArrayNotHasKey( 'annotations', $arr );
	}

	public function test_wordpress_format_fields_are_filtered_out(): void {
		$ability = wp_get_ability( 'test/annotated-ability' );
		$this->assertNotNull( $ability, 'Ability test/annotated-ability should be registered' );

		$tool = $this->build_tool_from_ability( $ability );

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

			$tool = $this->build_tool_from_ability( $ability );

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
		$built = RegisterAbilityAsMcpTool::build( $ability );
		$this->assertNotWPError( $built );
		$this->assertInstanceOf( ToolDto::class, $built['tool'] );

		$adapter_meta = $built['adapter_meta'];
		$this->assertSame( 'test/flat-transformed-tool', $adapter_meta['ability'] );
		$this->assertTrue( $adapter_meta['input_schema_transformed'] );
		$this->assertTrue( $adapter_meta['output_schema_transformed'] );
		$this->assertSame( 'input', $adapter_meta['input_schema_wrapper'] );
		$this->assertSame( 'result', $adapter_meta['output_schema_wrapper'] );

		wp_unregister_ability( 'test/flat-transformed-tool' );
	}

	public function test_build_preserves_user_mcp_adapter_meta_key(): void {
		$this->register_ability_in_hook(
			'test/tool-user-meta-mcp-adapter',
			array(
				'label'               => 'Tool User Meta MCP Adapter',
				'description'         => 'Test reserved _meta key stripping',
				'category'            => 'test',
				'input_schema'        => array( 'type' => 'object' ),
				'execute_callback'    => static function () {
					return array( 'ok' => true );
				},
				'permission_callback' => static function () {
					return true;
				},
				'meta'                => array(
					'mcp' => array(
						'_meta' => array(
							'mcp_adapter'       => array( 'spoofed' => true ),
							'public_meta_key'   => 'public_meta_value',
							'public_meta_other' => 'public_meta_other_value',
						),
					),
				),
			)
		);

		$ability = wp_get_ability( 'test/tool-user-meta-mcp-adapter' );
		$this->assertNotNull( $ability );

		$built = RegisterAbilityAsMcpTool::build( $ability );
		$this->assertNotWPError( $built );

		$tool = $built['tool'];
		$this->assertInstanceOf( ToolDto::class, $tool );

			$data = $tool->toArray();
			$this->assertArrayHasKey( '_meta', $data );
			$this->assertSame( 'public_meta_value', $data['_meta']['public_meta_key'] );
			$this->assertSame( 'public_meta_other_value', $data['_meta']['public_meta_other'] );
			$this->assertSame( array( 'spoofed' => true ), $data['_meta']['mcp_adapter'] );

		wp_unregister_ability( 'test/tool-user-meta-mcp-adapter' );
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

			$tool = $this->build_tool_from_ability( $ability );

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

			$built = RegisterAbilityAsMcpTool::build( $ability );
			$this->assertWPError( $built );
			$this->assertSame( 'mcp_tool_name_filter_invalid', $built->get_error_code() );

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

			$tool = $this->build_tool_from_ability( $ability );

			$arr = $tool->toArray();
			$this->assertSame( 'test-slash-ability', $arr['name'] );

		wp_unregister_ability( 'test/slash-ability' );
	}

		// Adapter metadata correctness tests (semantic accuracy of adapter_meta).

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

		$built = RegisterAbilityAsMcpTool::build( $ability );
		$this->assertNotWPError( $built );
		$this->assertInstanceOf( ToolDto::class, $built['tool'] );

		// Verify input transformation metadata is present.
		$adapter_meta = $built['adapter_meta'];
		$this->assertSame( 'test/input-only-transformed', $adapter_meta['ability'] );
		$this->assertTrue( $adapter_meta['input_schema_transformed'] );
		$this->assertSame( 'input', $adapter_meta['input_schema_wrapper'] );

		// Verify output transformation metadata is NOT present (no outputSchema).
		$this->assertArrayNotHasKey( 'output_schema_transformed', $adapter_meta, 'output_schema_transformed should be omitted when no output schema exists' );
		$this->assertArrayNotHasKey( 'output_schema_wrapper', $adapter_meta, 'output_schema_wrapper should be omitted when no output schema exists' );

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

		$built = RegisterAbilityAsMcpTool::build( $ability );
		$this->assertNotWPError( $built );
		$this->assertInstanceOf( ToolDto::class, $built['tool'] );

		$adapter_meta = $built['adapter_meta'];
		$this->assertSame( 'test/output-only-transformed', $adapter_meta['ability'] );

		// Verify input transformation metadata is NOT present (not transformed).
		$this->assertArrayNotHasKey( 'input_schema_transformed', $adapter_meta, 'input_schema_transformed should be omitted when input was not transformed' );
		$this->assertArrayNotHasKey( 'input_schema_wrapper', $adapter_meta, 'input_schema_wrapper should be omitted when input was not transformed' );

		// Verify output transformation metadata IS present.
		$this->assertArrayHasKey( 'output_schema_transformed', $adapter_meta, 'output_schema_transformed should be present when output was transformed' );
		$this->assertTrue( $adapter_meta['output_schema_transformed'] );
		$this->assertArrayHasKey( 'output_schema_wrapper', $adapter_meta, 'output_schema_wrapper should be present when output was transformed' );
		$this->assertSame( 'result', $adapter_meta['output_schema_wrapper'] );

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

		$built = RegisterAbilityAsMcpTool::build( $ability );
		$this->assertNotWPError( $built );
		$this->assertInstanceOf( ToolDto::class, $built['tool'] );

		$adapter_meta = $built['adapter_meta'];

		// Verify only 'ability' key is present.
		$this->assertArrayHasKey( 'ability', $adapter_meta );
		$this->assertSame( 'test/no-transformations', $adapter_meta['ability'] );

		// Verify no transformation keys.
		$this->assertArrayNotHasKey( 'input_schema_transformed', $adapter_meta );
		$this->assertArrayNotHasKey( 'input_schema_wrapper', $adapter_meta );
		$this->assertArrayNotHasKey( 'output_schema_transformed', $adapter_meta );
		$this->assertArrayNotHasKey( 'output_schema_wrapper', $adapter_meta );

		wp_unregister_ability( 'test/no-transformations' );
	}

	// Icons and _meta Mapping Tests (MCP 2025-11-25).

	public function test_icons_are_mapped_from_ability_meta(): void {
		$ability = wp_get_ability( 'test/with-icons' );
		$this->assertNotNull( $ability, 'Ability test/with-icons should be registered' );

		$tool = $this->build_tool_from_ability( $ability );

		$arr = $tool->toArray();

		// Verify icons field exists and has correct structure.
		$this->assertArrayHasKey( 'icons', $arr );
		$this->assertIsArray( $arr['icons'] );
		$this->assertCount( 2, $arr['icons'] );

		// Verify first icon (all fields).
		$this->assertSame( 'https://example.com/icon.png', $arr['icons'][0]['src'] );
		$this->assertSame( 'image/png', $arr['icons'][0]['mimeType'] );
		$this->assertIsArray( $arr['icons'][0]['sizes'] );
		$this->assertSame( array( '32x32' ), $arr['icons'][0]['sizes'] );
		$this->assertSame( 'light', $arr['icons'][0]['theme'] );

		// Verify second icon (no sizes).
		$this->assertSame( 'https://example.com/icon-dark.svg', $arr['icons'][1]['src'] );
		$this->assertSame( 'image/svg+xml', $arr['icons'][1]['mimeType'] );
		$this->assertSame( 'dark', $arr['icons'][1]['theme'] );
	}

	public function test_invalid_icons_are_filtered_out(): void {
		$ability = wp_get_ability( 'test/with-mixed-icons' );
		$this->assertNotNull( $ability, 'Ability test/with-mixed-icons should be registered' );

		$tool = $this->build_tool_from_ability( $ability );

		$arr = $tool->toArray();

		// Verify icons field exists with only valid icons.
		$this->assertArrayHasKey( 'icons', $arr );
		$this->assertIsArray( $arr['icons'] );

		// Should only have 2 valid icons (the one without src was filtered out).
		$this->assertCount( 2, $arr['icons'] );

		// Verify the valid icons are present.
		$srcs = array_column( $arr['icons'], 'src' );
		$this->assertContains( 'https://example.com/valid-icon.png', $srcs );
		$this->assertContains( 'https://example.com/another-valid.svg', $srcs );
	}

		public function test_custom_meta_is_passed_through(): void {
		$ability = wp_get_ability( 'test/with-custom-meta' );
		$this->assertNotNull( $ability, 'Ability test/with-custom-meta should be registered' );

		$built = RegisterAbilityAsMcpTool::build( $ability );
		$this->assertNotWPError( $built );

		$tool = $built['tool'];
		$this->assertInstanceOf( ToolDto::class, $tool );

		$meta = $tool->get_meta();
		$this->assertIsArray( $meta );

		// Verify custom vendor keys are present.
		$this->assertArrayHasKey( 'custom_vendor', $meta );
		$this->assertSame( true, $meta['custom_vendor']['feature_flag'] );
		$this->assertSame( '1.0', $meta['custom_vendor']['version'] );

		$this->assertArrayHasKey( 'another_vendor', $meta );
		$this->assertSame( 'some-value', $meta['another_vendor'] );

			$this->assertSame( 'test/with-custom-meta', $built['adapter_meta']['ability'] );
		}

		public function test_icons_and_meta_can_coexist(): void {
		$ability = wp_get_ability( 'test/with-icons-and-meta' );
		$this->assertNotNull( $ability, 'Ability test/with-icons-and-meta should be registered' );

		$built = RegisterAbilityAsMcpTool::build( $ability );
		$this->assertNotWPError( $built );

		$tool = $built['tool'];
		$this->assertInstanceOf( ToolDto::class, $tool );

		$arr = $tool->toArray();

		// Verify icons field exists.
		$this->assertArrayHasKey( 'icons', $arr );
		$this->assertCount( 1, $arr['icons'] );
		$this->assertSame( 'https://example.com/combined-icon.png', $arr['icons'][0]['src'] );
		$this->assertSame( array( '48x48' ), $arr['icons'][0]['sizes'] );

			// Verify custom _meta exists (internal adapter metadata is not exposed here).
			$meta = $tool->get_meta();
			$this->assertArrayHasKey( 'vendor_info', $meta );
			$this->assertSame( 'test-value', $meta['vendor_info']['custom_data'] );
			$this->assertSame( 'test/with-icons-and-meta', $built['adapter_meta']['ability'] );
		}

	public function test_tool_without_icons_has_no_icons_field(): void {
		// Use an existing ability that doesn't have icons defined.
		$ability = wp_get_ability( 'test/always-allowed' );
		$this->assertNotNull( $ability, 'Ability test/always-allowed should be registered' );

		$tool = $this->build_tool_from_ability( $ability );

		$arr = $tool->toArray();

		// Verify icons field is not present.
		$this->assertArrayNotHasKey( 'icons', $arr );
	}

	public function test_tool_without_custom_meta_has_no_meta_field(): void {
		// Use an existing ability that doesn't have custom _meta defined.
		$ability = wp_get_ability( 'test/always-allowed' );
		$this->assertNotNull( $ability, 'Ability test/always-allowed should be registered' );

		$built = RegisterAbilityAsMcpTool::build( $ability );
		$this->assertNotWPError( $built );

		$tool = $built['tool'];
		$this->assertInstanceOf( ToolDto::class, $tool );

		$data = $tool->toArray();
		$this->assertArrayNotHasKey( '_meta', $data );
		$this->assertSame( 'test/always-allowed', $built['adapter_meta']['ability'] );
	}

	public function test_user_meta_mcp_adapter_collision_is_preserved(): void {
		$this->register_ability_in_hook(
			'test/meta-collision',
			array(
				'label'               => 'Meta Collision Test',
				'description'         => 'Tests _meta collision',
				'category'            => 'test',
				'input_schema'        => array( 'type' => 'object' ),
				'execute_callback'    => static function () {
					return 'success';
				},
				'permission_callback' => static function () {
					return true;
				},
				'meta'                => array(
					'mcp' => array(
						'public' => true,
						'_meta'  => array(
							'mcp_adapter'       => array(
								'spoofed' => 'should-be-overwritten',
								'fake'    => 'data',
							),
							'legitimate_vendor' => 'preserved',
						),
					),
				),
			)
		);

		$ability = wp_get_ability( 'test/meta-collision' );
		$this->assertNotNull( $ability );

		$built = RegisterAbilityAsMcpTool::build( $ability );
		$this->assertNotWPError( $built );

		$tool = $built['tool'];
		$this->assertInstanceOf( ToolDto::class, $tool );

		$data = $tool->toArray();
		$this->assertArrayHasKey( '_meta', $data );
		$this->assertArrayHasKey( 'mcp_adapter', $data['_meta'] );
		$this->assertSame(
			array(
				'spoofed' => 'should-be-overwritten',
				'fake'    => 'data',
			),
			$data['_meta']['mcp_adapter']
		);
		$this->assertArrayHasKey( 'legitimate_vendor', $data['_meta'] );
		$this->assertSame( 'preserved', $data['_meta']['legitimate_vendor'] );
		$this->assertSame( 'test/meta-collision', $built['adapter_meta']['ability'] );

		wp_unregister_ability( 'test/meta-collision' );
	}

	public function test_empty_user_meta_does_not_add_empty_keys(): void {
		// If user provides empty _meta array, it should not affect the output.
		$this->register_ability_in_hook(
			'test/empty-meta',
			array(
				'label'               => 'Empty Meta Test',
				'description'         => 'Tests empty _meta handling',
				'category'            => 'test',
				'input_schema'        => array( 'type' => 'object' ),
				'execute_callback'    => static function () {
					return 'success';
				},
				'permission_callback' => static function () {
					return true;
				},
				'meta'                => array(
					'mcp' => array(
						'public' => true,
						'_meta'  => array(), // Empty _meta array.
					),
				),
			)
		);

			$ability = wp_get_ability( 'test/empty-meta' );
			$this->assertNotNull( $ability );

		$built = RegisterAbilityAsMcpTool::build( $ability );
		$this->assertNotWPError( $built );

		$tool = $built['tool'];
		$this->assertInstanceOf( ToolDto::class, $tool );

		$data = $tool->toArray();
		$this->assertArrayNotHasKey( '_meta', $data );
		$this->assertSame( 'test/empty-meta', $built['adapter_meta']['ability'] );

		wp_unregister_ability( 'test/empty-meta' );
	}
}
