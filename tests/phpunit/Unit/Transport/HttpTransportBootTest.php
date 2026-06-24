<?php
/**
 * Tests for HttpTransport boot lifecycle.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Transport;

use WP\MCP\Core\McpServer;
use WP\MCP\Handlers\Initialize\InitializeHandler;
use WP\MCP\Handlers\Prompts\PromptsHandler;
use WP\MCP\Handlers\Resources\ResourcesHandler;
use WP\MCP\Handlers\System\SystemHandler;
use WP\MCP\Handlers\Tools\ToolsHandler;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\Fixtures\DummyObservabilityHandler;
use WP\MCP\Tests\TestCase;
use WP\MCP\Transport\HttpTransport;
use WP\MCP\Transport\Infrastructure\McpTransportContext;

/**
 * Test HttpTransport boot behavior.
 */
final class HttpTransportBootTest extends TestCase {

	public function test_constructor_does_not_register_rest_api_init_hook(): void {
		$transport = new HttpTransport( $this->createTransportContext() );

		$this->assertFalse( has_action( 'rest_api_init', array( $transport, 'register_routes' ) ) );
	}

	public function test_boot_registers_rest_api_init_hook_at_expected_priority(): void {
		$transport = new HttpTransport( $this->createTransportContext() );

		$transport->boot();

		$this->assertSame( 16, has_action( 'rest_api_init', array( $transport, 'register_routes' ) ) );
	}

	private function createTransportContext(): McpTransportContext {
		$server = new McpServer(
			'test-server',
			'mcp/v1',
			'/test-mcp',
			'Test Server',
			'Test server for HTTP transport boot',
			'1.0.0',
			array(),
			DummyErrorHandler::class,
			DummyObservabilityHandler::class
		);

		return new McpTransportContext(
			array(
				'mcp_server'            => $server,
				'initialize_handler'    => new InitializeHandler( $server ),
				'tools_handler'         => new ToolsHandler( $server ),
				'resources_handler'     => new ResourcesHandler( $server ),
				'prompts_handler'       => new PromptsHandler( $server ),
				'system_handler'        => new SystemHandler(),
				'observability_handler' => new DummyObservabilityHandler(),
			)
		);
	}
}
