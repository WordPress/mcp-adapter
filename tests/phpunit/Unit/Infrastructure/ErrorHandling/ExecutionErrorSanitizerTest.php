<?php
/**
 * Tests for ExecutionErrorSanitizer class.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Infrastructure\ErrorHandling;

use WP\MCP\Infrastructure\ErrorHandling\ExecutionErrorSanitizer;
use WP\MCP\Tests\TestCase;

/**
 * Test ExecutionErrorSanitizer functionality.
 */
final class ExecutionErrorSanitizerTest extends TestCase {

	public function test_sanitize_code_with_valid_string(): void {
		$this->assertEquals( 'valid_code', ExecutionErrorSanitizer::sanitize_code( 'valid_code' ) );
		$this->assertEquals( 'valid_code', ExecutionErrorSanitizer::sanitize_code( '  valid_code  ' ) );
	}

	public function test_sanitize_code_with_invalid_string(): void {
		$this->assertEquals( 'mcp_execution_failed', ExecutionErrorSanitizer::sanitize_code( '' ) );
		$this->assertEquals( 'mcp_execution_failed', ExecutionErrorSanitizer::sanitize_code( '   ' ) );
		$this->assertEquals( 'mcp_execution_failed', ExecutionErrorSanitizer::sanitize_code( null ) );
		$this->assertEquals( 'mcp_execution_failed', ExecutionErrorSanitizer::sanitize_code( 123 ) );
	}

	public function test_sanitize_code_with_unsafe_string(): void {
		$this->assertEquals( 'safe_code', ExecutionErrorSanitizer::sanitize_code( 'safe_code!@#' ) );
	}

	public function test_sanitize_data_with_primitive_types(): void {
		$this->assertNull( ExecutionErrorSanitizer::sanitize_data( null ) );
		$this->assertTrue( ExecutionErrorSanitizer::sanitize_data( true ) );
		$this->assertFalse( ExecutionErrorSanitizer::sanitize_data( false ) );
		$this->assertEquals( 123, ExecutionErrorSanitizer::sanitize_data( 123 ) );
		$this->assertEquals( 123.45, ExecutionErrorSanitizer::sanitize_data( 123.45 ) );
		$this->assertEquals( 'string', ExecutionErrorSanitizer::sanitize_data( 'string' ) );
	}

	public function test_sanitize_data_truncates_long_strings(): void {
		$long_string = str_repeat( 'a', 6000 );
		$sanitized   = ExecutionErrorSanitizer::sanitize_data( $long_string );
		$this->assertEquals( 5000, strlen( $sanitized ) );
	}

	public function test_sanitize_data_with_arrays(): void {
		$array = array(
			'key1' => 'value1',
			'key2' => 123,
			'key3' => array( 'nested' => 'value' ),
		);
		$this->assertEquals( $array, ExecutionErrorSanitizer::sanitize_data( $array ) );
	}

	public function test_sanitize_data_truncates_large_arrays(): void {
		$large_array = array();
		for ( $i = 0; $i < 60; $i++ ) {
			$large_array[ "key_$i" ] = "value_$i";
		}
		$sanitized = ExecutionErrorSanitizer::sanitize_data( $large_array );
		$this->assertCount( 50, $sanitized );
	}

	public function test_sanitize_data_respects_max_depth(): void {
		$deep_array = array( 'level1' => array( 'level2' => array( 'level3' => array( 'level4' => array( 'level5' => 'too_deep' ) ) ) ) );
		$sanitized  = ExecutionErrorSanitizer::sanitize_data( $deep_array );
		$this->assertNull( $sanitized['level1']['level2']['level3']['level4']['level5'] );
	}

	public function test_sanitize_data_with_objects(): void {
		$obj = new \stdClass();
		$obj->property = 'value';
		$this->assertNull( ExecutionErrorSanitizer::sanitize_data( $obj ) );
	}

	public function test_sanitize_data_with_throwables(): void {
		$exception = new \RuntimeException( 'test' );
		$sanitized = ExecutionErrorSanitizer::sanitize_data( $exception );
		$this->assertEquals( array( 'error_type' => 'RuntimeException' ), $sanitized );
	}

	public function test_sanitize_data_with_resources(): void {
		$resource = fopen( 'php://temp', 'r' );
		$this->assertNull( ExecutionErrorSanitizer::sanitize_data( $resource ) );
		fclose( $resource );
	}
}
