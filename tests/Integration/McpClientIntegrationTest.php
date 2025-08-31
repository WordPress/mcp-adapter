<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Integration;

use WP\MCP\Core\McpAdapter;
use WP\MCP\Core\McpClient;
use WP\MCP\Infrastructure\ErrorHandling\NullMcpErrorHandler;
use WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler;
use WP\MCP\Tests\TestCase;

final class McpClientIntegrationTest extends TestCase {

	public function test_adapter_creates_and_manages_clients(): void {
		$adapter = McpAdapter::instance();
		
		$client = $adapter->create_client(
			'test-integration-client',
			'https://example.com/mcp',
			array( 'timeout' => 5 ),
			NullMcpErrorHandler::class,
			NullMcpObservabilityHandler::class
		);

		// Should return a client instance (even if connection fails)
		$this->assertInstanceOf( McpClient::class, $client );
		
		// Adapter should track the client
		$clients = $adapter->get_clients();
		$this->assertArrayHasKey( 'test-integration-client', $clients );
		$this->assertSame( $client, $clients['test-integration-client'] );
	}

	public function test_client_abilities_registration_workflow(): void {
		// This test would need a mock MCP server to test the full workflow
		// For now, verify the adapter has the capability to register abilities
		$adapter = McpAdapter::instance();
		
		$this->assertTrue( method_exists( $adapter, 'create_client' ) );
		$this->assertTrue( method_exists( $adapter, 'get_clients' ) );
	}

	public function test_client_respects_ability_naming_conventions(): void {
		$adapter = McpAdapter::instance();
		
		// Verify the adapter follows the naming convention we established
		$client = $adapter->create_client(
			'test-naming-client',
			'https://example.com/mcp',
			array(),
			NullMcpErrorHandler::class,
			NullMcpObservabilityHandler::class
		);

		$this->assertInstanceOf( McpClient::class, $client );
		$this->assertSame( 'test-naming-client', $client->get_client_id() );
	}
}