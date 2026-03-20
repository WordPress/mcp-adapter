<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Handlers;

use WP\MCP\Core\McpServer;
use WP\MCP\Handlers\Initialize\InitializeHandler;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\Fixtures\DummyObservabilityHandler;
use WP\MCP\Tests\TestCase;
use WP\McpSchema\Common\Lifecycle\DTO\Implementation;
use WP\McpSchema\Common\McpConstants;
use WP\McpSchema\Common\Protocol\DTO\InitializeResult;
use WP\McpSchema\Server\Lifecycle\DTO\ServerCapabilities;

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
		$result  = $handler->handle( McpConstants::LATEST_PROTOCOL_VERSION );

		// Returns InitializeResult DTO.
		$this->assertInstanceOf( InitializeResult::class, $result );
		$this->assertSame( McpConstants::LATEST_PROTOCOL_VERSION, $result->getProtocolVersion() );

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
		$result  = $handler->handle( McpConstants::LATEST_PROTOCOL_VERSION );

		// Verify that toArray() produces expected structure.
		$array = $result->toArray();

		$this->assertIsArray( $array );
		$this->assertSame( McpConstants::LATEST_PROTOCOL_VERSION, $array['protocolVersion'] );
		$this->assertSame( 'Test Server', $array['serverInfo']['name'] );
		$this->assertSame( '1.0.0', $array['serverInfo']['version'] );
		$this->assertIsArray( $array['capabilities'] );
		$this->assertArrayHasKey( 'tools', $array['capabilities'] );
		$this->assertArrayHasKey( 'resources', $array['capabilities'] );
		$this->assertArrayHasKey( 'prompts', $array['capabilities'] );
		$this->assertArrayNotHasKey( 'logging', $array['capabilities'] );
		$this->assertArrayNotHasKey( 'completions', $array['capabilities'] );
		$this->assertSame( 'Desc', $array['instructions'] );

		// Verify capability sub-objects have explicit values (not empty arrays).
		$this->assertArrayHasKey( 'listChanged', $array['capabilities']['tools'] );
		$this->assertFalse( $array['capabilities']['tools']['listChanged'] );
		$this->assertArrayHasKey( 'listChanged', $array['capabilities']['prompts'] );
		$this->assertFalse( $array['capabilities']['prompts']['listChanged'] );
		$this->assertArrayHasKey( 'listChanged', $array['capabilities']['resources'] );
		$this->assertFalse( $array['capabilities']['resources']['listChanged'] );
		$this->assertArrayHasKey( 'subscribe', $array['capabilities']['resources'] );
		$this->assertFalse( $array['capabilities']['resources']['subscribe'] );
	}

	/**
	 * Test that capabilities serialize as JSON objects, not arrays.
	 *
	 * MCP specification requires capability objects to always be JSON objects `{}`,
	 * never JSON arrays `[]`. This test verifies the fix for the serialization issue
	 * where empty PHP arrays were serializing as JSON arrays instead of objects.
	 *
	 * @see https://modelcontextprotocol.io/specification/2025-11-25/basic/lifecycle.md
	 */
	public function test_capabilities_serialize_as_json_objects_not_arrays(): void {
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
		$result  = $handler->handle( McpConstants::LATEST_PROTOCOL_VERSION );

		// Simulate the JSON-RPC response serialization chain.
		$result_array = $result->toArray();
		$json         = json_encode( $result_array, JSON_THROW_ON_ERROR );

		// Decode as stdClass objects (not associative arrays) to verify JSON types.
		$decoded = json_decode( $json, false, 512, JSON_THROW_ON_ERROR );

		// Capabilities container must be an object.
		$this->assertInstanceOf(
			\stdClass::class,
			$decoded->capabilities,
			'capabilities must serialize as a JSON object, not an array'
		);

		// Each capability sub-object must be an object, not an array.
		$this->assertInstanceOf(
			\stdClass::class,
			$decoded->capabilities->tools,
			'capabilities.tools must serialize as a JSON object, not an array'
		);
		$this->assertInstanceOf(
			\stdClass::class,
			$decoded->capabilities->resources,
			'capabilities.resources must serialize as a JSON object, not an array'
		);
		$this->assertInstanceOf(
			\stdClass::class,
			$decoded->capabilities->prompts,
			'capabilities.prompts must serialize as a JSON object, not an array'
		);

		// Verify the actual values are present.
		$this->assertFalse( $decoded->capabilities->tools->listChanged );
		$this->assertFalse( $decoded->capabilities->resources->subscribe );
		$this->assertFalse( $decoded->capabilities->resources->listChanged );
		$this->assertFalse( $decoded->capabilities->prompts->listChanged );
	}

	public function test_handle_withSupportedVersion_negotiatesToClientVersion(): void {
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
		$result  = $handler->handle( '2025-06-18' );

		$this->assertInstanceOf( InitializeResult::class, $result );
		$this->assertSame( '2025-06-18', $result->getProtocolVersion() );

		// Verify other fields are still correct.
		$this->assertSame( 'Test Server', $result->getServerInfo()->getName() );
		$this->assertSame( '1.0.0', $result->getServerInfo()->getVersion() );
		$this->assertSame( 'Desc', $result->getInstructions() );
	}

	public function test_handle_withUnsupportedVersion_negotiatesToLatest(): void {
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
		$result  = $handler->handle( '9999-99-99' );

		$this->assertInstanceOf( InitializeResult::class, $result );
		$this->assertSame( McpConstants::LATEST_PROTOCOL_VERSION, $result->getProtocolVersion() );

		// Verify other fields are still correct.
		$this->assertSame( 'Test Server', $result->getServerInfo()->getName() );
	}

	public function test_handle_withEmptyVersion_negotiatesToLatest(): void {
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
		$result  = $handler->handle( '' );

		$this->assertInstanceOf( InitializeResult::class, $result );
		$this->assertSame( McpConstants::LATEST_PROTOCOL_VERSION, $result->getProtocolVersion() );

		// Verify other fields are still correct.
		$this->assertSame( 'Test Server', $result->getServerInfo()->getName() );
	}

	public function test_handle_applies_initialize_response_filter(): void {
		$server = new McpServer(
			'test',
			'mcp/v1',
			'/mcp',
			'Test Server',
			'Original instructions',
			'1.0.0',
			array(),
			DummyErrorHandler::class,
			DummyObservabilityHandler::class,
		);

		$filter = static function ( InitializeResult $result ): InitializeResult {
			$data                 = $result->toArray();
			$data['instructions'] = 'Custom instructions';

			return InitializeResult::fromArray( $data );
		};
		add_filter( 'mcp_adapter_initialize_response', $filter );

		$handler = new InitializeHandler( $server );
		$result  = $handler->handle( McpConstants::LATEST_PROTOCOL_VERSION );

		$this->assertSame( 'Custom instructions', $result->getInstructions() );

		remove_filter( 'mcp_adapter_initialize_response', $filter );
	}
}
