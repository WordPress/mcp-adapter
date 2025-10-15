<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Handlers;

use WP\MCP\Core\McpServer;
use WP\MCP\Handlers\System\SystemHandler;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\Fixtures\DummyObservabilityHandler;
use WP\MCP\Tests\TestCase;

final class SystemHandlerTest extends TestCase {

	public function test_ping_returns_empty_array(): void {
		new McpServer(
			'srv',
			'mcp/v1',
			'/mcp',
			'Srv',
			'desc',
			'0.0.1',
			array(),
			DummyErrorHandler::class,
			DummyObservabilityHandler::class,
		);
		$handler = new SystemHandler();
		$this->assertSame( array(), $handler->ping() );
	}

	public function test_set_logging_level_missing_level_returns_error(): void {
		new McpServer(
			'srv',
			'mcp/v1',
			'/mcp',
			'Srv',
			'desc',
			'0.0.1',
			array(),
			DummyErrorHandler::class,
			DummyObservabilityHandler::class,
		);
		$handler = new SystemHandler();
		$res     = $handler->set_logging_level( array( 'params' => array() ) );
		$this->assertArrayHasKey( 'error', $res );
	}

	public function test_complete_and_roots_list_return_expected_shapes(): void {
		new McpServer(
			'srv',
			'mcp/v1',
			'/mcp',
			'Srv',
			'desc',
			'0.0.1',
			array(),
			DummyErrorHandler::class,
			DummyObservabilityHandler::class,
		);
		$handler = new SystemHandler();
		$this->assertSame( array(), $handler->complete() );
		$this->assertArrayHasKey( 'roots', $handler->list_roots() );
	}
}
