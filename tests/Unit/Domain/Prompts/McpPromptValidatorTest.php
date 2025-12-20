<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Domain\Prompts;

use WP\MCP\Domain\Prompts\McpPromptValidator;
use WP\MCP\Tests\TestCase;
use WP\McpSchema\Server\Prompts\Prompt;

/**
 * Tests for McpPromptValidator class.
 *
 * @covers \WP\MCP\Domain\Prompts\McpPromptValidator
 */
final class McpPromptValidatorTest extends TestCase {

	// =========================================================================
	// validate_prompt_dto Tests
	// =========================================================================

	public function test_validate_prompt_dto_with_valid_prompt(): void {
		$prompt = Prompt::fromArray(
			array(
				'name' => 'test-prompt',
			)
		);

		$result = McpPromptValidator::validate_prompt_dto( $prompt );
		$this->assertTrue( $result );
	}

	public function test_validate_prompt_dto_rejects_invalid_name(): void {
		$prompt = Prompt::fromArray(
			array(
				'name' => 'invalid/name',
			)
		);

		$result = McpPromptValidator::validate_prompt_dto( $prompt );
		$this->assertWPError( $result );
		$this->assertSame( 'mcp_prompt_validation_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'Prompt name', $result->get_error_message() );
	}

	public function test_validate_prompt_dto_rejects_invalid_icons(): void {
		$prompt = Prompt::fromArray(
			array(
				'name'  => 'test-prompt',
				'icons' => array(
					array(
						'src'      => 'https://example.com/icon.png',
						'mimeType' => 'image/png',
					),
					array( 'src' => 'invalid-url' ), // Invalid src
				),
			)
		);

		$result = McpPromptValidator::validate_prompt_dto( $prompt );
		$this->assertWPError( $result );
		$this->assertStringContainsString( 'Icon at index 1', $result->get_error_message() );
	}

	// =========================================================================
	// validate_prompt_data Tests
	// =========================================================================

	public function test_validate_prompt_data_with_valid_data(): void {
		$prompt_data = array(
			'name'        => 'test-prompt',
			'title'       => 'Test Prompt',
			'description' => 'A test prompt',
		);

		$result = McpPromptValidator::validate_prompt_data( $prompt_data );
		$this->assertTrue( $result );
	}

	public function test_validate_prompt_data_with_context_in_error_message(): void {
		$prompt_data = array(
			'name' => '', // Invalid empty name
		);

		$result = McpPromptValidator::validate_prompt_data( $prompt_data, 'TestContext' );
		$this->assertWPError( $result );
		$this->assertStringContainsString( '[TestContext]', $result->get_error_message() );
	}

	// =========================================================================
	// get_validation_errors Tests
	// =========================================================================

	public function test_get_validation_errors_with_valid_prompt_data(): void {
		$prompt_data = array(
			'name'        => 'test-prompt',
			'title'       => 'Test Prompt',
			'description' => 'A test prompt',
		);

		$errors = McpPromptValidator::get_validation_errors( $prompt_data );
		$this->assertEmpty( $errors );
	}

	public function test_get_validation_errors_with_missing_name(): void {
		$prompt_data = array(
			'title' => 'Test',
		);

		$errors = McpPromptValidator::get_validation_errors( $prompt_data );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'name is required', $errors[0] );
	}

	public function test_get_validation_errors_with_empty_name(): void {
		$prompt_data = array(
			'name' => '',
		);

		$errors = McpPromptValidator::get_validation_errors( $prompt_data );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'name is required', $errors[0] );
	}

	public function test_get_validation_errors_with_non_string_name(): void {
		$prompt_data = array(
			'name' => 123,
		);

		$errors = McpPromptValidator::get_validation_errors( $prompt_data );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'name is required', $errors[0] );
	}

	public function test_get_validation_errors_with_invalid_name_format(): void {
		$prompt_data = array(
			'name' => 'invalid/name',
		);

		$errors = McpPromptValidator::get_validation_errors( $prompt_data );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'name is required', $errors[0] );
	}

	public function test_get_validation_errors_with_non_string_title(): void {
		$prompt_data = array(
			'name'  => 'test-prompt',
			'title' => 123,
		);

		$errors = McpPromptValidator::get_validation_errors( $prompt_data );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'title must be a string', $errors[0] );
	}

	public function test_get_validation_errors_with_non_string_description(): void {
		$prompt_data = array(
			'name'        => 'test-prompt',
			'description' => array( 'desc' ),
		);

		$errors = McpPromptValidator::get_validation_errors( $prompt_data );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'description must be a string', $errors[0] );
	}

	public function test_get_validation_errors_with_non_array_annotations(): void {
		$prompt_data = array(
			'name'        => 'test-prompt',
			'annotations' => 'not-an-array',
		);

		$errors = McpPromptValidator::get_validation_errors( $prompt_data );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'annotations must be an array', $errors[0] );
	}

	public function test_get_validation_errors_with_valid_annotations(): void {
		$prompt_data = array(
			'name'        => 'test-prompt',
			'annotations' => array(
				'audience' => array( 'user', 'assistant' ),
				'priority' => 0.5,
			),
		);

		$errors = McpPromptValidator::get_validation_errors( $prompt_data );
		$this->assertEmpty( $errors );
	}

	// =========================================================================
	// Arguments Validation Tests
	// =========================================================================

	public function test_get_validation_errors_with_non_array_arguments(): void {
		$prompt_data = array(
			'name'      => 'test-prompt',
			'arguments' => 'not-an-array',
		);

		$errors = McpPromptValidator::get_validation_errors( $prompt_data );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'arguments must be an array', $errors[0] );
	}

	public function test_get_validation_errors_with_valid_arguments(): void {
		$prompt_data = array(
			'name'      => 'test-prompt',
			'arguments' => array(
				array(
					'name'        => 'arg1',
					'description' => 'First argument',
					'required'    => true,
				),
				array(
					'name'        => 'arg2',
					'description' => 'Second argument',
					'required'    => false,
				),
			),
		);

		$errors = McpPromptValidator::get_validation_errors( $prompt_data );
		$this->assertEmpty( $errors );
	}

	public function test_get_validation_errors_with_non_object_argument(): void {
		$prompt_data = array(
			'name'      => 'test-prompt',
			'arguments' => array(
				'not-an-object',
			),
		);

		$errors = McpPromptValidator::get_validation_errors( $prompt_data );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'argument at index 0 must be an object', $errors[0] );
	}

	public function test_get_validation_errors_with_missing_argument_name(): void {
		$prompt_data = array(
			'name'      => 'test-prompt',
			'arguments' => array(
				array(
					'description' => 'Test arg',
				),
			),
		);

		$errors = McpPromptValidator::get_validation_errors( $prompt_data );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'argument at index 0 must have a non-empty name', $errors[0] );
	}

	public function test_get_validation_errors_with_empty_argument_name(): void {
		$prompt_data = array(
			'name'      => 'test-prompt',
			'arguments' => array(
				array(
					'name' => '',
				),
			),
		);

		$errors = McpPromptValidator::get_validation_errors( $prompt_data );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'argument at index 0 must have a non-empty name', $errors[0] );
	}

	public function test_get_validation_errors_with_invalid_argument_name_format(): void {
		$prompt_data = array(
			'name'      => 'test-prompt',
			'arguments' => array(
				array(
					'name' => 'invalid/name',
				),
			),
		);

		$errors = McpPromptValidator::get_validation_errors( $prompt_data );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'invalid/name', $errors[0] );
	}

	public function test_get_validation_errors_with_non_string_argument_description(): void {
		$prompt_data = array(
			'name'      => 'test-prompt',
			'arguments' => array(
				array(
					'name'        => 'arg1',
					'description' => 123,
				),
			),
		);

		$errors = McpPromptValidator::get_validation_errors( $prompt_data );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'arg1', $errors[0] );
		$this->assertStringContainsString( 'description must be a string', $errors[0] );
	}

	public function test_get_validation_errors_with_non_boolean_argument_required(): void {
		$prompt_data = array(
			'name'      => 'test-prompt',
			'arguments' => array(
				array(
					'name'     => 'arg1',
					'required' => 'true', // Should be boolean
				),
			),
		);

		$errors = McpPromptValidator::get_validation_errors( $prompt_data );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'arg1', $errors[0] );
		$this->assertStringContainsString( 'required field must be a boolean', $errors[0] );
	}

	public function test_get_validation_errors_allows_missing_argument_required_field(): void {
		$prompt_data = array(
			'name'      => 'test-prompt',
			'arguments' => array(
				array(
					'name' => 'arg1',
					// 'required' is optional
				),
			),
		);

		$errors = McpPromptValidator::get_validation_errors( $prompt_data );
		$this->assertEmpty( $errors );
	}

	// =========================================================================
	// validate_prompt_messages Tests
	// =========================================================================

	public function test_validate_prompt_messages_with_valid_text_message(): void {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => array(
					'type' => 'text',
					'text' => 'Hello',
				),
			),
		);

		$errors = McpPromptValidator::validate_prompt_messages( $messages );
		$this->assertEmpty( $errors );
	}

	public function test_validate_prompt_messages_with_valid_roles(): void {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => array(
					'type' => 'text',
					'text' => 'User message',
				),
			),
			array(
				'role'    => 'assistant',
				'content' => array(
					'type' => 'text',
					'text' => 'Assistant message',
				),
			),
		);

		$errors = McpPromptValidator::validate_prompt_messages( $messages );
		$this->assertEmpty( $errors );
	}

	public function test_validate_prompt_messages_with_non_object_message(): void {
		$messages = array( 'not-an-object' );

		$errors = McpPromptValidator::validate_prompt_messages( $messages );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'Message at index 0 must be an object', $errors[0] );
	}

	public function test_validate_prompt_messages_with_missing_role(): void {
		$messages = array(
			array(
				'content' => array(
					'type' => 'text',
					'text' => 'Hello',
				),
			),
		);

		$errors = McpPromptValidator::validate_prompt_messages( $messages );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'Message at index 0 must have a role field', $errors[0] );
	}

	public function test_validate_prompt_messages_with_invalid_role(): void {
		$messages = array(
			array(
				'role'    => 'system', // Invalid role
				'content' => array(
					'type' => 'text',
					'text' => 'Hello',
				),
			),
		);

		$errors = McpPromptValidator::validate_prompt_messages( $messages );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'role must be either \'user\' or \'assistant\'', $errors[0] );
	}

	public function test_validate_prompt_messages_with_missing_content(): void {
		$messages = array(
			array(
				'role' => 'user',
			),
		);

		$errors = McpPromptValidator::validate_prompt_messages( $messages );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'Message at index 0 must have a content object', $errors[0] );
	}

	public function test_validate_prompt_messages_with_missing_content_type(): void {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => array(
					'text' => 'Hello',
				),
			),
		);

		$errors = McpPromptValidator::validate_prompt_messages( $messages );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'content must have a type field', $errors[0] );
	}

	public function test_validate_prompt_messages_with_invalid_text_content(): void {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => array(
					'type' => 'text',
					'text' => 123, // Should be string
				),
			),
		);

		$errors = McpPromptValidator::validate_prompt_messages( $messages );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'text content must have a text field', $errors[0] );
	}

	public function test_validate_prompt_messages_allows_empty_text_content(): void {
		// MCP spec allows empty text content - empty string is valid.
		$messages = array(
			array(
				'role'    => 'user',
				'content' => array(
					'type' => 'text',
					'text' => '', // Empty string is valid per MCP spec
				),
			),
		);

		$errors = McpPromptValidator::validate_prompt_messages( $messages );
		$this->assertEmpty( $errors, 'Empty text content should be valid per MCP spec' );
	}

	public function test_validate_prompt_messages_with_valid_image_content(): void {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => array(
					'type'     => 'image',
					'data'     => base64_encode( 'image-data' ),
					'mimeType' => 'image/png',
				),
			),
		);

		$errors = McpPromptValidator::validate_prompt_messages( $messages );
		$this->assertEmpty( $errors );
	}

	public function test_validate_prompt_messages_with_invalid_image_mime_type(): void {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => array(
					'type'     => 'image',
					'data'     => base64_encode( 'image-data' ),
					'mimeType' => 'text/plain', // Invalid for image
				),
			),
		);

		$errors = McpPromptValidator::validate_prompt_messages( $messages );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'image content must have a valid image MIME type', $errors[0] );
	}

	public function test_validate_prompt_messages_with_valid_audio_content(): void {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => array(
					'type'     => 'audio',
					'data'     => base64_encode( 'audio-data' ),
					'mimeType' => 'audio/mpeg',
				),
			),
		);

		$errors = McpPromptValidator::validate_prompt_messages( $messages );
		$this->assertEmpty( $errors );
	}

	public function test_validate_prompt_messages_with_invalid_audio_mime_type(): void {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => array(
					'type'     => 'audio',
					'data'     => base64_encode( 'audio-data' ),
					'mimeType' => 'text/plain', // Invalid for audio
				),
			),
		);

		$errors = McpPromptValidator::validate_prompt_messages( $messages );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'audio content must have a valid audio MIME type', $errors[0] );
	}

	public function test_validate_prompt_messages_with_valid_resource_content(): void {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => array(
					'type'     => 'resource',
					'resource' => array(
						'uri'  => 'test://resource',
						'text' => 'Resource content',
					),
				),
			),
		);

		$errors = McpPromptValidator::validate_prompt_messages( $messages );
		$this->assertEmpty( $errors );
	}

	public function test_validate_prompt_messages_with_invalid_resource_content(): void {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => array(
					'type'     => 'resource',
					'resource' => array(
						'uri' => 'invalid uri', // Invalid URI
					),
				),
			),
		);

		$errors = McpPromptValidator::validate_prompt_messages( $messages );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'embedded resource', $errors[0] );
	}

	public function test_validate_prompt_messages_with_unsupported_content_type(): void {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => array(
					'type' => 'unsupported',
				),
			),
		);

		$errors = McpPromptValidator::validate_prompt_messages( $messages );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'content type \'unsupported\' is not supported', $errors[0] );
	}

	public function test_validate_prompt_messages_with_invalid_content_annotations(): void {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => array(
					'type'        => 'text',
					'text'        => 'Hello',
					'annotations' => 'not-an-array',
				),
			),
		);

		$errors = McpPromptValidator::validate_prompt_messages( $messages );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'content annotations must be an array', $errors[0] );
	}

	// =========================================================================
	// Edge Cases and Multiple Errors
	// =========================================================================

	public function test_get_validation_errors_reports_multiple_errors(): void {
		$prompt_data = array(
			'name'        => '', // Invalid
			'title'       => 123, // Invalid
			'description' => array(), // Invalid
		);

		$errors = McpPromptValidator::get_validation_errors( $prompt_data );
		$this->assertCount( 3, $errors, 'Should report all validation errors' );
	}

	public function test_validate_prompt_messages_reports_multiple_message_errors(): void {
		$messages = array(
			array( 'role' => 'invalid' ), // Missing content, invalid role
			array( 'role' => 'user' ), // Missing content
		);

		$errors = McpPromptValidator::validate_prompt_messages( $messages );
		$this->assertGreaterThan( 1, count( $errors ), 'Should report multiple errors' );
	}
}
