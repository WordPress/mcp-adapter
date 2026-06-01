<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Integration;

use WP\MCP\Core\McpServer;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\Fixtures\DummyObservabilityHandler;
use WP\MCP\Tests\TestCase;

final class WordPressFiltersTest extends TestCase {

	public function test_validation_toggle_filter_is_respected(): void {
		add_filter( 'mcp_adapter_validation_enabled', '__return_false' );

		$server = new McpServer(
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

		$this->assertFalse( $server->is_mcp_validation_enabled() );

		remove_filter( 'mcp_adapter_validation_enabled', '__return_false' );
	}
}
