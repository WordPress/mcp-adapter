<?php
/**
 * Tests for McpResourceValidator class.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Domain\Resources;

use WP\MCP\Domain\Resources\McpResource;
use WP\MCP\Domain\Resources\McpResourceValidator;
use WP\MCP\Tests\TestCase;

/**
 * Test McpResourceValidator functionality.
 */
final class McpResourceValidatorTest extends TestCase {

	public function test_validate_resource_data_with_valid_text_resource(): void {
		$valid_resource_data = array(
			'uri'         => 'WordPress://local/test-resource',
			'name'        => 'Test Resource',
			'description' => 'A test resource for validation',
			'text'        => 'This is test content',
			'mimeType'    => 'text/plain',
			'annotations' => array( 'category' => 'test' ),
		);

		$result = McpResourceValidator::validate_resource_data( $valid_resource_data, 'test-context' );
		$this->assertTrue( $result );
	}

	public function test_validate_resource_data_with_valid_blob_resource(): void {
		$valid_resource_data = array(
			'uri'         => 'WordPress://local/test-blob',
			'name'        => 'Test Blob',
			'description' => 'A test blob resource',
			'blob'        => 'SGVsbG8gV29ybGQ=', // Base64 encoded "Hello World"
			'mimeType'    => 'application/octet-stream',
		);

		$result = McpResourceValidator::validate_resource_data( $valid_resource_data );
		$this->assertTrue( $result );
	}

	public function test_validate_resource_data_with_missing_uri(): void {
		$invalid_resource_data = array(
			'name'        => 'Test Resource',
			'description' => 'Missing URI',
			'text'        => 'Content',
		);

		$result = McpResourceValidator::validate_resource_data( $invalid_resource_data );

		$this->assertWPError( $result );
		$this->assertEquals( 'resource_validation_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'Resource validation failed', $result->get_error_message() );
		$this->assertStringContainsString( 'Resource URI is required', $result->get_error_message() );
	}

	public function test_validate_resource_data_with_invalid_uri(): void {
		$invalid_resource_data = array(
			'uri'  => 'not-a-valid-uri',
			'text' => 'Content',
		);

		$result = McpResourceValidator::validate_resource_data( $invalid_resource_data );

		$this->assertWPError( $result );
		$this->assertEquals( 'resource_validation_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'Resource URI must be a valid URI format', $result->get_error_message() );
	}

	public function test_validate_resource_data_with_no_content(): void {
		$invalid_resource_data = array(
			'uri'         => 'WordPress://local/no-content',
			'name'        => 'No Content Resource',
			'description' => 'Missing both text and blob',
		);

		$result = McpResourceValidator::validate_resource_data( $invalid_resource_data );

		$this->assertWPError( $result );
		$this->assertEquals( 'resource_validation_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'Resource must have either text or blob content', $result->get_error_message() );
	}

	public function test_validate_resource_data_with_both_text_and_blob(): void {
		$invalid_resource_data = array(
			'uri'  => 'WordPress://local/conflicting-content',
			'text' => 'Text content',
			'blob' => 'SGVsbG8=', // Both text and blob (not allowed)
		);

		$result = McpResourceValidator::validate_resource_data( $invalid_resource_data );

		$this->assertWPError( $result );
		$this->assertEquals( 'resource_validation_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'Resource cannot have both text and blob content', $result->get_error_message() );
	}

	public function test_validate_resource_data_with_invalid_mime_type(): void {
		$invalid_resource_data = array(
			'uri'      => 'WordPress://local/invalid-mime',
			'text'     => 'Content',
			'mimeType' => 'invalid-mime-type',
		);

		$result = McpResourceValidator::validate_resource_data( $invalid_resource_data );

		$this->assertWPError( $result );
		$this->assertEquals( 'resource_validation_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'Resource mimeType must be a valid MIME type format', $result->get_error_message() );
	}

	public function test_validate_resource_uri_with_valid_uris(): void {
		$valid_uris = array(
			'WordPress://local/resource',
			'https://example.com/resource',
			'file:///path/to/resource',
			'custom-protocol://resource-id',
			'ftp://server.com/file.txt',
		);

		foreach ( $valid_uris as $uri ) {
			$this->assertTrue( McpResourceValidator::validate_resource_uri( $uri ), "URI '{$uri}' should be valid" );
		}
	}

	public function test_validate_resource_uri_with_invalid_uris(): void {
		$invalid_uris = array(
			'',                           // Empty
			'not-a-uri',                 // No scheme
			'://missing-scheme',         // Missing scheme
			'123://invalid-scheme',      // Scheme can't start with number
			str_repeat( 'a', 2049 ),     // Too long
		);

		foreach ( $invalid_uris as $uri ) {
			$this->assertFalse( McpResourceValidator::validate_resource_uri( $uri ), "URI '{$uri}' should be invalid" );
		}
	}

	public function test_validate_mime_type_with_valid_types(): void {
		$valid_types = array(
			'text/plain',
			'application/json',
			'image/jpeg',
			'audio/mp3',
			'video/mp4',
			'application/octet-stream',
			'text/html',
		);

		foreach ( $valid_types as $type ) {
			$this->assertTrue( McpResourceValidator::validate_mime_type( $type ), "MIME type '{$type}' should be valid" );
		}
	}

	public function test_validate_mime_type_with_invalid_types(): void {
		$invalid_types = array(
			'',
			'text',                      // Missing subtype
			'text/',                     // Empty subtype
			'/plain',                    // Missing type
			'text/plain/extra',          // Too many parts
			'invalid-mime-type',         // No slash
		);

		foreach ( $invalid_types as $type ) {
			$this->assertFalse( McpResourceValidator::validate_mime_type( $type ), "MIME type '{$type}' should be invalid" );
		}
	}

	public function test_validate_resource_instance_with_valid_resource(): void {
		$server = $this->makeServer();

		$resource_data = array(
			'ability'     => 'test/valid-resource',
			'uri'         => 'WordPress://local/valid-resource',
			'name'        => 'Valid Resource',
			'description' => 'A valid test resource',
			'mimeType'    => 'text/plain',
			'text'        => 'This is test content',
		);

		$resource = McpResource::from_array( $resource_data, $server );

		$result = McpResourceValidator::validate_resource_instance( $resource, 'test-context' );
		$this->assertTrue( $result );
	}

	public function test_validate_resource_uniqueness_method_exists(): void {
		// Test that the uniqueness validation method exists and is callable
		$server = $this->makeServer();

		$resource_data = array(
			'ability'     => 'test/test-resource',
			'uri'         => 'WordPress://local/test-resource',
			'name'        => 'Test Resource',
			'description' => 'Test resource',
			'text'        => 'Test content',
		);
		$resource      = McpResource::from_array( $resource_data, $server );

		// The method should exist and be callable
		$this->assertTrue( method_exists( McpResourceValidator::class, 'validate_resource_uniqueness' ) );

		// Should return true for unique resource
		$result = McpResourceValidator::validate_resource_uniqueness( $resource, 'test-context' );
		$this->assertTrue( $result );
	}

	public function test_get_validation_errors_returns_array(): void {
		$invalid_data = array(
			'uri'         => '',
			'name'        => 123,
			'mimeType'    => 'invalid-type',
			'annotations' => 'not-an-array',
		);

		$errors = McpResourceValidator::get_validation_errors( $invalid_data );

		$this->assertIsArray( $errors );
		$this->assertNotEmpty( $errors );
		$this->assertGreaterThan( 3, count( $errors ) ); // Should have multiple validation errors
	}

	public function test_validate_resource_data_with_context_in_error_message(): void {
		$invalid_resource_data = array(
			'uri' => '',
		);

		$result = McpResourceValidator::validate_resource_data( $invalid_resource_data, 'custom-context' );

		$this->assertWPError( $result );
		$this->assertEquals( 'resource_validation_failed', $result->get_error_code() );
		$this->assertStringContainsString( '[custom-context]', $result->get_error_message() );
		$this->assertStringContainsString( 'Resource validation failed', $result->get_error_message() );
	}

	public function test_validate_resource_data_sanitizes_string_inputs(): void {
		$resource_data_with_whitespace = array(
			'uri'         => '  WordPress://local/test  ',
			'name'        => '  Test Resource  ',
			'description' => '  Test description  ',
			'mimeType'    => '  text/plain  ',
			'text'        => 'Content',
		);

		$result = McpResourceValidator::validate_resource_data( $resource_data_with_whitespace );
		$this->assertTrue( $result );
	}

	public function test_validate_resource_data_with_invalid_text_type(): void {
		$invalid_resource_data = array(
			'uri'  => 'WordPress://local/invalid-text',
			'text' => 123, // Should be string
		);

		$result = McpResourceValidator::validate_resource_data( $invalid_resource_data );

		$this->assertWPError( $result );
		$this->assertEquals( 'resource_validation_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'Resource text content must be a string', $result->get_error_message() );
	}

	public function test_validate_resource_data_with_invalid_blob_type(): void {
		$invalid_resource_data = array(
			'uri'  => 'WordPress://local/invalid-blob',
			'blob' => array(), // Should be string, but also need to have content
			'text' => '', // This will trigger the "must have either text or blob" error first
		);

		$result = McpResourceValidator::validate_resource_data( $invalid_resource_data );

		$this->assertWPError( $result );
		$this->assertEquals( 'resource_validation_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'Resource must have either text or blob content', $result->get_error_message() );
	}
}
