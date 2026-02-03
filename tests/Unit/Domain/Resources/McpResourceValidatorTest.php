<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Domain\Resources;

use WP\MCP\Domain\Resources\McpResourceValidator;
use WP\MCP\Tests\TestCase;
use WP\McpSchema\Server\Resources\DTO\Resource as ResourceDto;

/**
 * Tests for McpResourceValidator class.
 *
 * @covers \WP\MCP\Domain\Resources\McpResourceValidator
 */
final class McpResourceValidatorTest extends TestCase {

	// =========================================================================
	// validate_resource_dto Tests
	// =========================================================================

	public function test_validate_resource_dto_with_valid_resource(): void {
		$resource = ResourceDto::fromArray(
			array(
				'uri'  => 'test://resource',
				'name' => 'test-resource',
			)
		);

		$result = McpResourceValidator::validate_resource_dto( $resource );
		$this->assertTrue( $result );
	}

	public function test_validate_resource_dto_rejects_invalid_uri(): void {
		$resource = ResourceDto::fromArray(
			array(
				'uri'  => 'not a valid uri',
				'name' => 'test-resource',
			)
		);

		$result = McpResourceValidator::validate_resource_dto( $resource );
		$this->assertWPError( $result );
		$this->assertSame( 'mcp_resource_validation_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'URI must be a valid URI', $result->get_error_message() );
	}

	public function test_validate_resource_dto_rejects_invalid_mime_type(): void {
		$resource = ResourceDto::fromArray(
			array(
				'uri'      => 'test://resource',
				'name'     => 'test-resource',
				'mimeType' => 'invalid-mime',
			)
		);

		$result = McpResourceValidator::validate_resource_dto( $resource );
		$this->assertWPError( $result );
		$this->assertStringContainsString( 'MIME type is invalid', $result->get_error_message() );
	}

	public function test_validate_resource_dto_accepts_valid_mime_type(): void {
		$resource = ResourceDto::fromArray(
			array(
				'uri'      => 'test://resource',
				'name'     => 'test-resource',
				'mimeType' => 'application/json',
			)
		);

		$result = McpResourceValidator::validate_resource_dto( $resource );
		$this->assertTrue( $result );
	}

	public function test_validate_resource_dto_rejects_invalid_icons(): void {
		$resource = ResourceDto::fromArray(
			array(
				'uri'   => 'test://resource',
				'name'  => 'test-resource',
				'icons' => array(
					array(
						'src'      => 'https://example.com/icon.png',
						'mimeType' => 'image/png',
					),
					array( 'src' => 'invalid-url' ), // Invalid src
				),
			)
		);

		$result = McpResourceValidator::validate_resource_dto( $resource );
		$this->assertWPError( $result );
		$this->assertStringContainsString( 'Icon at index 1', $result->get_error_message() );
	}

	public function test_validate_resource_dto_rejects_invalid_annotation_values(): void {
		// Note: The DTO validates structure (e.g., audience must be array).
		// Our validator tests for invalid VALUES within valid structure.
		$resource = ResourceDto::fromArray(
			array(
				'uri'         => 'test://resource',
				'name'        => 'test-resource',
				'annotations' => array(
					'audience' => array( 'admin' ), // Invalid role - should be 'user' or 'assistant'
				),
			)
		);

		$result = McpResourceValidator::validate_resource_dto( $resource );
		$this->assertWPError( $result );
		$this->assertStringContainsString( 'valid roles', $result->get_error_message() );
	}

	public function test_validate_resource_dto_rejects_invalid_annotation_priority(): void {
		$resource = ResourceDto::fromArray(
			array(
				'uri'         => 'test://resource',
				'name'        => 'test-resource',
				'annotations' => array(
					'priority' => 1.5, // Out of range - should be 0.0 to 1.0
				),
			)
		);

		$result = McpResourceValidator::validate_resource_dto( $resource );
		$this->assertWPError( $result );
		$this->assertStringContainsString( 'priority must be between', $result->get_error_message() );
	}

	public function test_validate_resource_dto_accepts_valid_annotations(): void {
		$resource = ResourceDto::fromArray(
			array(
				'uri'         => 'test://resource',
				'name'        => 'test-resource',
				'annotations' => array(
					'audience' => array( 'user', 'assistant' ),
					'priority' => 0.8,
				),
			)
		);

		$result = McpResourceValidator::validate_resource_dto( $resource );
		$this->assertTrue( $result );
	}

	// =========================================================================
	// validate_resource_data Tests
	// =========================================================================

	public function test_validate_resource_data_with_valid_text_content(): void {
		$resource_data = array(
			'uri'  => 'test://resource',
			'text' => 'Test content',
		);

		$result = McpResourceValidator::validate_resource_data( $resource_data );
		$this->assertTrue( $result );
	}

	public function test_validate_resource_data_with_valid_blob_content(): void {
		$resource_data = array(
			'uri'  => 'test://resource',
			'blob' => base64_encode( 'Test content' ),
		);

		$result = McpResourceValidator::validate_resource_data( $resource_data );
		$this->assertTrue( $result );
	}

	public function test_validate_resource_data_with_context_in_error_message(): void {
		$resource_data = array(
			'uri' => 'invalid uri',
		);

		$result = McpResourceValidator::validate_resource_data( $resource_data, 'TestContext' );
		$this->assertWPError( $result );
		$this->assertStringContainsString( '[TestContext]', $result->get_error_message() );
	}

	// =========================================================================
	// get_validation_errors Tests
	// =========================================================================

	public function test_get_validation_errors_with_valid_resource_data(): void {
		$resource_data = array(
			'uri'         => 'test://resource',
			'text'        => 'Content',
		);

		$errors = McpResourceValidator::get_validation_errors( $resource_data );
		$this->assertEmpty( $errors );
	}

	public function test_get_validation_errors_with_missing_uri(): void {
		$resource_data = array(
			'text' => 'Content',
		);

		$errors = McpResourceValidator::get_validation_errors( $resource_data );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'URI is required', $errors[0] );
	}

	public function test_get_validation_errors_with_empty_uri(): void {
		$resource_data = array(
			'uri'  => '',
			'text' => 'Content',
		);

		$errors = McpResourceValidator::get_validation_errors( $resource_data );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'URI is required', $errors[0] );
	}

	public function test_get_validation_errors_with_non_string_uri(): void {
		$resource_data = array(
			'uri'  => 123,
			'text' => 'Content',
		);

		$errors = McpResourceValidator::get_validation_errors( $resource_data );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'URI is required', $errors[0] );
	}

	public function test_get_validation_errors_with_invalid_uri_format(): void {
		$resource_data = array(
			'uri'  => 'not a valid uri',
			'text' => 'Content',
		);

		$errors = McpResourceValidator::get_validation_errors( $resource_data );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'URI must be a valid URI format', $errors[0] );
	}

	public function test_get_validation_errors_with_missing_content(): void {
		$resource_data = array(
			'uri' => 'test://resource',
		);

		$errors = McpResourceValidator::get_validation_errors( $resource_data );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'must include at least one of', $errors[0] );
	}

	public function test_get_validation_errors_with_both_text_and_blob(): void {
		$resource_data = array(
			'uri'  => 'test://resource',
			'text' => 'Text content',
			'blob' => base64_encode( 'Blob content' ),
		);

		$errors = McpResourceValidator::get_validation_errors( $resource_data );
		$this->assertEmpty( $errors );
	}

	public function test_get_validation_errors_with_non_string_text(): void {
		$resource_data = array(
			'uri'  => 'test://resource',
			'text' => 123,
		);

		$errors = McpResourceValidator::get_validation_errors( $resource_data );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'text content must be a string', implode( ' ', $errors ) );
	}

	public function test_get_validation_errors_with_non_string_blob(): void {
		$resource_data = array(
			'uri'  => 'test://resource',
			'blob' => array( 'data' ),
		);

		$errors = McpResourceValidator::get_validation_errors( $resource_data );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'blob content must be a string', implode( ' ', $errors ) );
	}

	public function test_get_validation_errors_rejects_invalid_base64_blob(): void {
		$resource_data = array(
			'uri'  => 'test://resource',
			'blob' => 'not-valid-base64!!!',
		);

		$errors = McpResourceValidator::get_validation_errors( $resource_data );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'valid base64', $errors[0] );
	}

	public function test_get_validation_errors_with_non_string_mime_type(): void {
		$resource_data = array(
			'uri'      => 'test://resource',
			'text'     => 'Content',
			'mimeType' => 123,
		);

		$errors = McpResourceValidator::get_validation_errors( $resource_data );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'mimeType must be a string', $errors[0] );
	}

	public function test_get_validation_errors_with_invalid_mime_type_format(): void {
		$resource_data = array(
			'uri'      => 'test://resource',
			'text'     => 'Content',
			'mimeType' => 'invalid-mime',
		);

		$errors = McpResourceValidator::get_validation_errors( $resource_data );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'mimeType must be a valid MIME type format', $errors[0] );
	}

	public function test_get_validation_errors_with_valid_mime_type(): void {
		$resource_data = array(
			'uri'      => 'test://resource',
			'text'     => 'Content',
			'mimeType' => 'application/json',
		);

		$errors = McpResourceValidator::get_validation_errors( $resource_data );
		$this->assertEmpty( $errors );
	}

	// =========================================================================
	// Edge Cases and Multiple Errors
	// =========================================================================

	public function test_get_validation_errors_reports_multiple_errors(): void {
		$resource_data = array(
			'uri'         => 'invalid uri',
			'mimeType'    => 'invalid-mime',
			// Missing text/blob content
		);

		$errors = McpResourceValidator::get_validation_errors( $resource_data );
		$this->assertCount( 3, $errors, 'Should report all validation errors: invalid URI, invalid mimeType, and missing content' );
	}

	public function test_get_validation_errors_allows_empty_string_text(): void {
		$resource_data = array(
			'uri'  => 'test://resource',
			'text' => '', // Empty but string type - valid per array_key_exists check
		);

		// Empty string IS valid content after the fix - array_key_exists allows it.
		$errors = McpResourceValidator::get_validation_errors( $resource_data );
		$this->assertEmpty( $errors, 'Empty string text content should be valid' );
	}

	public function test_get_validation_errors_allows_optional_fields_when_omitted(): void {
		$resource_data = array(
			'uri'  => 'test://resource',
			'text' => 'Content',
			// mimeType omitted - should be valid (optional)
		);

		$errors = McpResourceValidator::get_validation_errors( $resource_data );
		$this->assertEmpty( $errors );
	}
}
