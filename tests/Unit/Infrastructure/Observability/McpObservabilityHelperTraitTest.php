<?php
/**
 * Tests for McpObservabilityHelperTrait.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Infrastructure\Observability;

use WP\MCP\Infrastructure\Observability\McpObservabilityHelperTrait;
use WP\MCP\Tests\TestCase;

/**
 * Test McpObservabilityHelperTrait functionality.
 */
final class McpObservabilityHelperTraitTest extends TestCase {

	/**
	 * Test class that uses the trait for testing purposes.
	 */
	private $trait_user;

	public function set_up(): void {
		parent::set_up();

		// Create an anonymous class that uses the trait
		$this->trait_user = new class() {
			use McpObservabilityHelperTrait;

			// Make static methods accessible for testing
			public static function test_get_default_tags(): array {
				return self::get_default_tags();
			}

			public static function test_sanitize_tags( array $tags ): array {
				return self::sanitize_tags( $tags );
			}

			public static function test_format_metric_name( string $metric ): string {
				return self::format_metric_name( $metric );
			}

			public static function test_merge_tags( array $tags ): array {
				return self::merge_tags( $tags );
			}

			public static function test_categorize_error( \Throwable $exception ): string {
				return self::categorize_error( $exception );
			}
		};
	}

	public function test_get_default_tags(): void {
		$tags = $this->trait_user::test_get_default_tags();

		$this->assertIsArray( $tags );
		$this->assertArrayHasKey( 'site_id', $tags );
		$this->assertArrayHasKey( 'user_id', $tags );
		$this->assertArrayHasKey( 'timestamp', $tags );

		$this->assertIsInt( $tags['site_id'] );
		$this->assertIsInt( $tags['user_id'] );
		$this->assertIsInt( $tags['timestamp'] );
		$this->assertGreaterThan( 0, $tags['timestamp'] );
	}

	public function test_sanitize_tags_removes_sensitive_data(): void {
		$tags_with_sensitive_data = array(
			'username'      => 'testuser',
			'user_password' => 'my password is secret',  // Contains 'password' as whole word
			'bearer_token'  => 'token value here',        // Contains 'token' as whole word
			'api_key'       => 'key is sensitive',        // Contains 'key' as whole word
			'user_secret'   => 'secret data',             // Contains 'secret' as whole word
			'normal_value'  => 'normal_data',             // Should not be redacted
		);

		$sanitized = $this->trait_user::test_sanitize_tags( $tags_with_sensitive_data );

		$this->assertIsArray( $sanitized );
		$this->assertEquals( 'testuser', $sanitized['username'] );
		$this->assertStringContainsString( '[REDACTED]', $sanitized['user_password'] );
		$this->assertStringContainsString( '[REDACTED]', $sanitized['bearer_token'] );
		$this->assertStringContainsString( '[REDACTED]', $sanitized['api_key'] );
		$this->assertStringContainsString( '[REDACTED]', $sanitized['user_secret'] );
		$this->assertEquals( 'normal_data', $sanitized['normal_value'] );
	}

	public function test_sanitize_tags_limits_length(): void {
		$tags_with_long_values = array(
			'long_key_' . str_repeat( 'x', 100 ) => 'value',
			'normal_key'                         => str_repeat( 'y', 200 ),
		);

		$sanitized = $this->trait_user::test_sanitize_tags( $tags_with_long_values );

		$this->assertIsArray( $sanitized );

		// Check key length limit (64 chars)
		$keys = array_keys( $sanitized );
		foreach ( $keys as $key ) {
			$this->assertLessThanOrEqual( 64, strlen( $key ) );
		}

		// Values are not truncated, they maintain their full length
		$this->assertEquals( 'value', $sanitized[ 'long_key_' . str_repeat( 'x', 55 ) ] ); // First 64 chars of key
		$this->assertEquals( 200, strlen( $sanitized['normal_key'] ) ); // Value is not truncated
	}

	public function test_format_metric_name_adds_mcp_prefix(): void {
		$metrics = array(
			'event.name'           => 'mcp.event.name',
			'request.count'        => 'mcp.request.count',
			'mcp.already.prefixed' => 'mcp.already.prefixed',
		);

		foreach ( $metrics as $input => $expected ) {
			$result = $this->trait_user::test_format_metric_name( $input );
			$this->assertEquals( $expected, $result );
		}
	}

	public function test_format_metric_name_normalizes_format(): void {
		$test_cases = array(
			'Event Name With Spaces' => 'mcp.event.name.with.spaces',
			'UPPERCASE_METRIC'       => 'mcp.uppercase_metric', // Underscores preserved
			'mixed@#$%characters'    => 'mcp.mixed.characters',
			'multiple...dots'        => 'mcp.multiple.dots',
			'.leading.trailing.'     => 'mcp.leading.trailing',
		);

		foreach ( $test_cases as $input => $expected ) {
			$result = $this->trait_user::test_format_metric_name( $input );
			$this->assertEquals( $expected, $result, "Input '{$input}' should format to '{$expected}'" );
		}
	}

	public function test_merge_tags_combines_default_and_custom(): void {
		$custom_tags = array(
			'custom_key' => 'custom_value',
			'method'     => 'tools/call',
		);

		$merged = $this->trait_user::test_merge_tags( $custom_tags );

		$this->assertIsArray( $merged );

		// Should have default tags
		$this->assertArrayHasKey( 'site_id', $merged );
		$this->assertArrayHasKey( 'user_id', $merged );
		$this->assertArrayHasKey( 'timestamp', $merged );

		// Should have custom tags
		$this->assertArrayHasKey( 'custom_key', $merged );
		$this->assertArrayHasKey( 'method', $merged );
		$this->assertEquals( 'custom_value', $merged['custom_key'] );
		$this->assertEquals( 'tools/call', $merged['method'] );
	}

	public function test_categorize_error_with_known_exceptions(): void {
		$test_cases = array(
			array( new \ArgumentCountError(), 'arguments' ),
			array( new \Error( 'test' ), 'system' ),
			array( new \InvalidArgumentException(), 'validation' ),
			array( new \LogicException(), 'logic' ),
			array( new \RuntimeException(), 'execution' ),
			array( new \TypeError(), 'type' ),
		);

		foreach ( $test_cases as $test_case ) {
			$exception         = $test_case[0];
			$expected_category = $test_case[1];
			$result            = $this->trait_user::test_categorize_error( $exception );
			$this->assertEquals( $expected_category, $result );
		}
	}

	public function test_categorize_error_with_unknown_exception(): void {
		$unknown_exception = new \Exception( 'Unknown exception type' );

		$result = $this->trait_user::test_categorize_error( $unknown_exception );

		$this->assertEquals( 'unknown', $result );
	}

	public function test_sanitize_tags_converts_types_to_strings(): void {
		$mixed_type_tags = array(
			'string_value' => 'text',
			'int_value'    => 123,
			'float_value'  => 45.67,
			'bool_value'   => true,
			'null_value'   => null,
		);

		$sanitized = $this->trait_user::test_sanitize_tags( $mixed_type_tags );

		$this->assertIsArray( $sanitized );

		// All values should be converted to strings
		foreach ( $sanitized as $key => $value ) {
			$this->assertIsString( $key );
			$this->assertIsString( $value );
		}

		$this->assertEquals( 'text', $sanitized['string_value'] );
		$this->assertEquals( '123', $sanitized['int_value'] );
		$this->assertEquals( '45.67', $sanitized['float_value'] );
		$this->assertEquals( '1', $sanitized['bool_value'] );
		$this->assertEquals( '', $sanitized['null_value'] );
	}
}
