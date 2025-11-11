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
			'annotations' => array( 'priority' => 0.5 ),
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

	public function test_is_valid_prompt_data(): void {
		$valid_data = array(
			'name' => 'valid-prompt',
		);

		$this->assertTrue( empty( McpPromptValidator::get_validation_errors( $valid_data ) ) );

		$invalid_data = array(
			'name' => '',
		);

		$this->assertFalse( empty( McpPromptValidator::get_validation_errors( $invalid_data ) ) );
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

	public function test_validate_prompt_messages_with_invalid_embedded_resource(): void {
		$invalid_messages = array(
			array(
				'role'    => 'assistant',
				'content' => array(
					'type'     => 'resource',
					'resource' => array(
						// Missing URI should trigger McpResourceValidator errors.
						'text' => 'Some content',
					),
				),
			),
		);

		$errors = McpPromptValidator::validate_prompt_messages( $invalid_messages );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'Resource URI is required', implode( ' ', $errors ) );
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

	public function test_validate_prompt_requires_server(): void {
		$prompt = new McpPrompt(
			'test/missing-server',
			'prompt-without-server'
		);

		$result = $prompt->validate();
		$this->assertWPError( $result );
		$this->assertSame( 'prompt_missing_mcp_server', $result->get_error_code() );
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

	public function test_validate_prompt_data_with_valid_mcp_annotations(): void {
		$valid_prompt_data = array(
			'name'        => 'test-prompt',
			'annotations' => array(
				'audience'     => array( 'user', 'assistant' ),
				'lastModified' => '2024-01-15T10:30:00Z',
				'priority'     => 0.8,
			),
		);

		$result = McpPromptValidator::validate_prompt_data( $valid_prompt_data );
		$this->assertTrue( $result );
	}

	public function test_validate_prompt_data_with_unknown_annotation_field_name(): void {
		// Unknown fields should be ignored (filtered out by mapper before validation)
		$valid_prompt_data = array(
			'name'        => 'test-prompt',
			'annotations' => array(
				'invalidField' => 'value', // Unknown field, should be ignored
			),
		);

		$result = McpPromptValidator::validate_prompt_data( $valid_prompt_data );

		$this->assertTrue( $result, 'Unknown annotation fields should be ignored' );
	}

	public function test_validate_prompt_data_with_invalid_audience_type(): void {
		$invalid_prompt_data = array(
			'name'        => 'test-prompt',
			'annotations' => array(
				'audience' => 'not-an-array', // Should be array
			),
		);

		$result = McpPromptValidator::validate_prompt_data( $invalid_prompt_data );

		$this->assertWPError( $result );
		$this->assertEquals( 'prompt_validation_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'Annotation field audience must be an array', $result->get_error_message() );
	}

	public function test_validate_prompt_data_with_invalid_audience_role(): void {
		$invalid_prompt_data = array(
			'name'        => 'test-prompt',
			'annotations' => array(
				'audience' => array( 'invalid-role' ), // Invalid role
			),
		);

		$result = McpPromptValidator::validate_prompt_data( $invalid_prompt_data );

		$this->assertWPError( $result );
		$this->assertEquals( 'prompt_validation_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'audience must contain only valid roles', $result->get_error_message() );
	}

	public function test_validate_prompt_data_with_invalid_lastModified_format(): void {
		$invalid_prompt_data = array(
			'name'        => 'test-prompt',
			'annotations' => array(
				'lastModified' => 'not-a-date', // Invalid ISO 8601 format
			),
		);

		$result = McpPromptValidator::validate_prompt_data( $invalid_prompt_data );

		$this->assertWPError( $result );
		$this->assertEquals( 'prompt_validation_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'lastModified must be a valid ISO 8601 timestamp', $result->get_error_message() );
	}

	public function test_validate_prompt_data_with_invalid_priority_range(): void {
		$invalid_prompt_data = array(
			'name'        => 'test-prompt',
			'annotations' => array(
				'priority' => 2.0, // Out of range (should be 0-1)
			),
		);

		$result = McpPromptValidator::validate_prompt_data( $invalid_prompt_data );

		$this->assertWPError( $result );
		$this->assertEquals( 'prompt_validation_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'priority must be between 0.0 and 1.0', $result->get_error_message() );
	}

	public function test_validate_prompt_data_with_partial_annotations(): void {
		// Should be valid - not all annotation fields are required
		$valid_prompt_data = array(
			'name'        => 'test-prompt',
			'annotations' => array(
				'priority' => 0.5,
			),
		);

		$result = McpPromptValidator::validate_prompt_data( $valid_prompt_data );
		$this->assertTrue( $result );
	}
}
