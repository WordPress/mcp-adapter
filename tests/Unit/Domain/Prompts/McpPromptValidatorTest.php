<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Domain\Prompts;

use WP\MCP\Domain\Prompts\McpPrompt;
use WP\MCP\Domain\Prompts\McpPromptValidator;
use WP\MCP\Tests\TestCase;
use WP\McpSchema\Server\Prompts\DTO\Prompt as PromptDto;

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
		$prompt = PromptDto::fromArray(
			array(
				'name' => 'test-prompt',
			)
		);

		$result = McpPromptValidator::validate_prompt_dto( $prompt );
		$this->assertTrue( $result );
	}

	public function test_validate_prompt_dto_rejects_invalid_name(): void {
		$prompt = PromptDto::fromArray(
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
		$prompt = PromptDto::fromArray(
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

	public function test_get_validation_errors_ignores_annotations_field(): void {
		// MCP prompt templates do not have an annotations field in the 2025-11-25 schema.
		$prompt_data = array(
			'name'        => 'test-prompt',
			'annotations' => 'not-an-array',
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

	public function test_validate_prompt_messages_with_valid_resource_link_content(): void {
		$messages = array(
			array(
				'role'    => 'assistant',
				'content' => array(
					'type'        => 'resource_link',
					'name'        => 'test-resource',
					'uri'         => 'test://resource',
					'title'       => 'Test Resource',
					'description' => 'A resource reference',
					'mimeType'    => 'application/json',
					'size'        => 123,
					'annotations' => array(
						'audience' => array( 'user' ),
						'priority' => 0.5,
					),
					'icons'       => array(
						array(
							'src'      => 'https://example.com/icon.png',
							'mimeType' => 'image/png',
						),
					),
				),
			),
		);

		$errors = McpPromptValidator::validate_prompt_messages( $messages );
		$this->assertEmpty( $errors );
	}

	public function test_validate_prompt_messages_with_invalid_resource_link_missing_uri(): void {
		$messages = array(
			array(
				'role'    => 'assistant',
				'content' => array(
					'type' => 'resource_link',
					'name' => 'test-resource',
				),
			),
		);

		$errors = McpPromptValidator::validate_prompt_messages( $messages );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'resource_link content must have a uri field', implode( ' ', $errors ) );
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

	// =========================================================================
	// validate_prompt_instance Tests
	// =========================================================================

	public function test_validate_prompt_instance_with_valid_prompt(): void {
		$mcp_prompt = McpPrompt::fromArray(
			array(
				'name'    => 'test-prompt',
				'handler' => function () {
					return array( 'messages' => array() );
				},
			)
		);

		// Ensure prompt was created successfully
		$this->assertNotWPError( $mcp_prompt );

		$result = McpPromptValidator::validate_prompt_instance( $mcp_prompt );
		$this->assertTrue( $result );
	}

	public function test_validate_prompt_instance_with_invalid_prompt(): void {
		// We cannot create an McpPrompt with invalid name via fromArray
		// because the Prompt DTO allows any name. Instead, let's test
		// validate_prompt_dto directly with an invalid name
		$prompt_dto = PromptDto::fromArray( array( 'name' => 'invalid/name' ) );

		$result = McpPromptValidator::validate_prompt_dto( $prompt_dto );
		$this->assertWPError( $result );
		$this->assertSame( 'mcp_prompt_validation_failed', $result->get_error_code() );
	}

	// =========================================================================
	// Additional Image Content Validation Tests
	// =========================================================================

	public function test_validate_prompt_messages_with_image_missing_data(): void {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => array(
					'type'     => 'image',
					'mimeType' => 'image/png',
					// Missing 'data' field
				),
			),
		);

		$errors = McpPromptValidator::validate_prompt_messages( $messages );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'image content must have a data field', $errors[0] );
	}

	public function test_validate_prompt_messages_with_image_invalid_base64(): void {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => array(
					'type'     => 'image',
					'data'     => '!!!not-valid-base64!!!',
					'mimeType' => 'image/png',
				),
			),
		);

		$errors = McpPromptValidator::validate_prompt_messages( $messages );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'image content data must be valid base64', $errors[0] );
	}

	public function test_validate_prompt_messages_with_image_missing_mime_type(): void {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => array(
					'type' => 'image',
					'data' => base64_encode( 'image-data' ),
					// Missing 'mimeType' field
				),
			),
		);

		$errors = McpPromptValidator::validate_prompt_messages( $messages );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'image content must have a mimeType field', $errors[0] );
	}

	public function test_validate_prompt_messages_with_image_non_string_data(): void {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => array(
					'type'     => 'image',
					'data'     => 12345, // Not a string
					'mimeType' => 'image/png',
				),
			),
		);

		$errors = McpPromptValidator::validate_prompt_messages( $messages );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'image content must have a data field', $errors[0] );
	}

	public function test_validate_prompt_messages_with_image_non_string_mime_type(): void {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => array(
					'type'     => 'image',
					'data'     => base64_encode( 'image-data' ),
					'mimeType' => 12345, // Not a string
				),
			),
		);

		$errors = McpPromptValidator::validate_prompt_messages( $messages );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'image content must have a mimeType field', $errors[0] );
	}

	// =========================================================================
	// Additional Audio Content Validation Tests
	// =========================================================================

	public function test_validate_prompt_messages_with_audio_missing_data(): void {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => array(
					'type'     => 'audio',
					'mimeType' => 'audio/mpeg',
					// Missing 'data' field
				),
			),
		);

		$errors = McpPromptValidator::validate_prompt_messages( $messages );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'audio content must have a data field', $errors[0] );
	}

	public function test_validate_prompt_messages_with_audio_invalid_base64(): void {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => array(
					'type'     => 'audio',
					'data'     => '!!!not-valid-base64!!!',
					'mimeType' => 'audio/mpeg',
				),
			),
		);

		$errors = McpPromptValidator::validate_prompt_messages( $messages );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'audio content data must be valid base64', $errors[0] );
	}

	public function test_validate_prompt_messages_with_audio_missing_mime_type(): void {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => array(
					'type' => 'audio',
					'data' => base64_encode( 'audio-data' ),
					// Missing 'mimeType' field
				),
			),
		);

		$errors = McpPromptValidator::validate_prompt_messages( $messages );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'audio content must have a mimeType field', $errors[0] );
	}

	public function test_validate_prompt_messages_with_audio_non_string_data(): void {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => array(
					'type'     => 'audio',
					'data'     => 12345, // Not a string
					'mimeType' => 'audio/mpeg',
				),
			),
		);

		$errors = McpPromptValidator::validate_prompt_messages( $messages );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'audio content must have a data field', $errors[0] );
	}

	public function test_validate_prompt_messages_with_audio_non_string_mime_type(): void {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => array(
					'type'     => 'audio',
					'data'     => base64_encode( 'audio-data' ),
					'mimeType' => 12345, // Not a string
				),
			),
		);

		$errors = McpPromptValidator::validate_prompt_messages( $messages );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'audio content must have a mimeType field', $errors[0] );
	}

	// =========================================================================
	// Additional Resource Link Content Validation Tests
	// =========================================================================

	public function test_validate_prompt_messages_with_resource_link_missing_name(): void {
		$messages = array(
			array(
				'role'    => 'assistant',
				'content' => array(
					'type' => 'resource_link',
					'uri'  => 'test://resource',
					// Missing 'name' field
				),
			),
		);

		$errors = McpPromptValidator::validate_prompt_messages( $messages );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'resource_link content must have a name field', implode( ' ', $errors ) );
	}

	public function test_validate_prompt_messages_with_resource_link_invalid_uri(): void {
		$messages = array(
			array(
				'role'    => 'assistant',
				'content' => array(
					'type' => 'resource_link',
					'name' => 'test-resource',
					'uri'  => 'invalid uri with spaces',
				),
			),
		);

		$errors = McpPromptValidator::validate_prompt_messages( $messages );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'resource_link content uri must be a valid URI format', implode( ' ', $errors ) );
	}

	public function test_validate_prompt_messages_with_resource_link_non_string_mime_type(): void {
		$messages = array(
			array(
				'role'    => 'assistant',
				'content' => array(
					'type'     => 'resource_link',
					'name'     => 'test-resource',
					'uri'      => 'test://resource',
					'mimeType' => 12345, // Not a string
				),
			),
		);

		$errors = McpPromptValidator::validate_prompt_messages( $messages );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'resource_link content mimeType must be a string', implode( ' ', $errors ) );
	}

	public function test_validate_prompt_messages_with_resource_link_invalid_mime_type_format(): void {
		$messages = array(
			array(
				'role'    => 'assistant',
				'content' => array(
					'type'     => 'resource_link',
					'name'     => 'test-resource',
					'uri'      => 'test://resource',
					'mimeType' => 'invalid-mime-type',
				),
			),
		);

		$errors = McpPromptValidator::validate_prompt_messages( $messages );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'resource_link content mimeType must be a valid MIME type format', implode( ' ', $errors ) );
	}

	public function test_validate_prompt_messages_with_resource_link_invalid_size(): void {
		$messages = array(
			array(
				'role'    => 'assistant',
				'content' => array(
					'type' => 'resource_link',
					'name' => 'test-resource',
					'uri'  => 'test://resource',
					'size' => 'not-an-integer', // Should be int
				),
			),
		);

		$errors = McpPromptValidator::validate_prompt_messages( $messages );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'resource_link content size must be an integer', implode( ' ', $errors ) );
	}

	public function test_validate_prompt_messages_with_resource_link_invalid_title(): void {
		$messages = array(
			array(
				'role'    => 'assistant',
				'content' => array(
					'type'  => 'resource_link',
					'name'  => 'test-resource',
					'uri'   => 'test://resource',
					'title' => 12345, // Should be string
				),
			),
		);

		$errors = McpPromptValidator::validate_prompt_messages( $messages );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'resource_link content title must be a string', implode( ' ', $errors ) );
	}

	public function test_validate_prompt_messages_with_resource_link_invalid_description(): void {
		$messages = array(
			array(
				'role'    => 'assistant',
				'content' => array(
					'type'        => 'resource_link',
					'name'        => 'test-resource',
					'uri'         => 'test://resource',
					'description' => 12345, // Should be string
				),
			),
		);

		$errors = McpPromptValidator::validate_prompt_messages( $messages );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'resource_link content description must be a string', implode( ' ', $errors ) );
	}

	public function test_validate_prompt_messages_with_resource_link_invalid_icons_type(): void {
		$messages = array(
			array(
				'role'    => 'assistant',
				'content' => array(
					'type'  => 'resource_link',
					'name'  => 'test-resource',
					'uri'   => 'test://resource',
					'icons' => 'not-an-array', // Should be array
				),
			),
		);

		$errors = McpPromptValidator::validate_prompt_messages( $messages );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'resource_link content icons must be an array', implode( ' ', $errors ) );
	}

	public function test_validate_prompt_messages_with_resource_link_invalid_icons_content(): void {
		$messages = array(
			array(
				'role'    => 'assistant',
				'content' => array(
					'type'  => 'resource_link',
					'name'  => 'test-resource',
					'uri'   => 'test://resource',
					'icons' => array(
						array( 'src' => 'invalid-url' ), // Invalid icon src
					),
				),
			),
		);

		$errors = McpPromptValidator::validate_prompt_messages( $messages );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'Icon at index', implode( ' ', $errors ) );
	}

	// =========================================================================
	// Additional Resource Content Validation Tests
	// =========================================================================

	public function test_validate_prompt_messages_with_resource_missing_resource_object(): void {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => array(
					'type' => 'resource',
					// Missing 'resource' field
				),
			),
		);

		$errors = McpPromptValidator::validate_prompt_messages( $messages );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'resource content must have a resource object', $errors[0] );
	}

	public function test_validate_prompt_messages_with_resource_non_array_resource(): void {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => array(
					'type'     => 'resource',
					'resource' => 'not-an-array',
				),
			),
		);

		$errors = McpPromptValidator::validate_prompt_messages( $messages );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'resource content must have a resource object', $errors[0] );
	}

	// =========================================================================
	// Additional Text Content Validation Tests
	// =========================================================================

	public function test_validate_prompt_messages_with_text_missing_text_field(): void {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => array(
					'type' => 'text',
					// Missing 'text' field
				),
			),
		);

		$errors = McpPromptValidator::validate_prompt_messages( $messages );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'text content must have a text field', $errors[0] );
	}

	// =========================================================================
	// Additional Content Annotations Tests
	// =========================================================================

	public function test_validate_prompt_messages_with_valid_content_annotations(): void {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => array(
					'type'        => 'text',
					'text'        => 'Hello',
					'annotations' => array(
						'audience' => array( 'user' ),
						'priority' => 0.5,
					),
				),
			),
		);

		$errors = McpPromptValidator::validate_prompt_messages( $messages );
		$this->assertEmpty( $errors );
	}

	// =========================================================================
	// Additional Arguments Edge Case Tests
	// =========================================================================

	public function test_get_validation_errors_with_non_string_argument_name(): void {
		$prompt_data = array(
			'name'      => 'test-prompt',
			'arguments' => array(
				array(
					'name' => 12345, // Should be string
				),
			),
		);

		$errors = McpPromptValidator::get_validation_errors( $prompt_data );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'argument at index 0 must have a non-empty name', $errors[0] );
	}

	// =========================================================================
	// Content Type Edge Cases
	// =========================================================================

	public function test_validate_prompt_messages_with_non_string_content_type(): void {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => array(
					'type' => 12345, // Should be string
					'text' => 'Hello',
				),
			),
		);

		$errors = McpPromptValidator::validate_prompt_messages( $messages );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'content must have a type field', $errors[0] );
	}

	public function test_validate_prompt_messages_with_non_string_role(): void {
		$messages = array(
			array(
				'role'    => 12345, // Should be string
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

	public function test_validate_prompt_messages_with_non_array_content(): void {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'not-an-array',
			),
		);

		$errors = McpPromptValidator::validate_prompt_messages( $messages );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'Message at index 0 must have a content object', $errors[0] );
	}
}
