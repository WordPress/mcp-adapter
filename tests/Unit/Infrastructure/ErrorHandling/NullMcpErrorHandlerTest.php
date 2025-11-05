<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Infrastructure\ErrorHandling;

use WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface;
use WP\MCP\Infrastructure\ErrorHandling\NullMcpErrorHandler;
use WP\MCP\Tests\TestCase;

final class NullMcpErrorHandlerTest extends TestCase {

	private NullMcpErrorHandler $handler;

	public function setUp(): void {
		parent::setUp();
		$this->handler = new NullMcpErrorHandler();
	}

	public function test_implements_interface(): void {
		$this->assertInstanceOf( McpErrorHandlerInterface::class, $this->handler );
	}

	public function test_log_does_nothing(): void {
		// The log method should execute without error but do nothing
		$this->handler->log( 'Test message' );
		$this->assertTrue( true, 'log() method executed without throwing exception' );
	}

	public function test_log_with_context_does_nothing(): void {
		$context = array(
			'key1' => 'value1',
			'key2' => 123,
		);

		$this->handler->log( 'Test message', $context );
		$this->assertTrue( true, 'log() method executed with context without throwing exception' );
	}

	public function test_log_with_custom_type_does_nothing(): void {
		$this->handler->log( 'Test message', array(), 'info' );
		$this->assertTrue( true, 'log() method executed with custom type without throwing exception' );
	}

	public function test_log_handles_empty_message(): void {
		$this->handler->log( '' );
		$this->assertTrue( true, 'log() method executed with empty message without throwing exception' );
	}

	public function test_log_handles_complex_context(): void {
		$complex_context = array(
			'nested'  => array(
				'key' => 'value',
			),
			'numbers' => array( 1, 2, 3 ),
		);

		$this->handler->log( 'Test message', $complex_context );
		$this->assertTrue( true, 'log() method executed with complex context without throwing exception' );
	}
}
