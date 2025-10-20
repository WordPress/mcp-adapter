<?php
/**
 * Tests for McpPromptValidator class.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Domain\Prompts;

use WP\MCP\Domain\Prompts\McpPrompt;
use WP\MCP\Domain\Prompts\McpPromptValidator;
use WP\MCP\Tests\TestCase;

/**
 * Test McpPromptValidator functionality.
 */
final class McpPromptValidatorTest extends TestCase {

	public function test_validate_prompt_data_with_valid_data(): void {
		$valid_prompt_data = array(
			'name'        => 'test-prompt',
			'title'       => 'Test Prompt',
			'description' => 'A test prompt for validation',
			'arguments'   => array(
				array(
					'name'        => 'input',
					'description' => 'Input parameter',
					'required'    => true,
				),
				array(
					'name'        => 'optional',
					'description' => 'Optional parameter',
				),
			),
			'annotations' => array( 'category' => 'test' ),
		);

		$result = McpPromptValidator::validate_prompt_data( $valid_prompt_data, 'test-context' );
		$this->assertTrue( $result );
	}

	public function test_validate_prompt_data_with_missing_name(): void {
		$invalid_prompt_data = array(
			'title'       => 'Test Prompt',
			'description' => 'A test prompt',
		);

		$result = McpPromptValidator::validate_prompt_data( $invalid_prompt_data );

		$this->assertWPError( $result );
		$this->assertEquals( 'prompt_validation_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'Prompt validation failed', $result->get_error_message() );
		$this->assertStringContainsString( 'Prompt name is required', $result->get_error_message() );
	}

	public function test_validate_prompt_data_with_invalid_name(): void {
		$invalid_prompt_data = array(
			'name'        => 'invalid name with spaces!',
			'description' => 'A test prompt',
		);

		$result = McpPromptValidator::validate_prompt_data( $invalid_prompt_data );

		$this->assertWPError( $result );
		$this->assertEquals( 'prompt_validation_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'Prompt name is required and must only contain letters, numbers, hyphens (-), and underscores (_)', $result->get_error_message() );
	}

	public function test_validate_prompt_data_with_invalid_title(): void {
		$invalid_prompt_data = array(
			'name'  => 'test-prompt',
			'title' => 123, // Should be string
		);

		$result = McpPromptValidator::validate_prompt_data( $invalid_prompt_data );

		$this->assertWPError( $result );
		$this->assertEquals( 'prompt_validation_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'Prompt title must be a string if provided', $result->get_error_message() );
	}

	public function test_validate_prompt_data_with_invalid_description(): void {
		$invalid_prompt_data = array(
			'name'        => 'test-prompt',
			'description' => array(), // Should be string
		);

		$result = McpPromptValidator::validate_prompt_data( $invalid_prompt_data );

		$this->assertWPError( $result );
		$this->assertEquals( 'prompt_validation_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'Prompt description must be a string if provided', $result->get_error_message() );
	}

	public function test_validate_prompt_data_with_invalid_arguments(): void {
		$invalid_prompt_data = array(
			'name'      => 'test-prompt',
			'arguments' => 'not-an-array',
		);

		$result = McpPromptValidator::validate_prompt_data( $invalid_prompt_data );

		$this->assertWPError( $result );
		$this->assertEquals( 'prompt_validation_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'Prompt arguments must be an array if provided', $result->get_error_message() );
	}

	public function test_validate_prompt_data_with_invalid_argument_structure(): void {
		$invalid_prompt_data = array(
			'name'      => 'test-prompt',
			'arguments' => array(
				'not-an-object', // Should be an array/object
			),
		);

		$result = McpPromptValidator::validate_prompt_data( $invalid_prompt_data );

		$this->assertWPError( $result );
		$this->assertEquals( 'prompt_validation_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'Prompt argument at index 0 must be an object', $result->get_error_message() );
	}

	public function test_validate_prompt_data_with_missing_argument_name(): void {
		$invalid_prompt_data = array(
			'name'      => 'test-prompt',
			'arguments' => array(
				array(
					'description' => 'Missing name',
				),
			),
		);

		$result = McpPromptValidator::validate_prompt_data( $invalid_prompt_data );

		$this->assertWPError( $result );
		$this->assertEquals( 'prompt_validation_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'Prompt argument at index 0 must have a non-empty name string', $result->get_error_message() );
	}

	public function test_validate_prompt_data_with_invalid_argument_name(): void {
		$invalid_prompt_data = array(
			'name'      => 'test-prompt',
			'arguments' => array(
				array(
					'name'        => 'invalid name with spaces!',
					'description' => 'Invalid argument name',
				),
			),
		);

		$result = McpPromptValidator::validate_prompt_data( $invalid_prompt_data );

		$this->assertWPError( $result );
		$this->assertEquals( 'prompt_validation_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'name must only contain letters, numbers, hyphens (-), and underscores (_)', $result->get_error_message() );
	}

	public function test_validate_prompt_data_with_invalid_annotations(): void {
		$invalid_prompt_data = array(
			'name'        => 'test-prompt',
			'annotations' => 'not-an-array',
		);

		$result = McpPromptValidator::validate_prompt_data( $invalid_prompt_data );

		$this->assertWPError( $result );
		$this->assertEquals( 'prompt_validation_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'Prompt annotations must be an array if provided', $result->get_error_message() );
	}

	public function test_validate_prompt_name_with_valid_names(): void {
		$valid_names = array(
			'simple-prompt',
			'prompt_with_underscores',
			'prompt123',
			'a',
			'very-long-prompt-name-that-is-still-under-255-characters',
		);

		foreach ( $valid_names as $name ) {
			$this->assertTrue( McpPromptValidator::validate_prompt_name( $name ), "Name '{$name}' should be valid" );
		}
	}

	public function test_validate_prompt_name_with_invalid_names(): void {
		$invalid_names = array(
			'',                           // Empty
			'prompt with spaces',         // Spaces
			'prompt@invalid',            // Special characters
			'prompt.invalid',            // Dots
			str_repeat( 'a', 256 ),      // Too long
		);

		foreach ( $invalid_names as $name ) {
			$this->assertFalse( McpPromptValidator::validate_prompt_name( $name ), "Name '{$name}' should be invalid" );
		}
	}

	public function test_validate_argument_name_with_valid_names(): void {
		$valid_names = array(
			'simple-arg',
			'arg_with_underscores',
			'arg123',
			'a',
		);

		foreach ( $valid_names as $name ) {
			$this->assertTrue( McpPromptValidator::validate_argument_name( $name ), "Argument name '{$name}' should be valid" );
		}
	}

	public function test_validate_argument_name_with_invalid_names(): void {
		$invalid_names = array(
			'',                           // Empty
			'arg with spaces',            // Spaces
			'arg@invalid',               // Special characters
			str_repeat( 'a', 65 ),       // Too long (over 64 chars)
		);

		foreach ( $invalid_names as $name ) {
			$this->assertFalse( McpPromptValidator::validate_argument_name( $name ), "Argument name '{$name}' should be invalid" );
		}
	}

	public function test_validate_base64_with_valid_data(): void {
		$valid_base64_strings = array(
			'SGVsbG8gV29ybGQ=',           // "Hello World"
			'VGVzdCBkYXRh',              // "Test data"
			'',                          // Empty (should be invalid)
		);

		$this->assertTrue( McpPromptValidator::validate_base64( $valid_base64_strings[0] ) );
		$this->assertTrue( McpPromptValidator::validate_base64( $valid_base64_strings[1] ) );
		$this->assertFalse( McpPromptValidator::validate_base64( $valid_base64_strings[2] ) ); // Empty should be invalid
	}

	public function test_validate_base64_with_invalid_data(): void {
		$invalid_base64_strings = array(
			'not-base64!',
			'Invalid@#$%',
		);

		foreach ( $invalid_base64_strings as $data ) {
			$this->assertFalse( McpPromptValidator::validate_base64( $data ), "Data '{$data}' should be invalid base64" );
		}

		// Note: 'SGVsbG8gV29ybGQ' (without padding) is actually valid base64 in PHP
		// PHP's base64_decode is more lenient than strict base64 validation
	}

	public function test_validate_image_mime_type(): void {
		$valid_types = array(
			'image/jpeg',
			'image/jpg',
			'image/png',
			'image/gif',
			'image/webp',
			'image/bmp',
			'image/svg+xml',
		);

		foreach ( $valid_types as $type ) {
			$this->assertTrue( McpPromptValidator::validate_image_mime_type( $type ), "MIME type '{$type}' should be valid" );
		}

		$invalid_types = array(
			'text/plain',
			'application/json',
			'audio/mp3',
			'invalid/type',
		);

		foreach ( $invalid_types as $type ) {
			$this->assertFalse( McpPromptValidator::validate_image_mime_type( $type ), "MIME type '{$type}' should be invalid for images" );
		}
	}

	public function test_validate_audio_mime_type(): void {
		$valid_types = array(
			'audio/wav',
			'audio/mp3',
			'audio/mpeg',
			'audio/ogg',
			'audio/webm',
			'audio/aac',
			'audio/flac',
		);

		foreach ( $valid_types as $type ) {
			$this->assertTrue( McpPromptValidator::validate_audio_mime_type( $type ), "MIME type '{$type}' should be valid" );
		}

		$invalid_types = array(
			'image/jpeg',
			'text/plain',
			'video/mp4',
			'invalid/type',
		);

		foreach ( $invalid_types as $type ) {
			$this->assertFalse( McpPromptValidator::validate_audio_mime_type( $type ), "MIME type '{$type}' should be invalid for audio" );
		}
	}

	public function test_validate_iso8601_timestamp(): void {
		$valid_timestamps = array(
			'2023-12-25T10:30:00Z',
			'2023-12-25T10:30:00+02:00',
			// Note: Microsecond formats may not be supported by all DateTime implementations
		);

		foreach ( $valid_timestamps as $timestamp ) {
			$this->assertTrue( McpPromptValidator::validate_iso8601_timestamp( $timestamp ), "Timestamp '{$timestamp}' should be valid" );
		}

		$invalid_timestamps = array(
			'2023-12-25',
			'10:30:00',
			'not-a-timestamp',
			'2023/12/25 10:30:00',
		);

		foreach ( $invalid_timestamps as $timestamp ) {
			$this->assertFalse( McpPromptValidator::validate_iso8601_timestamp( $timestamp ), "Timestamp '{$timestamp}' should be invalid" );
		}
	}

	public function test_is_valid_prompt_data(): void {
		$valid_data = array(
			'name' => 'valid-prompt',
		);

		$this->assertTrue( McpPromptValidator::is_valid_prompt_data( $valid_data ) );

		$invalid_data = array(
			'name' => '',
		);

		$this->assertFalse( McpPromptValidator::is_valid_prompt_data( $invalid_data ) );
	}

	public function test_validate_prompt_messages_with_valid_messages(): void {
		$valid_messages = array(
			array(
				'role'    => 'user',
				'content' => array(
					'type' => 'text',
					'text' => 'Hello, world!',
				),
			),
			array(
				'role'    => 'assistant',
				'content' => array(
					'type'     => 'image',
					'data'     => 'SGVsbG8gV29ybGQ=',
					'mimeType' => 'image/png',
				),
			),
		);

		$errors = McpPromptValidator::validate_prompt_messages( $valid_messages );
		$this->assertEmpty( $errors );
	}

	public function test_validate_prompt_messages_with_invalid_role(): void {
		$invalid_messages = array(
			array(
				'role'    => 'invalid-role',
				'content' => array(
					'type' => 'text',
					'text' => 'Hello',
				),
			),
		);

		$errors = McpPromptValidator::validate_prompt_messages( $invalid_messages );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'role must be either \'user\' or \'assistant\'', implode( ' ', $errors ) );
	}

	public function test_validate_prompt_messages_with_missing_content(): void {
		$invalid_messages = array(
			array(
				'role' => 'user',
				// Missing content
			),
		);

		$errors = McpPromptValidator::validate_prompt_messages( $invalid_messages );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'must have a content object', implode( ' ', $errors ) );
	}

	public function test_validate_prompt_messages_with_invalid_content_type(): void {
		$invalid_messages = array(
			array(
				'role'    => 'user',
				'content' => array(
					'type' => 'invalid-type',
					'text' => 'Hello',
				),
			),
		);

		$errors = McpPromptValidator::validate_prompt_messages( $invalid_messages );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'content type \'invalid-type\' is not supported', implode( ' ', $errors ) );
	}

	public function test_validate_prompt_instance_with_valid_prompt(): void {
		$server = $this->makeServer();

		$prompt = new McpPrompt(
			'test/valid-prompt',
			'valid-prompt',
			'Valid Prompt',
			'A valid test prompt'
		);
		$prompt->set_mcp_server( $server );

		$result = McpPromptValidator::validate_prompt_instance( $prompt, 'test-context' );
		$this->assertTrue( $result );
	}

	public function test_validate_prompt_uniqueness_method_exists(): void {
		// Test that the uniqueness validation method exists and is callable
		$server = $this->makeServer();

		$prompt_data = array(
			'ability'     => 'test/test-prompt',
			'name'        => 'test-prompt',
			'title'       => 'Test Prompt',
			'description' => 'Test prompt',
		);
		$prompt      = McpPrompt::from_array( $prompt_data, $server );

		// The method should exist and be callable
		$this->assertTrue( method_exists( McpPromptValidator::class, 'validate_prompt_uniqueness' ) );

		// Should return true for unique prompt
		$result = McpPromptValidator::validate_prompt_uniqueness( $prompt, 'test-context' );
		$this->assertTrue( $result );
	}

	public function test_get_validation_errors_returns_array(): void {
		$invalid_data = array(
			'name'        => '',
			'title'       => 123,
			'annotations' => 'not-an-array',
		);

		$errors = McpPromptValidator::get_validation_errors( $invalid_data );

		$this->assertIsArray( $errors );
		$this->assertNotEmpty( $errors );
		$this->assertGreaterThan( 2, count( $errors ) ); // Should have multiple validation errors
	}
}
