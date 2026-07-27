<?php
/**
 * Tests for McpAdapter::set_current_server() / get_current_server().
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Core;

use WP\MCP\Core\McpAdapter;
use WP\MCP\Core\McpServer;
use WP\MCP\Infrastructure\ErrorHandling\NullMcpErrorHandler;
use WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler;
use WP\MCP\Tests\TestCase;
use WP\MCP\Transport\HttpTransport;

/**
 * Verifies the per-request "current server" accessor used to thread the
 * MCP server context down to filters exposed by the built-in abilities.
 */
final class McpAdapterCurrentServerTest extends TestCase {

	public function tear_down(): void {
		McpAdapter::instance()->set_current_server( null );
		parent::tear_down();
	}

	public function test_current_server_defaults_to_null(): void {
		$this->assertNull( McpAdapter::instance()->get_current_server() );
	}

	public function test_set_and_get_current_server_round_trip(): void {
		$server = new McpServer(
			'test-current-server',
			'wp-mcp/v1',
			'current-server',
			'Test Server',
			'Test description',
			'0.0.1',
			array( HttpTransport::class ),
			NullMcpErrorHandler::class,
			NullMcpObservabilityHandler::class,
			array(),
			array(),
			array()
		);

		McpAdapter::instance()->set_current_server( $server );
		$this->assertSame( $server, McpAdapter::instance()->get_current_server() );
	}

	public function test_setting_null_clears_the_current_server(): void {
		$server = new McpServer(
			'test-clear-server',
			'wp-mcp/v1',
			'clear-server',
			'Test Server',
			'Test description',
			'0.0.1',
			array( HttpTransport::class ),
			NullMcpErrorHandler::class,
			NullMcpObservabilityHandler::class,
			array(),
			array(),
			array()
		);

		McpAdapter::instance()->set_current_server( $server );
		McpAdapter::instance()->set_current_server( null );
		$this->assertNull( McpAdapter::instance()->get_current_server() );
	}
}
