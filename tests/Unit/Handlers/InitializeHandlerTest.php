<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Handlers;

use WP\MCP\Core\McpServer;
use WP\MCP\Handlers\Initialize\InitializeHandler;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\Fixtures\DummyObservabilityHandler;
use WP\MCP\Tests\TestCase;
use WP\McpSchema\Common\Lifecycle\Implementation;
use WP\McpSchema\Common\Protocol\InitializeResult;
use WP\McpSchema\Server\Lifecycle\ServerCapabilities;

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

		// Returns InitializeResult DTO.
		$this->assertInstanceOf( InitializeResult::class, $result );
		$this->assertSame( '2025-06-18', $result->getProtocolVersion() );

		// Server info.
		$server_info = $result->getServerInfo();
		$this->assertInstanceOf( Implementation::class, $server_info );
		$this->assertSame( 'Test Server', $server_info->getName() );
		$this->assertSame( '1.0.0', $server_info->getVersion() );

		// Capabilities.
		$capabilities = $result->getCapabilities();
		$this->assertInstanceOf( ServerCapabilities::class, $capabilities );

		// Instructions.
		$this->assertSame( 'Desc', $result->getInstructions() );
	}

	public function test_handle_returns_dto_that_converts_to_correct_array(): void {
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

		// Verify that toArray() produces expected structure.
		$array = $result->toArray();

		$this->assertIsArray( $array );
		$this->assertSame( '2025-06-18', $array['protocolVersion'] );
		$this->assertSame( 'Test Server', $array['serverInfo']['name'] );
		$this->assertSame( '1.0.0', $array['serverInfo']['version'] );
		$this->assertIsArray( $array['capabilities'] );
		$this->assertSame( 'Desc', $array['instructions'] );
	}
}
