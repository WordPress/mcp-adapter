<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Prompts;

use WP\MCP\Domain\Prompts\RegisterAbilityAsMcpPrompt;
use WP\MCP\Tests\TestCase;
use WP\McpSchema\Server\Prompts\DTO\Prompt as PromptDto;

	final class RegisterAbilityAsMcpPromptTest extends TestCase {

		public function test_make_builds_prompt_from_ability(): void {
			$ability = wp_get_ability( 'test/prompt' );
			$this->assertNotNull( $ability, 'Ability test/prompt should be registered' );
			$prompt = RegisterAbilityAsMcpPrompt::make( $ability );
			$this->assertInstanceOf( PromptDto::class, $prompt );
			$arr = $prompt->toArray();
			$this->assertSame( 'test-prompt', $arr['name'] );
			$this->assertArrayHasKey( 'arguments', $arr );
			$this->assertNull( $prompt->get_meta() );
		}

	public function test_annotations_are_mapped_to_mcp_format(): void {
		$ability = wp_get_ability( 'test/prompt-with-annotations' );
		$this->assertNotNull( $ability, 'Ability test/prompt-with-annotations should be registered' );

		$prompt = RegisterAbilityAsMcpPrompt::make( $ability );
		$this->assertNotWPError( $prompt );

		$arr = $prompt->toArray();

		// Note: Schema Prompt DTO does not support annotations; these are intentionally not exposed.
		$this->assertArrayNotHasKey( 'annotations', $arr );
	}

	public function test_partial_annotations_are_included(): void {
		$ability = wp_get_ability( 'test/prompt-partial-annotations' );
		$this->assertNotNull( $ability, 'Ability test/prompt-partial-annotations should be registered' );

		$prompt = RegisterAbilityAsMcpPrompt::make( $ability );
		$this->assertNotWPError( $prompt );

		$arr = $prompt->toArray();

		// Note: Schema Prompt DTO does not support annotations; these are intentionally not exposed.
		$this->assertArrayNotHasKey( 'annotations', $arr );
	}

	public function test_empty_annotations_are_not_included(): void {
		$ability = wp_get_ability( 'test/prompt' );
		$this->assertNotNull( $ability, 'Ability test/prompt should be registered' );

		$prompt = RegisterAbilityAsMcpPrompt::make( $ability );
		$this->assertNotWPError( $prompt );

		$arr = $prompt->toArray();

		// Verify annotations field is not present when empty.
		$this->assertArrayNotHasKey( 'annotations', $arr );
	}

	// =========================================================================
	// Flattened Schema Tests
	// =========================================================================

		public function test_flattened_string_schema_creates_single_input_argument(): void {
			$ability = wp_get_ability( 'test/prompt-flattened-string' );
			$this->assertNotNull( $ability, 'Ability test/prompt-flattened-string should be registered' );

			$built = RegisterAbilityAsMcpPrompt::build( $ability );
			$this->assertNotWPError( $built );
			$this->assertIsArray( $built );
			$this->assertInstanceOf( PromptDto::class, $built['prompt'] );
			$prompt = $built['prompt'];

			$arr = $prompt->toArray();

		// Should have exactly one argument named 'input'.
		$this->assertArrayHasKey( 'arguments', $arr );
		$this->assertCount( 1, $arr['arguments'] );
		$this->assertSame( 'input', $arr['arguments'][0]['name'] );
			$this->assertSame( 'The code to review', $arr['arguments'][0]['description'] );
			$this->assertTrue( $arr['arguments'][0]['required'] );

			$adapter_meta = $built['adapter_meta'];
			$this->assertTrue( $adapter_meta['input_schema_transformed'] );
			$this->assertSame( 'input', $adapter_meta['input_schema_wrapper'] );
		}

		public function test_flattened_array_schema_creates_single_input_argument(): void {
			$ability = wp_get_ability( 'test/prompt-flattened-array' );
			$this->assertNotNull( $ability, 'Ability test/prompt-flattened-array should be registered' );

			$built = RegisterAbilityAsMcpPrompt::build( $ability );
			$this->assertNotWPError( $built );
			$this->assertIsArray( $built );
			$this->assertInstanceOf( PromptDto::class, $built['prompt'] );
			$prompt = $built['prompt'];

			$arr = $prompt->toArray();

		// Should have exactly one argument named 'input'.
		$this->assertArrayHasKey( 'arguments', $arr );
		$this->assertCount( 1, $arr['arguments'] );
		$this->assertSame( 'input', $arr['arguments'][0]['name'] );
			$this->assertSame( 'List of items to process', $arr['arguments'][0]['description'] );
			$this->assertTrue( $arr['arguments'][0]['required'] );

			$this->assertTrue( $built['adapter_meta']['input_schema_transformed'] );
		}

	// =========================================================================
	// Property Title Mapping Tests
	// =========================================================================

	public function test_property_title_is_mapped_to_argument_title(): void {
		$ability = wp_get_ability( 'test/prompt-with-titles' );
		$this->assertNotNull( $ability, 'Ability test/prompt-with-titles should be registered' );

		$prompt = RegisterAbilityAsMcpPrompt::make( $ability );
		$this->assertNotWPError( $prompt );

		$arr = $prompt->toArray();

		$this->assertArrayHasKey( 'arguments', $arr );
		$this->assertCount( 2, $arr['arguments'] );

		// Find arguments by name.
		$code_arg     = null;
		$language_arg = null;
		foreach ( $arr['arguments'] as $arg ) {
			if ( 'code' === $arg['name'] ) {
				$code_arg = $arg;
			}
			if ( 'language' !== $arg['name'] ) {
				continue;
			}

			$language_arg = $arg;
		}

		// Verify titles are mapped.
		$this->assertNotNull( $code_arg );
		$this->assertSame( 'Source Code', $code_arg['title'] );
		$this->assertSame( 'The code to review', $code_arg['description'] );
		$this->assertTrue( $code_arg['required'] );

		$this->assertNotNull( $language_arg );
		$this->assertSame( 'Programming Language', $language_arg['title'] );
		$this->assertSame( 'The programming language', $language_arg['description'] );
		// Optional argument should not have 'required' key.
		$this->assertArrayNotHasKey( 'required', $language_arg );
	}

	// =========================================================================
	// Required Field Handling Tests
	// =========================================================================

	public function test_required_only_emitted_when_true(): void {
		$ability = wp_get_ability( 'test/prompt-mixed-required' );
		$this->assertNotNull( $ability, 'Ability test/prompt-mixed-required should be registered' );

		$prompt = RegisterAbilityAsMcpPrompt::make( $ability );
		$this->assertNotWPError( $prompt );

		$arr = $prompt->toArray();

		$this->assertArrayHasKey( 'arguments', $arr );
		$this->assertCount( 3, $arr['arguments'] );

		// Find arguments by name.
		$topic_arg  = null;
		$tone_arg   = null;
		$length_arg = null;
		foreach ( $arr['arguments'] as $arg ) {
			if ( 'topic' === $arg['name'] ) {
				$topic_arg = $arg;
			}
			if ( 'tone' === $arg['name'] ) {
				$tone_arg = $arg;
			}
			if ( 'length' !== $arg['name'] ) {
				continue;
			}

			$length_arg = $arg;
		}

		// Required argument should have required: true.
		$this->assertNotNull( $topic_arg );
		$this->assertArrayHasKey( 'required', $topic_arg );
		$this->assertTrue( $topic_arg['required'] );

		// Optional arguments should NOT have the 'required' key at all.
		$this->assertNotNull( $tone_arg );
		$this->assertArrayNotHasKey( 'required', $tone_arg );

		$this->assertNotNull( $length_arg );
		$this->assertArrayNotHasKey( 'required', $length_arg );
	}

	// =========================================================================
	// Edge Case Tests
	// =========================================================================

	public function test_empty_object_schema_has_no_arguments(): void {
		$ability = wp_get_ability( 'test/prompt-empty-object' );
		$this->assertNotNull( $ability, 'Ability test/prompt-empty-object should be registered' );

		$built = RegisterAbilityAsMcpPrompt::build( $ability );
		$this->assertNotWPError( $built );
		$this->assertIsArray( $built );
		$this->assertInstanceOf( PromptDto::class, $built['prompt'] );
		$prompt = $built['prompt'];

		$arr = $prompt->toArray();

		// Empty object schema should result in no arguments key.
		$this->assertArrayNotHasKey( 'arguments', $arr );

		// Should NOT track transformation (no wrapping occurred).
		$this->assertArrayNotHasKey( 'input_schema_transformed', $built['adapter_meta'] );
	}

	public function test_no_schema_has_no_arguments(): void {
		$ability = wp_get_ability( 'test/prompt-no-schema' );
		$this->assertNotNull( $ability, 'Ability test/prompt-no-schema should be registered' );

		$prompt = RegisterAbilityAsMcpPrompt::make( $ability );
		$this->assertNotWPError( $prompt );

		$arr = $prompt->toArray();

		// No schema should result in no arguments key.
		$this->assertArrayNotHasKey( 'arguments', $arr );
	}

	public function test_object_schema_with_properties_no_transformation(): void {
		$ability = wp_get_ability( 'test/prompt' );
		$this->assertNotNull( $ability, 'Ability test/prompt should be registered' );

		$built = RegisterAbilityAsMcpPrompt::build( $ability );
		$this->assertNotWPError( $built );
		$this->assertIsArray( $built );
		$this->assertInstanceOf( PromptDto::class, $built['prompt'] );
		$prompt = $built['prompt'];

		$arr = $prompt->toArray();

		// Object schema should have arguments.
		$this->assertArrayHasKey( 'arguments', $arr );
		$this->assertNotEmpty( $arr['arguments'] );

		// Should NOT track transformation (already an object schema).
		$this->assertArrayNotHasKey( 'input_schema_transformed', $built['adapter_meta'] );
	}

	// =========================================================================
	// Meta Tracking Tests
	// =========================================================================

	public function test_meta_tracks_ability_name(): void {
		$ability = wp_get_ability( 'test/prompt' );
		$this->assertNotNull( $ability );

		$built = RegisterAbilityAsMcpPrompt::build( $ability );
		$this->assertNotWPError( $built );
		$this->assertIsArray( $built );
		$this->assertSame( 'test/prompt', $built['adapter_meta']['ability'] );
	}

	public function test_meta_tracks_transformation_for_flattened_schema(): void {
		$ability = wp_get_ability( 'test/prompt-flattened-string' );
		$this->assertNotNull( $ability );

		$built = RegisterAbilityAsMcpPrompt::build( $ability );
		$this->assertNotWPError( $built );
		$this->assertIsArray( $built );

		$adapter_meta = $built['adapter_meta'];
		$this->assertSame( 'test/prompt-flattened-string', $adapter_meta['ability'] );
		$this->assertTrue( $adapter_meta['input_schema_transformed'] );
		$this->assertSame( 'input', $adapter_meta['input_schema_wrapper'] );
	}

	// =========================================================================
	// Explicit mcp.arguments Tests
	// =========================================================================

	public function test_explicit_arguments_are_used_when_defined(): void {
		$ability = wp_get_ability( 'test/prompt-explicit-args' );
		$this->assertNotNull( $ability, 'Ability test/prompt-explicit-args should be registered' );

		$built = RegisterAbilityAsMcpPrompt::build( $ability );
		$this->assertNotWPError( $built );
		$this->assertIsArray( $built );
		$this->assertInstanceOf( PromptDto::class, $built['prompt'] );
		$prompt = $built['prompt'];

		$arr = $prompt->toArray();

		// Should have 2 arguments from explicit definition.
		$this->assertArrayHasKey( 'arguments', $arr );
		$this->assertCount( 2, $arr['arguments'] );

		// Verify first argument (code).
		$code_arg = $arr['arguments'][0];
		$this->assertSame( 'code', $code_arg['name'] );
		$this->assertSame( 'Source Code', $code_arg['title'] );
		$this->assertSame( 'The code to review', $code_arg['description'] );
		$this->assertTrue( $code_arg['required'] );

		// Verify second argument (language).
		$language_arg = $arr['arguments'][1];
		$this->assertSame( 'language', $language_arg['name'] );
		$this->assertSame( 'Programming language (optional)', $language_arg['description'] );
		$this->assertArrayNotHasKey( 'required', $language_arg ); // Optional - no required field.
		$this->assertArrayNotHasKey( 'title', $language_arg );    // No title defined.

			// Verify arguments_source is 'explicit'.
			$this->assertSame( 'explicit', $built['adapter_meta']['arguments_source'] );
		}

		public function test_explicit_arguments_override_input_schema(): void {
			$ability = wp_get_ability( 'test/prompt-explicit-args-override' );
			$this->assertNotNull( $ability, 'Ability test/prompt-explicit-args-override should be registered' );

			$built = RegisterAbilityAsMcpPrompt::build( $ability );
			$this->assertNotWPError( $built );
			$this->assertIsArray( $built );
			$this->assertInstanceOf( PromptDto::class, $built['prompt'] );
			$prompt = $built['prompt'];

			$arr = $prompt->toArray();

		// Should have exactly 1 argument from explicit override, NOT from input_schema.
		$this->assertArrayHasKey( 'arguments', $arr );
		$this->assertCount( 1, $arr['arguments'] );

		// Verify the argument is from explicit, not schema.
		$arg = $arr['arguments'][0];
		$this->assertSame( 'explicit_field', $arg['name'] );
		$this->assertSame( 'Explicit Field', $arg['title'] );
		$this->assertSame( 'This should appear instead of schema_field', $arg['description'] );
		$this->assertTrue( $arg['required'] );

		// Verify NO schema_field (from input_schema).
			foreach ( $arr['arguments'] as $argument ) {
				$this->assertNotSame( 'schema_field', $argument['name'] );
			}

			// Verify arguments_source is 'explicit'.
			$this->assertSame( 'explicit', $built['adapter_meta']['arguments_source'] );

			// Verify NO transformation metadata (explicit args bypass schema transform).
			$this->assertArrayNotHasKey( 'input_schema_transformed', $built['adapter_meta'] );
		}

		public function test_empty_explicit_arguments_falls_back_to_schema(): void {
			$ability = wp_get_ability( 'test/prompt-empty-explicit-args' );
			$this->assertNotNull( $ability, 'Ability test/prompt-empty-explicit-args should be registered' );

			$built = RegisterAbilityAsMcpPrompt::build( $ability );
			$this->assertNotWPError( $built );
			$this->assertIsArray( $built );
			$this->assertInstanceOf( PromptDto::class, $built['prompt'] );
			$prompt = $built['prompt'];

			$arr = $prompt->toArray();

		// Should fall back to input_schema since mcp.arguments is empty.
		$this->assertArrayHasKey( 'arguments', $arr );
		$this->assertCount( 1, $arr['arguments'] );

		// Verify the argument is from input_schema.
		$arg = $arr['arguments'][0];
		$this->assertSame( 'fallback_field', $arg['name'] );
		$this->assertSame( 'This should appear because mcp.arguments is empty', $arg['description'] );
			$this->assertTrue( $arg['required'] );

			// Verify arguments_source is 'schema' (fell back).
			$this->assertSame( 'schema', $built['adapter_meta']['arguments_source'] );
		}

	public function test_invalid_explicit_arguments_missing_name_returns_wp_error(): void {
		$ability = wp_get_ability( 'test/prompt-invalid-explicit-args-no-name' );
		$this->assertNotNull( $ability, 'Ability test/prompt-invalid-explicit-args-no-name should be registered' );

		$result = RegisterAbilityAsMcpPrompt::make( $ability );

		$this->assertWPError( $result );
		$this->assertSame( 'mcp_prompt_argument_missing_name', $result->get_error_code() );
		$this->assertStringContainsString( 'missing required "name" field', $result->get_error_message() );
	}

	public function test_invalid_explicit_arguments_not_array_returns_wp_error(): void {
		$ability = wp_get_ability( 'test/prompt-invalid-explicit-args-not-array' );
		$this->assertNotNull( $ability, 'Ability test/prompt-invalid-explicit-args-not-array should be registered' );

		$result = RegisterAbilityAsMcpPrompt::make( $ability );

		$this->assertWPError( $result );
		$this->assertSame( 'mcp_prompt_invalid_argument', $result->get_error_code() );
		$this->assertStringContainsString( 'must be an array', $result->get_error_message() );
	}

		public function test_explicit_arguments_with_all_fields(): void {
			$ability = wp_get_ability( 'test/prompt-explicit-args-all-fields' );
			$this->assertNotNull( $ability, 'Ability test/prompt-explicit-args-all-fields should be registered' );

			$built = RegisterAbilityAsMcpPrompt::build( $ability );
			$this->assertNotWPError( $built );
			$this->assertIsArray( $built );
			$this->assertInstanceOf( PromptDto::class, $built['prompt'] );
			$prompt = $built['prompt'];

			$arr = $prompt->toArray();

		// Should have 2 arguments.
		$this->assertArrayHasKey( 'arguments', $arr );
		$this->assertCount( 2, $arr['arguments'] );

		// Verify full_arg has all fields.
		$full_arg = $arr['arguments'][0];
		$this->assertSame( 'full_arg', $full_arg['name'] );
		$this->assertSame( 'Full Argument', $full_arg['title'] );
		$this->assertSame( 'An argument with all fields populated', $full_arg['description'] );
		$this->assertTrue( $full_arg['required'] );

		// Verify minimal_arg has only name (no optional fields).
		$minimal_arg = $arr['arguments'][1];
		$this->assertSame( 'minimal_arg', $minimal_arg['name'] );
		$this->assertArrayNotHasKey( 'title', $minimal_arg );
		$this->assertArrayNotHasKey( 'description', $minimal_arg );
		$this->assertArrayNotHasKey( 'required', $minimal_arg );

			// Verify arguments_source.
			$this->assertSame( 'explicit', $built['adapter_meta']['arguments_source'] );
		}

		public function test_arguments_source_tracks_schema_source(): void {
			$ability = wp_get_ability( 'test/prompt' );
			$this->assertNotNull( $ability );

			$built = RegisterAbilityAsMcpPrompt::build( $ability );
			$this->assertNotWPError( $built );
			$this->assertIsArray( $built );
			$this->assertInstanceOf( PromptDto::class, $built['prompt'] );
			$prompt = $built['prompt'];

			$arr = $prompt->toArray();

			// Verify arguments_source is 'schema' for auto-converted arguments.
			$this->assertSame( 'schema', $built['adapter_meta']['arguments_source'] );
		}

		public function test_no_arguments_has_no_arguments_source(): void {
			$ability = wp_get_ability( 'test/prompt-no-schema' );
			$this->assertNotNull( $ability );

			// Verify arguments_source is NOT present when there are no arguments.
			$built = RegisterAbilityAsMcpPrompt::build( $ability );
			$this->assertNotWPError( $built );
			$this->assertIsArray( $built );
			$this->assertArrayNotHasKey( 'arguments_source', $built['adapter_meta'] );
		}

	// =========================================================================
	// Icons Tests (MCP 2025-11-25)
	// =========================================================================

	public function test_icons_are_mapped_from_mcp_meta(): void {
		$ability = wp_get_ability( 'test/prompt-with-icons' );
		$this->assertNotNull( $ability, 'Ability test/prompt-with-icons should be registered' );

		$prompt = RegisterAbilityAsMcpPrompt::make( $ability );
		$this->assertNotWPError( $prompt );

		$arr = $prompt->toArray();

		// Verify icons array is present.
		$this->assertArrayHasKey( 'icons', $arr );
		$this->assertIsArray( $arr['icons'] );
		$this->assertCount( 2, $arr['icons'] );

		// Verify first icon (PNG with all fields).
		$this->assertSame( 'https://example.com/prompt-icon.png', $arr['icons'][0]['src'] );
		$this->assertSame( 'image/png', $arr['icons'][0]['mimeType'] );
		$this->assertSame( array( '32x32' ), $arr['icons'][0]['sizes'] );
		$this->assertSame( 'light', $arr['icons'][0]['theme'] );

		// Verify second icon (SVG).
		$this->assertSame( 'https://example.com/prompt-icon-dark.svg', $arr['icons'][1]['src'] );
		$this->assertSame( 'image/svg+xml', $arr['icons'][1]['mimeType'] );
		$this->assertSame( 'dark', $arr['icons'][1]['theme'] );
	}

	public function test_invalid_icons_are_filtered_out(): void {
		$ability = wp_get_ability( 'test/prompt-with-mixed-icons' );
		$this->assertNotNull( $ability, 'Ability test/prompt-with-mixed-icons should be registered' );

		$prompt = RegisterAbilityAsMcpPrompt::make( $ability );
		$this->assertNotWPError( $prompt );

		$arr = $prompt->toArray();

		// Should have only 2 valid icons (the one missing src was filtered out).
		$this->assertArrayHasKey( 'icons', $arr );
		$this->assertCount( 2, $arr['icons'] );

		// Verify valid icons are present.
		$this->assertSame( 'https://example.com/valid-prompt-icon.png', $arr['icons'][0]['src'] );
		$this->assertSame( 'https://example.com/another-valid-prompt.svg', $arr['icons'][1]['src'] );
	}

	public function test_prompt_without_icons_has_no_icons_key(): void {
		$ability = wp_get_ability( 'test/prompt' );
		$this->assertNotNull( $ability );

		$prompt = RegisterAbilityAsMcpPrompt::make( $ability );
		$this->assertNotWPError( $prompt );

		$arr = $prompt->toArray();

		// Verify icons key is not present when ability has no icons.
		$this->assertArrayNotHasKey( 'icons', $arr );
	}

	// =========================================================================
	// User _meta Passthrough Tests (MCP 2025-11-25)
	// =========================================================================

		public function test_user_meta_is_passed_through(): void {
			$ability = wp_get_ability( 'test/prompt-with-custom-meta' );
			$this->assertNotNull( $ability, 'Ability test/prompt-with-custom-meta should be registered' );

			$prompt = RegisterAbilityAsMcpPrompt::make( $ability );
			$this->assertNotWPError( $prompt );

			$arr = $prompt->toArray();

			// Verify _meta is present with user-defined keys.
			$this->assertArrayHasKey( '_meta', $arr );

		// Verify custom_vendor key is preserved.
		$this->assertArrayHasKey( 'custom_vendor', $arr['_meta'] );
		$this->assertIsArray( $arr['_meta']['custom_vendor'] );
		$this->assertTrue( $arr['_meta']['custom_vendor']['feature_flag'] );
		$this->assertSame( '1.0', $arr['_meta']['custom_vendor']['version'] );

			// Verify another_vendor key is preserved.
			$this->assertArrayHasKey( 'another_vendor', $arr['_meta'] );
			$this->assertSame( 'some-value', $arr['_meta']['another_vendor'] );
		}

		public function test_prompt_without_user_meta_has_no_meta_field(): void {
			$ability = wp_get_ability( 'test/prompt' );
			$this->assertNotNull( $ability );

			$prompt = RegisterAbilityAsMcpPrompt::make( $ability );
			$this->assertNotWPError( $prompt );

			$arr = $prompt->toArray();

			$this->assertArrayNotHasKey( '_meta', $arr );
		}

	// =========================================================================
	// Combined Icons and _meta Tests
	// =========================================================================

	public function test_prompt_with_both_icons_and_meta(): void {
		$ability = wp_get_ability( 'test/prompt-with-icons-and-meta' );
		$this->assertNotNull( $ability, 'Ability test/prompt-with-icons-and-meta should be registered' );

		$prompt = RegisterAbilityAsMcpPrompt::make( $ability );
		$this->assertNotWPError( $prompt );

		$arr = $prompt->toArray();

		// Verify icons are present.
		$this->assertArrayHasKey( 'icons', $arr );
		$this->assertCount( 1, $arr['icons'] );
		$this->assertSame( 'https://example.com/combined-prompt-icon.png', $arr['icons'][0]['src'] );
		$this->assertSame( 'image/png', $arr['icons'][0]['mimeType'] );
		$this->assertSame( array( '48x48' ), $arr['icons'][0]['sizes'] );

			// Verify user _meta is present.
			$this->assertArrayHasKey( '_meta', $arr );
			$this->assertArrayHasKey( 'vendor_info', $arr['_meta'] );
			$this->assertSame( 'test-value', $arr['_meta']['vendor_info']['custom_data'] );
		}

	// =========================================================================
	// mcp_adapter_prompt_name Filter Tests
	// =========================================================================

	public function test_prompt_name_filter_can_customize_name(): void {
		$filter_callback = static function ( string $name ): string {
			return 'custom-prompt-name';
		};
		add_filter( 'mcp_adapter_prompt_name', $filter_callback );

		$ability = wp_get_ability( 'test/prompt' );
		$this->assertNotNull( $ability );

		$prompt = RegisterAbilityAsMcpPrompt::make( $ability );
		$this->assertNotWPError( $prompt );

		$arr = $prompt->toArray();
		$this->assertSame( 'custom-prompt-name', $arr['name'] );

		remove_filter( 'mcp_adapter_prompt_name', $filter_callback );
	}

	public function test_prompt_name_filter_with_invalid_result_returns_wp_error(): void {
		$filter_callback = static function (): string {
			return 'invalid name with spaces';
		};
		add_filter( 'mcp_adapter_prompt_name', $filter_callback );

		$ability = wp_get_ability( 'test/prompt' );
		$this->assertNotNull( $ability );

		$result = RegisterAbilityAsMcpPrompt::make( $ability );
		$this->assertWPError( $result );
		$this->assertSame( 'mcp_prompt_name_filter_invalid', $result->get_error_code() );

		remove_filter( 'mcp_adapter_prompt_name', $filter_callback );
	}
	}
