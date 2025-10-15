<?php
/**
 * Tests for ErrorLogMcpObservabilityHandler class.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Infrastructure\Observability;

use WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface;
use WP\MCP\Infrastructure\Observability\ErrorLogMcpObservabilityHandler;
use WP\MCP\Tests\TestCase;

/**
 * Test ErrorLogMcpObservabilityHandler functionality.
 */
final class ErrorLogMcpObservabilityHandlerTest extends TestCase {

	private string $original_error_log;

	public function set_up(): void {
		parent::set_up();

		// Skip tests that require file system access in containerized environment
		if ( ! is_writable( sys_get_temp_dir() ) ) {
			$this->markTestSkipped( 'Temporary directory not writable in test environment' );
		}

		// Capture original error log setting
		$this->original_error_log = ini_get( 'error_log' );

		// Set up a temporary error log file for testing
		$temp_log = tempnam( sys_get_temp_dir(), 'mcp_test_error_log' );
		if ( ! $temp_log ) {
			return;
		}

		ini_set( 'error_log', $temp_log );
	}

	public function tear_down(): void {
		// Restore original error log setting
		if ( $this->original_error_log ) {
			ini_set( 'error_log', $this->original_error_log );
		}

		parent::tear_down();
	}

	public function test_implements_observability_interface(): void {
		$this->assertContains(
			McpObservabilityHandlerInterface::class,
			class_implements( ErrorLogMcpObservabilityHandler::class )
		);
	}

	public function test_record_event_logs_to_error_log(): void {
		// Clear any existing log content
		$log_file = ini_get( 'error_log' );
		if ( file_exists( $log_file ) ) {
			file_put_contents( $log_file, '' );
		}

		$handler = new ErrorLogMcpObservabilityHandler();
		$handler->record_event( 'test.event', array( 'key' => 'value' ) );

		// Check that something was logged
		$log_content = file_get_contents( $log_file );
		$this->assertStringContainsString( '[MCP Observability] EVENT', $log_content );
		$this->assertStringContainsString( 'mcp.test.event', $log_content );
		$this->assertStringContainsString( 'key=value', $log_content );
	}

	public function test_record_event_with_duration_logs_to_error_log(): void {
		// Clear any existing log content
		$log_file = ini_get( 'error_log' );
		if ( file_exists( $log_file ) ) {
			file_put_contents( $log_file, '' );
		}

		$handler = new ErrorLogMcpObservabilityHandler();
		$handler->record_event( 'test.timing', array( 'operation' => 'test' ), 123.45 );

		// Check that something was logged with duration
		$log_content = file_get_contents( $log_file );
		$this->assertStringContainsString( '[MCP Observability] EVENT', $log_content );
		$this->assertStringContainsString( 'mcp.test.timing', $log_content );
		$this->assertStringContainsString( '123.45ms', $log_content );
		$this->assertStringContainsString( 'operation=test', $log_content );
	}

	public function test_record_event_formats_metric_name(): void {
		// Clear any existing log content
		$log_file = ini_get( 'error_log' );
		if ( file_exists( $log_file ) ) {
			file_put_contents( $log_file, '' );
		}

		// Test metric name formatting
		$handler = new ErrorLogMcpObservabilityHandler();
		$handler->record_event( 'raw.event.name' );

		$log_content = file_get_contents( $log_file );
		$this->assertStringContainsString( 'mcp.raw.event.name', $log_content );
	}

	public function test_record_event_with_empty_tags(): void {
		// Clear any existing log content
		$log_file = ini_get( 'error_log' );
		if ( file_exists( $log_file ) ) {
			file_put_contents( $log_file, '' );
		}

		$handler = new ErrorLogMcpObservabilityHandler();
		$handler->record_event( 'test.event' );

		$log_content = file_get_contents( $log_file );
		$this->assertStringContainsString( '[MCP Observability] EVENT', $log_content );
		$this->assertStringContainsString( 'mcp.test.event', $log_content );
	}

	public function test_record_event_with_duration_and_empty_tags(): void {
		// Clear any existing log content
		$log_file = ini_get( 'error_log' );
		if ( file_exists( $log_file ) ) {
			file_put_contents( $log_file, '' );
		}

		$handler = new ErrorLogMcpObservabilityHandler();
		$handler->record_event( 'test.timing', array(), 100.0 );

		$log_content = file_get_contents( $log_file );
		$this->assertStringContainsString( '[MCP Observability] EVENT', $log_content );
		$this->assertStringContainsString( 'mcp.test.timing', $log_content );
		$this->assertStringContainsString( '100.00ms', $log_content );
	}

	public function test_record_event_with_complex_tags(): void {
		// Clear any existing log content
		$log_file = ini_get( 'error_log' );
		if ( file_exists( $log_file ) ) {
			file_put_contents( $log_file, '' );
		}

		$complex_tags = array(
			'server_id' => 'test-server',
			'user_id'   => 123,
			'method'    => 'tools/call',
			'tool_name' => 'test-tool',
			'success'   => true,
		);

		$handler = new ErrorLogMcpObservabilityHandler();
		$handler->record_event( 'tool.execution', $complex_tags );

		$log_content = file_get_contents( $log_file );
		$this->assertStringContainsString( '[MCP Observability] EVENT', $log_content );
		$this->assertStringContainsString( 'mcp.tool.execution', $log_content );
		$this->assertStringContainsString( 'server_id=test-server', $log_content );
		$this->assertStringContainsString( 'user_id=123', $log_content );
		$this->assertStringContainsString( 'method=tools/call', $log_content );
	}

	public function test_format_tags_method(): void {
		// Use reflection to access private method
		$reflection         = new \ReflectionClass( ErrorLogMcpObservabilityHandler::class );
		$format_tags_method = $reflection->getMethod( 'format_tags' );
		$format_tags_method->setAccessible( true );

		$tags = array(
			'key1' => 'value1',
			'key2' => 'value2',
		);

		$result = $format_tags_method->invoke( null, $tags );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'key1=value1', $result );
		$this->assertStringContainsString( 'key2=value2', $result );
		$this->assertStringStartsWith( '[', $result );
		$this->assertStringEndsWith( ']', $result );
	}

	public function test_format_tags_with_empty_array(): void {
		// Use reflection to access private method
		$reflection         = new \ReflectionClass( ErrorLogMcpObservabilityHandler::class );
		$format_tags_method = $reflection->getMethod( 'format_tags' );
		$format_tags_method->setAccessible( true );

		$result = $format_tags_method->invoke( null, array() );

		$this->assertEquals( '', $result );
	}

	public function test_uses_helper_trait_methods(): void {
		// Verify that the class uses the helper trait by checking method existence
		$this->assertTrue( method_exists( ErrorLogMcpObservabilityHandler::class, 'format_metric_name' ) );
		$this->assertTrue( method_exists( ErrorLogMcpObservabilityHandler::class, 'merge_tags' ) );
		$this->assertTrue( method_exists( ErrorLogMcpObservabilityHandler::class, 'sanitize_tags' ) );
	}
}
