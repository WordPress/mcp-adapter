<?php

/**
 * Tests for McpValidator class.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Domain\Utils;

use WP\MCP\Domain\Utils\McpValidator;
use WP\MCP\Tests\TestCase;

/**
 * Test McpValidator functionality.
 */
final class McpValidatorTest extends TestCase {

	// ISO 8601 Timestamp Validation Tests

	public function test_validate_iso8601_timestamp_with_atom_format(): void {
		$valid_timestamp = '2024-01-15T10:30:00+00:00';
		$this->assertTrue( McpValidator::validate_iso8601_timestamp( $valid_timestamp ) );
	}

	public function test_validate_iso8601_timestamp_with_utc_z_format(): void {
		$valid_timestamp = '2024-01-15T10:30:00Z';
		$this->assertTrue( McpValidator::validate_iso8601_timestamp( $valid_timestamp ) );
	}

	public function test_validate_iso8601_timestamp_with_timezone_offset(): void {
		$valid_timestamp = '2024-01-15T10:30:00+05:00';
		$this->assertTrue( McpValidator::validate_iso8601_timestamp( $valid_timestamp ) );
	}

	public function test_validate_iso8601_timestamp_with_fractional_seconds(): void {
		// Any number of fractional digits is accepted, as by the official SDK client.
		$this->assertTrue( McpValidator::validate_iso8601_timestamp( '2024-01-15T10:30:00.1Z' ) );
		$this->assertTrue( McpValidator::validate_iso8601_timestamp( '2024-01-15T10:30:00.123Z' ) );
		$this->assertTrue( McpValidator::validate_iso8601_timestamp( '2024-01-15T10:30:00.123456Z' ) );
		$this->assertTrue( McpValidator::validate_iso8601_timestamp( '2024-01-15T10:30:00.123+00:00' ) );
	}

	public function test_validate_iso8601_timestamp_without_seconds(): void {
		$this->assertTrue( McpValidator::validate_iso8601_timestamp( '2024-01-15T10:30Z' ) );
	}

	public function test_validate_iso8601_timestamp_ignores_default_time_zone(): void {
		// Pacific/Apia skipped 2011-12-30 entirely; the calendar check must not depend on the site zone.
		$previous = date_default_timezone_get();
		date_default_timezone_set( 'Pacific/Apia' );
		try {
			$this->assertTrue( McpValidator::validate_iso8601_timestamp( '2011-12-30T12:00:00Z' ) );
		} finally {
			date_default_timezone_set( $previous );
		}
	}

	public function test_validate_iso8601_timestamp_rejects_invalid_format(): void {
		$invalid_timestamps = array(
			'2024-01-15',
			'10:30:00',
			'2024/01/15 10:30:00',
			'invalid-date',
			'',
			'2024-13-45T99:99:99Z',
			'2024-02-30T10:30:00Z',
			'2024-01-15T10:30:00',
			'2024-01-15T10:30:00+0200',
			'2024-01-15T10:30:00z',
			'2024-01-15T24:00:00Z',
			"2024-01-15T10:30:00Z\n",
		);

		foreach ( $invalid_timestamps as $timestamp ) {
			$this->assertFalse( McpValidator::validate_iso8601_timestamp( $timestamp ), "Timestamp '{$timestamp}' should be invalid" );
		}
	}

	// Name Validation Tests

	public function test_validate_name_with_valid_names(): void {
		$valid_names = array(
			'simple-name',
			'name_with_underscores',
			'name123',
			'a',
			'very-long-name-that-is-still-under-255-characters',
			'Name-With-Mixed-Case',
		);

		foreach ( $valid_names as $name ) {
			$this->assertTrue( McpValidator::validate_name( $name ), "Name '{$name}' should be valid" );
		}
	}

	public function test_validate_name_rejects_empty_string(): void {
		$this->assertFalse( McpValidator::validate_name( '' ) );
	}

	public function test_validate_name_rejects_too_long(): void {
		// Default max length is 128 per MCP spec.
		$long_name = str_repeat( 'a', 129 );
		$this->assertFalse( McpValidator::validate_name( $long_name ) );
	}

	public function test_validate_name_accepts_max_length(): void {
		// Default max length is 128 per MCP spec.
		$max_length_name = str_repeat( 'a', 128 );
		$this->assertTrue( McpValidator::validate_name( $max_length_name ) );
	}

	public function test_validate_name_rejects_invalid_characters(): void {
		$invalid_names = array(
			'name with spaces',
			'name@invalid',
			'name#invalid',
			'name$invalid',
			'name%invalid',
			'name/invalid',
		);

		foreach ( $invalid_names as $name ) {
			$this->assertFalse( McpValidator::validate_name( $name ), "Name '{$name}' should be invalid" );
		}
	}

	public function test_validate_name_accepts_dot(): void {
		// Dots are allowed per MCP 2025-11-25 spec: [A-Za-z0-9_.-]
		$this->assertTrue( McpValidator::validate_name( 'name.with.dots' ) );
		$this->assertTrue( McpValidator::validate_name( 'foo.bar' ) );
		$this->assertTrue( McpValidator::validate_name( 'api.v2.endpoint' ) );
	}

	public function test_validate_name_accepts_numeric_zero(): void {
		// Numeric "0" should be valid (matches regex but not empty()).
		$this->assertTrue( McpValidator::validate_name( '0' ) );
		$this->assertTrue( McpValidator::validate_name( '123' ) );
		$this->assertTrue( McpValidator::validate_name( '000' ) );
	}

	public function test_validate_name_with_custom_max_length(): void {
		$name_64_chars = str_repeat( 'a', 64 );
		$name_65_chars = str_repeat( 'a', 65 );

		$this->assertTrue( McpValidator::validate_name( $name_64_chars, 64 ) );
		$this->assertFalse( McpValidator::validate_name( $name_65_chars, 64 ) );
	}

	// Tool/Prompt Name Validation Tests (using validate_name with default 128-char limit)

	public function test_validate_name_default_128_with_valid_names(): void {
		$valid_names = array(
			'tool-name',
			'prompt_name',
			'tool123',
		);

		foreach ( $valid_names as $name ) {
			$this->assertTrue( McpValidator::validate_name( $name ), "Name '{$name}' should be valid" );
		}
	}

	public function test_validate_name_default_128_rejects_invalid(): void {
		$invalid_names = array(
			'',
			'tool with spaces',
			'tool@invalid',
			'tool/invalid',
		);

		foreach ( $invalid_names as $name ) {
			$this->assertFalse( McpValidator::validate_name( $name ), "Name '{$name}' should be invalid" );
		}
	}

	public function test_validate_name_default_max_length_128(): void {
		// MCP 2025-11-25 spec: tool/prompt names max 128 characters (default).
		$name_128_chars = str_repeat( 'a', 128 );
		$name_129_chars = str_repeat( 'a', 129 );

		$this->assertTrue( McpValidator::validate_name( $name_128_chars ), '128 chars should be valid' );
		$this->assertFalse( McpValidator::validate_name( $name_129_chars ), '129 chars should be invalid' );
	}

	public function test_validate_name_allows_dot(): void {
		// MCP 2025-11-25 spec allows dots in tool/prompt names.
		$this->assertTrue( McpValidator::validate_name( 'foo.bar' ) );
		$this->assertTrue( McpValidator::validate_name( 'namespace.tool.action' ) );
	}

	public function test_validate_name_rejects_slash(): void {
		// Forward slash is NOT allowed in MCP tool/prompt names.
		$this->assertFalse( McpValidator::validate_name( 'foo/bar' ) );
		$this->assertFalse( McpValidator::validate_name( 'namespace/tool' ) );
	}

	// Resource URI Validation Tests

	public function test_validate_resource_uri_with_valid_uris(): void {
		$valid_uris = array(
			'file:///path/to/file.txt',
			'http://example.com/resource',
			'https://example.com/resource',
			'ftp://ftp.example.com/file',
			'custom://my-resource',
			'app://resource/123',
			'wordpress://post/42',
			// RFC 3986 allows an empty path after the scheme.
			'wordpress:',
			// Nothing after the scheme is checked, and there is no length cap.
			'http://example.com/' . str_repeat( 'a', 4096 ),
		);

		foreach ( $valid_uris as $uri ) {
			$this->assertTrue( McpValidator::validate_resource_uri( $uri ), "URI '{$uri}' should be valid" );
		}
	}

	public function test_validate_resource_uri_rejects_empty(): void {
		$this->assertFalse( McpValidator::validate_resource_uri( '' ) );
	}

	public function test_validate_resource_uri_rejects_no_scheme(): void {
		$invalid_uris = array(
			'/path/to/file',
			'example.com',
			'resource',
			// The URI is matched as given, so leading whitespace hides the scheme.
			' wordpress://post/42',
		);

		foreach ( $invalid_uris as $uri ) {
			$this->assertFalse( McpValidator::validate_resource_uri( $uri ), "URI '{$uri}' should be invalid (no scheme)" );
		}
	}

	// Annotation Validation Tests

	public function test_get_annotation_validation_errors_accepts_timestamp_with_time_zone(): void {
		$this->assertSame( array(), McpValidator::get_annotation_validation_errors( array( 'lastModified' => '2024-01-15T10:30:00Z' ) ) );
		$this->assertSame( array(), McpValidator::get_annotation_validation_errors( array( 'lastModified' => '2024-01-15T10:30:00+02:00' ) ) );
		$this->assertSame( array(), McpValidator::get_annotation_validation_errors( array( 'lastModified' => '2024-01-15T10:30:00.123Z' ) ) );
	}

	public function test_get_annotation_validation_errors_ignores_every_other_field(): void {
		// Only lastModified is checked here; the schema package decides the rest.
		$annotations = array(
			'audience'    => 'not-an-array',
			'priority'    => 1.5,
			'customField' => 'value',
		);

		$this->assertSame( array(), McpValidator::get_annotation_validation_errors( $annotations ) );
	}

	/**
	 * @dataProvider data_invalid_last_modified_values
	 *
	 * @param mixed $value The lastModified value.
	 */
	public function test_get_annotation_validation_errors_rejects_last_modified( $value ): void {
		$errors = McpValidator::get_annotation_validation_errors( array( 'lastModified' => $value ) );

		$this->assertCount( 1, $errors );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public function data_invalid_last_modified_values(): array {
		return array(
			'not a string'     => array( 12345 ),
			'empty string'     => array( '' ),
			'date only'        => array( '2024-01-15' ),
			'naive date time'  => array( '2024-01-15T10:30:00' ),
			'garbage'          => array( 'garbage' ),
			'padded timestamp' => array( ' 2024-01-15T10:30:00Z ' ),
		);
	}
}
