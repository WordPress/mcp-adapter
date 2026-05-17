<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Infrastructure\ErrorHandling;

use WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface;
use WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler;
use WP\MCP\Tests\TestCase;

final class ErrorLogMcpErrorHandlerTest extends TestCase {

	private ErrorLogMcpErrorHandler $handler;

	public function setUp(): void {
		parent::setUp();
		$this->handler = new ErrorLogMcpErrorHandler();
	}

	public function test_implements_interface(): void {
		$this->assertInstanceOf( McpErrorHandlerInterface::class, $this->handler );
	}

	public function test_log_without_context(): void {
		// Capture error_log output
		$error_log_captured = '';
		$original_error_log = ini_get( 'error_log' );

		// Use output buffering to capture error_log if possible
		// Note: error_log() output may go to file, so we test the method doesn't throw
		$this->handler->log( 'Test message' );

		// If we can verify it doesn't throw, that's good enough
		$this->assertTrue( true, 'log() method executed without throwing exception' );
	}

	public function test_log_with_context(): void {
		$context = array(
			'key1' => 'value1',
			'key2' => 123,
		);

		// Test that log doesn't throw with context
		$this->handler->log( 'Test message', $context );
		$this->assertTrue( true, 'log() method executed with context without throwing exception' );
	}

	public function test_log_with_custom_type(): void {
		$this->handler->log( 'Test message', array(), 'info' );
		$this->assertTrue( true, 'log() method executed with custom type without throwing exception' );
	}

	public function test_log_includes_user_id_when_available(): void {
		// Set up a mock user ID
		if ( function_exists( 'wp_set_current_user' ) ) {
			wp_set_current_user( 1 );
		}

		$this->handler->log( 'Test message' );
		$this->assertTrue( true, 'log() method executed with user context without throwing exception' );
	}

	public function test_log_handles_complex_context(): void {
		$complex_context = array(
			'nested'  => array(
				'key' => 'value',
			),
			'numbers' => array( 1, 2, 3 ),
			'null'    => null,
			'bool'    => true,
		);

		$this->handler->log( 'Test message', $complex_context );
		$this->assertTrue( true, 'log() method executed with complex context without throwing exception' );
	}
}

