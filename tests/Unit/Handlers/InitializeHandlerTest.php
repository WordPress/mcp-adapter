<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Handlers;

use WP\MCP\Core\McpServer;
use WP\MCP\Handlers\Initialize\InitializeHandler;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\Fixtures\DummyObservabilityHandler;
use WP\MCP\Tests\TestCase;

final class InitializeHandlerTest extends TestCase {

	public function test_handle_returns_expected_shape(): void {
		$server = new McpServer(
			'test',
			'mcp/v1',
			'/mcp',
			'Test Server',
			'Desc',
			'1.0.0',
			array(),
			DummyErrorHandler::class,
			DummyObservabilityHandler::class,
		);

		$handler = new InitializeHandler( $server );
		$result  = $handler->handle();

		$this->assertIsArray( $result );
		$this->assertSame( '2025-06-18', $result['protocolVersion'] );
		$this->assertSame( 'Test Server', $result['serverInfo']['name'] );
		$this->assertSame( '1.0.0', $result['serverInfo']['version'] );
		$this->assertIsObject( $result['capabilities'] );
		$this->assertSame( 'Desc', $result['instructions'] );
	}
}
