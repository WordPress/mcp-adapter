<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit;

use WP\MCP\Core\McpServer;
use WP\MCP\Infrastructure\ErrorHandling\NullMcpErrorHandler;
use WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler;
use WP\MCP\Tests\Fixtures\DummyTransport;
use WP\MCP\Tests\TestCase;

final class McpServerTest extends TestCase {

	public function test_it_initializes_and_exposes_basic_getters(): void {
		$server = new McpServer(
			'test-server',
			'mcp/v1',
			'/mcp',
			'Test MCP',
			'Testing server',
			'0.1.0',
			array( DummyTransport::class ),
			NullMcpErrorHandler::class,
			NullMcpObservabilityHandler::class,
		);

		$this->assertSame( 'test-server', $server->get_server_id() );
		$this->assertSame( 'mcp/v1', $server->get_server_route_namespace() );
		$this->assertSame( '/mcp', $server->get_server_route() );
		$this->assertSame( 'Test MCP', $server->get_server_name() );
		$this->assertSame( 'Testing server', $server->get_server_description() );
		$this->assertSame( '0.1.0', $server->get_server_version() );
	}
}
