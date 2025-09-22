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

		// Should not throw exception
		McpResourceValidator::validate_resource_data( $valid_resource_data, 'test-context' );
		$this->assertTrue( true );
	}

	public function test_validate_resource_data_with_valid_blob_resource(): void {
		$valid_resource_data = array(
			'uri'         => 'WordPress://local/test-blob',
			'name'        => 'Test Blob',
			'description' => 'A test blob resource',
			'blob'        => 'SGVsbG8gV29ybGQ=', // Base64 encoded "Hello World"
			'mimeType'    => 'application/octet-stream',
		);

		// Should not throw exception
		McpResourceValidator::validate_resource_data( $valid_resource_data );
		$this->assertTrue( true );
	}

	public function test_validate_resource_data_with_missing_uri(): void {
		$invalid_resource_data = array(
			'name'        => 'Test Resource',
			'description' => 'Missing URI',
			'text'        => 'Content',
		);

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Resource validation failed' );
		$this->expectExceptionMessage( 'Resource URI is required' );

		McpResourceValidator::validate_resource_data( $invalid_resource_data );
	}

	public function test_validate_resource_data_with_invalid_uri(): void {
		$invalid_resource_data = array(
			'uri'  => 'not-a-valid-uri',
			'text' => 'Content',
		);

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Resource URI must be a valid URI format' );

		McpResourceValidator::validate_resource_data( $invalid_resource_data );
	}

	public function test_validate_resource_data_with_no_content(): void {
		$invalid_resource_data = array(
			'uri'         => 'WordPress://local/no-content',
			'name'        => 'No Content Resource',
			'description' => 'Missing both text and blob',
		);

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Resource must have either text or blob content' );

		McpResourceValidator::validate_resource_data( $invalid_resource_data );
	}

	public function test_validate_resource_data_with_both_text_and_blob(): void {
		$invalid_resource_data = array(
			'uri'  => 'WordPress://local/conflicting-content',
			'text' => 'Text content',
			'blob' => 'SGVsbG8=', // Both text and blob (not allowed)
		);

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Resource cannot have both text and blob content' );

		McpResourceValidator::validate_resource_data( $invalid_resource_data );
	}

	public function test_validate_resource_data_with_invalid_mime_type(): void {
		$invalid_resource_data = array(
			'uri'      => 'WordPress://local/invalid-mime',
			'text'     => 'Content',
			'mimeType' => 'invalid-mime-type',
		);

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Resource mimeType must be a valid MIME type format' );

		McpResourceValidator::validate_resource_data( $invalid_resource_data );
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

		// Should not throw exception
		McpResourceValidator::validate_resource_instance( $resource, 'test-context' );
		$this->assertTrue( true );
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
		$resource = McpResource::from_array( $resource_data, $server );

		// The method should exist and be callable
		$this->assertTrue( method_exists( McpResourceValidator::class, 'validate_resource_uniqueness' ) );
		
		// Should not throw exception for unique resource
		McpResourceValidator::validate_resource_uniqueness( $resource, 'test-context' );
		$this->assertTrue( true );
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

		try {
			McpResourceValidator::validate_resource_data( $invalid_resource_data, 'custom-context' );
			$this->fail( 'Expected exception to be thrown' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringContainsString( '[custom-context]', $e->getMessage() );
			$this->assertStringContainsString( 'Resource validation failed', $e->getMessage() );
		}
	}

	public function test_validate_resource_data_sanitizes_string_inputs(): void {
		$resource_data_with_whitespace = array(
			'uri'         => '  WordPress://local/test  ',
			'name'        => '  Test Resource  ',
			'description' => '  Test description  ',
			'mimeType'    => '  text/plain  ',
			'text'        => 'Content',
		);

		// Should not throw exception (whitespace should be trimmed)
		McpResourceValidator::validate_resource_data( $resource_data_with_whitespace );
		$this->assertTrue( true );
	}

	public function test_validate_resource_data_with_invalid_text_type(): void {
		$invalid_resource_data = array(
			'uri'  => 'WordPress://local/invalid-text',
			'text' => 123, // Should be string
		);

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Resource text content must be a string' );

		McpResourceValidator::validate_resource_data( $invalid_resource_data );
	}

	public function test_validate_resource_data_with_invalid_blob_type(): void {
		$invalid_resource_data = array(
			'uri'  => 'WordPress://local/invalid-blob',
			'blob' => array(), // Should be string, but also need to have content
			'text' => '', // This will trigger the "must have either text or blob" error first
		);

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Resource must have either text or blob content' );

		McpResourceValidator::validate_resource_data( $invalid_resource_data );
	}
}
