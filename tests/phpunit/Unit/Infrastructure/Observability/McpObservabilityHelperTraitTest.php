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

			public static function test_is_sensitive_key( string $key ): bool {
				return self::is_sensitive_key( $key );
			}

			public static function test_redact_sensitive_values( string $value ): string {
				return self::redact_sensitive_values( $value );
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

	public function test_sanitize_tags_redacts_sensitive_keys(): void {
		// Keys that look sensitive should have their values fully redacted
		$tags_with_sensitive_keys = array(
			'username'    => 'testuser',           // Safe key
			'api_key'     => 'abc123',             // Sensitive key - value should be [REDACTED]
			'apiKey'      => 'xyz789',             // camelCase sensitive key
			'API_KEY'     => 'DEF456',             // SCREAMING_CASE sensitive key
			'authToken'   => 'my-token',           // Sensitive key
			'normal_data' => 'safe_value',         // Safe key
		);

		$sanitized = $this->trait_user::test_sanitize_tags( $tags_with_sensitive_keys );

		$this->assertIsArray( $sanitized );
		$this->assertEquals( 'testuser', $sanitized['username'] );
		$this->assertEquals( '[REDACTED]', $sanitized['api_key'] );
		$this->assertEquals( '[REDACTED]', $sanitized['apiKey'] );
		$this->assertEquals( '[REDACTED]', $sanitized['API_KEY'] );
		$this->assertEquals( '[REDACTED]', $sanitized['authToken'] );
		$this->assertEquals( 'safe_value', $sanitized['normal_data'] );
	}

	public function test_sanitize_tags_redacts_sensitive_values(): void {
		// Values containing sensitive patterns should have those patterns redacted
		$tags_with_sensitive_values = array(
			'log_message' => 'User password was reset',           // Contains 'password'
			'debug_info'  => 'Using bearer token for auth',       // Contains 'bearer' and 'token'
			'safe_text'   => 'This is normal text',               // No sensitive patterns
		);

		$sanitized = $this->trait_user::test_sanitize_tags( $tags_with_sensitive_values );

		$this->assertIsArray( $sanitized );
		$this->assertStringContainsString( '[REDACTED]', $sanitized['log_message'] );
		$this->assertStringNotContainsString( 'password', $sanitized['log_message'] );
		$this->assertStringContainsString( '[REDACTED]', $sanitized['debug_info'] );
		$this->assertEquals( 'This is normal text', $sanitized['safe_text'] );
	}

	public function test_sanitize_tags_limits_key_length(): void {
		$tags_with_long_key = array(
			'long_key_' . str_repeat( 'x', 100 ) => 'value',
		);

		$sanitized = $this->trait_user::test_sanitize_tags( $tags_with_long_key );

		$this->assertIsArray( $sanitized );

		// Check key length limit (64 chars)
		$keys = array_keys( $sanitized );
		foreach ( $keys as $key ) {
			$this->assertLessThanOrEqual( 64, strlen( $key ) );
		}

		$this->assertEquals( 'value', $sanitized[ 'long_key_' . str_repeat( 'x', 55 ) ] );
	}

	public function test_sanitize_tags_truncates_long_values(): void {
		$long_value = str_repeat( 'y', 2000 );
		$tags       = array( 'data' => $long_value );

		$sanitized = $this->trait_user::test_sanitize_tags( $tags );

		$this->assertIsArray( $sanitized );
		// Value should be truncated to 1024 chars + truncation marker
		$this->assertStringContainsString( '...[truncated]', $sanitized['data'] );
		$this->assertLessThan( 2000, strlen( $sanitized['data'] ) );
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
