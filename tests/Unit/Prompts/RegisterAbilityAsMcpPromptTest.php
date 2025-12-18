<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Prompts;

use WP\MCP\Domain\Prompts\PromptMetadataHelper;
use WP\MCP\Domain\Prompts\RegisterAbilityAsMcpPrompt;
use WP\MCP\Tests\TestCase;
use WP\McpSchema\Server\Prompts\Prompt;

final class RegisterAbilityAsMcpPromptTest extends TestCase {

	public function test_make_builds_prompt_from_ability(): void {
		$ability = wp_get_ability( 'test/prompt' );
		$this->assertNotNull( $ability, 'Ability test/prompt should be registered' );
		$prompt = RegisterAbilityAsMcpPrompt::make( $ability );
		$this->assertInstanceOf( Prompt::class, $prompt );
		$arr = $prompt->toArray();
		$this->assertSame( 'test-prompt', $arr['name'] );
		$this->assertArrayHasKey( 'arguments', $arr );
		$this->assertSame( $ability->get_name(), PromptMetadataHelper::get_ability_name( $prompt ) );
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

		$prompt = RegisterAbilityAsMcpPrompt::make( $ability );
		$this->assertNotWPError( $prompt );

		$arr = $prompt->toArray();

		// Should have exactly one argument named 'input'.
		$this->assertArrayHasKey( 'arguments', $arr );
		$this->assertCount( 1, $arr['arguments'] );
		$this->assertSame( 'input', $arr['arguments'][0]['name'] );
		$this->assertSame( 'The code to review', $arr['arguments'][0]['description'] );
		$this->assertTrue( $arr['arguments'][0]['required'] );

		// Should track transformation in _meta.
		$this->assertArrayHasKey( '_meta', $arr );
		$this->assertArrayHasKey( 'mcp_adapter', $arr['_meta'] );
		$this->assertTrue( $arr['_meta']['mcp_adapter']['input_schema_transformed'] );
		$this->assertSame( 'input', $arr['_meta']['mcp_adapter']['input_schema_wrapper'] );
	}

	public function test_flattened_array_schema_creates_single_input_argument(): void {
		$ability = wp_get_ability( 'test/prompt-flattened-array' );
		$this->assertNotNull( $ability, 'Ability test/prompt-flattened-array should be registered' );

		$prompt = RegisterAbilityAsMcpPrompt::make( $ability );
		$this->assertNotWPError( $prompt );

		$arr = $prompt->toArray();

		// Should have exactly one argument named 'input'.
		$this->assertArrayHasKey( 'arguments', $arr );
		$this->assertCount( 1, $arr['arguments'] );
		$this->assertSame( 'input', $arr['arguments'][0]['name'] );
		$this->assertSame( 'List of items to process', $arr['arguments'][0]['description'] );
		$this->assertTrue( $arr['arguments'][0]['required'] );

		// Should track transformation in _meta.
		$this->assertTrue( $arr['_meta']['mcp_adapter']['input_schema_transformed'] );
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

		$prompt = RegisterAbilityAsMcpPrompt::make( $ability );
		$this->assertNotWPError( $prompt );

		$arr = $prompt->toArray();

		// Empty object schema should result in no arguments key.
		$this->assertArrayNotHasKey( 'arguments', $arr );

		// Should NOT track transformation (no wrapping occurred).
		$this->assertArrayNotHasKey( 'input_schema_transformed', $arr['_meta']['mcp_adapter'] );
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

		$prompt = RegisterAbilityAsMcpPrompt::make( $ability );
		$this->assertNotWPError( $prompt );

		$arr = $prompt->toArray();

		// Object schema should have arguments.
		$this->assertArrayHasKey( 'arguments', $arr );
		$this->assertNotEmpty( $arr['arguments'] );

		// Should NOT track transformation (already an object schema).
		$this->assertArrayNotHasKey( 'input_schema_transformed', $arr['_meta']['mcp_adapter'] );
	}

	// =========================================================================
	// Meta Tracking Tests
	// =========================================================================

	public function test_meta_tracks_ability_name(): void {
		$ability = wp_get_ability( 'test/prompt' );
		$this->assertNotNull( $ability );

		$prompt = RegisterAbilityAsMcpPrompt::make( $ability );
		$this->assertNotWPError( $prompt );

		$arr = $prompt->toArray();

		$this->assertArrayHasKey( '_meta', $arr );
		$this->assertArrayHasKey( 'mcp_adapter', $arr['_meta'] );
		$this->assertSame( 'test/prompt', $arr['_meta']['mcp_adapter']['ability'] );
	}

	public function test_meta_tracks_transformation_for_flattened_schema(): void {
		$ability = wp_get_ability( 'test/prompt-flattened-string' );
		$this->assertNotNull( $ability );

		$prompt = RegisterAbilityAsMcpPrompt::make( $ability );
		$this->assertNotWPError( $prompt );

		$arr = $prompt->toArray();

		$this->assertArrayHasKey( '_meta', $arr );
		$adapter_meta = $arr['_meta']['mcp_adapter'];

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

		$prompt = RegisterAbilityAsMcpPrompt::make( $ability );
		$this->assertNotWPError( $prompt );

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
		$this->assertArrayHasKey( '_meta', $arr );
		$this->assertSame( 'explicit', $arr['_meta']['mcp_adapter']['arguments_source'] );
	}

	public function test_explicit_arguments_override_input_schema(): void {
		$ability = wp_get_ability( 'test/prompt-explicit-args-override' );
		$this->assertNotNull( $ability, 'Ability test/prompt-explicit-args-override should be registered' );

		$prompt = RegisterAbilityAsMcpPrompt::make( $ability );
		$this->assertNotWPError( $prompt );

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
		$this->assertSame( 'explicit', $arr['_meta']['mcp_adapter']['arguments_source'] );

		// Verify NO transformation metadata (explicit args bypass schema transform).
		$this->assertArrayNotHasKey( 'input_schema_transformed', $arr['_meta']['mcp_adapter'] );
	}

	public function test_empty_explicit_arguments_falls_back_to_schema(): void {
		$ability = wp_get_ability( 'test/prompt-empty-explicit-args' );
		$this->assertNotNull( $ability, 'Ability test/prompt-empty-explicit-args should be registered' );

		$prompt = RegisterAbilityAsMcpPrompt::make( $ability );
		$this->assertNotWPError( $prompt );

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
		$this->assertSame( 'schema', $arr['_meta']['mcp_adapter']['arguments_source'] );
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

		$prompt = RegisterAbilityAsMcpPrompt::make( $ability );
		$this->assertNotWPError( $prompt );

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
		$this->assertSame( 'explicit', $arr['_meta']['mcp_adapter']['arguments_source'] );
	}

	public function test_arguments_source_tracks_schema_source(): void {
		$ability = wp_get_ability( 'test/prompt' );
		$this->assertNotNull( $ability );

		$prompt = RegisterAbilityAsMcpPrompt::make( $ability );
		$this->assertNotWPError( $prompt );

		$arr = $prompt->toArray();

		// Verify arguments_source is 'schema' for auto-converted arguments.
		$this->assertArrayHasKey( '_meta', $arr );
		$this->assertArrayHasKey( 'arguments_source', $arr['_meta']['mcp_adapter'] );
		$this->assertSame( 'schema', $arr['_meta']['mcp_adapter']['arguments_source'] );
	}

	public function test_no_arguments_has_no_arguments_source(): void {
		$ability = wp_get_ability( 'test/prompt-no-schema' );
		$this->assertNotNull( $ability );

		$prompt = RegisterAbilityAsMcpPrompt::make( $ability );
		$this->assertNotWPError( $prompt );

		$arr = $prompt->toArray();

		// Verify arguments_source is NOT present when there are no arguments.
		$this->assertArrayHasKey( '_meta', $arr );
		$this->assertArrayNotHasKey( 'arguments_source', $arr['_meta']['mcp_adapter'] );
	}
}
