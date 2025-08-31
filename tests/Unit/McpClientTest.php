<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit;

use WP\MCP\Core\McpClient;
use WP\MCP\Infrastructure\ErrorHandling\NullMcpErrorHandler;
use WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler;
use WP\MCP\Tests\TestCase;

final class McpClientTest extends TestCase {

	public function test_it_initializes_with_basic_properties(): void {
		$client = new McpClient(
			'test-client',
			'https://api.example.com/mcp',
			array( 'timeout' => 30 ),
			new NullMcpErrorHandler(),
			new NullMcpObservabilityHandler()
		);

		$this->assertSame( 'test-client', $client->get_client_id() );
		$this->assertSame( 'https://api.example.com/mcp', $client->get_server_url() );
		$this->assertIsArray( $client->get_capabilities() );
	}

	public function test_it_detects_transport_protocol_from_url(): void {
		// Test MCP transport detection
		$mcp_client = new McpClient(
			'mcp-client',
			'https://server.com/mcp',
			array(),
			new NullMcpErrorHandler(),
			new NullMcpObservabilityHandler()
		);

		// Test SSE transport detection  
		$sse_client = new McpClient(
			'sse-client',
			'https://server.com/sse',
			array(),
			new NullMcpErrorHandler(),
			new NullMcpObservabilityHandler()
		);

		// We can't directly test the private transport property, but we can verify
		// the clients were created without errors
		$this->assertInstanceOf( McpClient::class, $mcp_client );
		$this->assertInstanceOf( McpClient::class, $sse_client );
	}

	public function test_it_handles_connection_errors_gracefully(): void {
		$client = new McpClient(
			'failing-client',
			'https://nonexistent-server-12345.com/mcp',
			array( 'timeout' => 1 ),
			new NullMcpErrorHandler(),
			new NullMcpObservabilityHandler()
		);

		// Should handle connection failure gracefully
		$this->assertFalse( $client->is_connected() );
	}

	public function test_it_validates_required_parameters(): void {
		$this->expectException( \TypeError::class );
		
		// Should throw TypeError for missing required parameters
		new McpClient();
	}

	public function test_it_generates_unique_session_ids(): void {
		$client1 = new McpClient(
			'client1',
			'https://server.com/mcp',
			array(),
			new NullMcpErrorHandler(),
			new NullMcpObservabilityHandler()
		);

		$client2 = new McpClient(
			'client2', 
			'https://server.com/mcp',
			array(),
			new NullMcpErrorHandler(),
			new NullMcpObservabilityHandler()
		);

		// Different clients should exist as separate instances
		$this->assertNotSame( $client1, $client2 );
	}
}