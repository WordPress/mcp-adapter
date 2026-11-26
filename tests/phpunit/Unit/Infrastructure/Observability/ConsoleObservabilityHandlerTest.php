<?php
/**
 * Tests for ConsoleObservabilityHandler class.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Infrastructure\Observability;

use WP\MCP\Infrastructure\Observability\ConsoleObservabilityHandler;
use WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface;
use WP\MCP\Tests\TestCase;

/**
 * Test ConsoleObservabilityHandler functionality.
 */
final class ConsoleObservabilityHandlerTest extends TestCase {

	private string $original_error_log;

	private string $temp_log = '';

	public function setUp(): void {
		parent::setUp();

		// Skip tests that require file system access in containerized environment.
		if ( ! is_writable( sys_get_temp_dir() ) ) {
			$this->markTestSkipped( 'Temporary directory not writable in test environment' );
		}

		// Capture original error log setting.
		$this->original_error_log = ini_get( 'error_log' );

		// Set up a temporary error log file for testing.
		$temp_log = tempnam( sys_get_temp_dir(), 'mcp_console_test_error_log' );
		if ( ! $temp_log ) {
			return;
		}

		$this->temp_log = $temp_log;
		ini_set( 'error_log', $this->temp_log );
	}

	public function tearDown(): void {
		// Restore original error log setting.
		if ( $this->original_error_log ) {
			ini_set( 'error_log', $this->original_error_log );
		}

		// Clean up temporary log file.
		if ( '' !== $this->temp_log && file_exists( $this->temp_log ) ) {
			unlink( $this->temp_log );
			$this->temp_log = '';
		}

		parent::tearDown();
	}

	public function test_implements_observability_interface(): void {
		$this->assertContains(
			McpObservabilityHandlerInterface::class,
			class_implements( ConsoleObservabilityHandler::class )
		);
	}

	public function test_uses_helper_trait_methods(): void {
		$this->assertTrue( method_exists( ConsoleObservabilityHandler::class, 'format_metric_name' ) );
		$this->assertTrue( method_exists( ConsoleObservabilityHandler::class, 'merge_tags' ) );
		$this->assertTrue( method_exists( ConsoleObservabilityHandler::class, 'sanitize_tags' ) );
	}

	public function test_record_event_logs_to_error_log(): void {
		$log_file = ini_get( 'error_log' );
		if ( file_exists( $log_file ) ) {
			file_put_contents( $log_file, '' );
		}

		$handler = new ConsoleObservabilityHandler();
		$handler->record_event( 'test.event', array( 'key' => 'value' ) );

		$log_content = file_get_contents( $log_file );
		$this->assertNotEmpty( $log_content );
		$this->assertStringContainsString( '[MCP OBSERVABILITY EVENT]', $log_content );
		$this->assertStringContainsString( str_repeat( '=', 80 ), $log_content );
		$this->assertStringContainsString( 'mcp.test.event', $log_content );
		$this->assertStringContainsString( '"key": "value"', $log_content );
	}

	public function test_record_event_outputs_valid_json_structure(): void {
		$log_file = ini_get( 'error_log' );
		if ( file_exists( $log_file ) ) {
			file_put_contents( $log_file, '' );
		}

		$handler = new ConsoleObservabilityHandler();
		$handler->record_event( 'tool.execute', array( 'tool' => 'site-info' ), 45.67 );

		$log_content = file_get_contents( $log_file );
		$separator   = str_repeat( '=', 80 );
		$parts       = explode( $separator, $log_content );

		// The JSON is located between the second and third separator lines.
		$this->assertGreaterThanOrEqual( 3, count( $parts ) );
		$json_string = trim( $parts[2] );

		$decoded = json_decode( $json_string, true );
		$this->assertIsArray( $decoded );
		$this->assertSame( 'mcp.tool.execute', $decoded['event'] );
		$this->assertSame( 45.67, $decoded['duration_ms'] );
		$this->assertArrayHasKey( 'tags', $decoded );
		$this->assertSame( 'site-info', $decoded['tags']['tool'] );
		$this->assertArrayHasKey( 'timestamp', $decoded );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $decoded['timestamp'] );
	}

	public function test_record_event_with_empty_tags_includes_defaults(): void {
		$log_file = ini_get( 'error_log' );
		if ( file_exists( $log_file ) ) {
			file_put_contents( $log_file, '' );
		}

		$handler = new ConsoleObservabilityHandler();
		$handler->record_event( 'server.start' );

		$log_content = file_get_contents( $log_file );
		$separator   = str_repeat( '=', 80 );
		$parts       = explode( $separator, $log_content );
		$this->assertGreaterThanOrEqual( 3, count( $parts ) );

		$decoded = json_decode( trim( $parts[2] ), true );
		$this->assertIsArray( $decoded );
		$this->assertSame( 'mcp.server.start', $decoded['event'] );
		$this->assertNull( $decoded['duration_ms'] );
		$this->assertArrayHasKey( 'site_id', $decoded['tags'] );
		$this->assertArrayHasKey( 'user_id', $decoded['tags'] );
		$this->assertArrayHasKey( 'timestamp', $decoded['tags'] );
	}

	public function test_record_event_with_sanitized_and_redacted_tags(): void {
		$log_file = ini_get( 'error_log' );
		if ( file_exists( $log_file ) ) {
			file_put_contents( $log_file, '' );
		}

		$tags = array(
			'server_id' => 'mcp-default',
			'user_id'   => 42,
			'api_key'   => 'sk-1234567890',
			'details'   => array(
				'step' => 'validation',
			),
		);

		$handler = new ConsoleObservabilityHandler();
		$handler->record_event( 'transport.http.request', $tags, 12.34 );

		$log_content = file_get_contents( $log_file );
		$separator   = str_repeat( '=', 80 );
		$parts       = explode( $separator, $log_content );
		$this->assertGreaterThanOrEqual( 3, count( $parts ) );

		$decoded = json_decode( trim( $parts[2] ), true );
		$this->assertIsArray( $decoded );
		$this->assertSame( 'mcp.transport.http.request', $decoded['event'] );
		$this->assertSame( 12.34, $decoded['duration_ms'] );
		$this->assertSame( 'mcp-default', $decoded['tags']['server_id'] );
		$this->assertSame( '42', $decoded['tags']['user_id'] );
		$this->assertSame( '[REDACTED]', $decoded['tags']['api_key'] );
		$this->assertSame( '{"step":"validation"}', $decoded['tags']['details'] );
	}

	public function test_record_event_formats_metric_name_without_duplicating_prefix(): void {
		$log_file = ini_get( 'error_log' );
		if ( file_exists( $log_file ) ) {
			file_put_contents( $log_file, '' );
		}

		$handler = new ConsoleObservabilityHandler();
		$handler->record_event( 'mcp.already.prefixed' );

		$log_content = file_get_contents( $log_file );
		$this->assertStringContainsString( 'mcp.already.prefixed', $log_content );
		$this->assertStringNotContainsString( 'mcp.mcp.already.prefixed', $log_content );
	}

	public function test_record_event_normalizes_metric_name_special_characters(): void {
		$log_file = ini_get( 'error_log' );
		if ( file_exists( $log_file ) ) {
			file_put_contents( $log_file, '' );
		}

		$handler = new ConsoleObservabilityHandler();
		$handler->record_event( 'Custom/Event Name!' );

		$log_content = file_get_contents( $log_file );
		$this->assertStringContainsString( 'mcp.custom.event.name', $log_content );
	}
}
