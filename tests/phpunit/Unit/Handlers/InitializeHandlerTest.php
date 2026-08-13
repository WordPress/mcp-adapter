<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Handlers;

use WP\MCP\Core\McpServer;
use WP\MCP\Core\McpVersionNegotiator;
use WP\MCP\Handlers\Initialize\InitializeHandler;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\Fixtures\DummyObservabilityHandler;
use WP\MCP\Tests\TestCase;
use WP\McpSchema\Common\Protocol\DTO\InitializeResult;

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
		$result  = $handler->handle( McpVersionNegotiator::LEGACY_PROTOCOL_VERSION );

		$this->assertSame(
			array(
				'protocolVersion' => McpVersionNegotiator::LEGACY_PROTOCOL_VERSION,
				'capabilities'    => array(
					'prompts'   => array( 'listChanged' => false ),
					'resources' => array(
						'subscribe'   => false,
						'listChanged' => false,
					),
					'tools'     => array( 'listChanged' => false ),
				),
				'serverInfo'      => array(
					'name'    => 'Test Server',
					'version' => '1.0.0',
				),
				'instructions'    => 'Desc',
			),
			$result
		);
	}

	public function test_handle_returns_correct_array(): void {
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
		$array   = $handler->handle( McpVersionNegotiator::LEGACY_PROTOCOL_VERSION );

		$this->assertIsArray( $array );
		$this->assertSame( McpVersionNegotiator::LEGACY_PROTOCOL_VERSION, $array['protocolVersion'] );
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
		$result  = $handler->handle( McpVersionNegotiator::LEGACY_PROTOCOL_VERSION );

		// Simulate the JSON-RPC response serialization chain.
		$json = wp_json_encode( $result );
		$this->assertIsString( $json );

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
		$result  = $handler->handle( McpVersionNegotiator::LEGACY_PROTOCOL_VERSION );

		$this->assertSame( McpVersionNegotiator::LEGACY_PROTOCOL_VERSION, $result['protocolVersion'] );

		// Verify other fields are still correct.
		$this->assertSame( 'Test Server', $result['serverInfo']['name'] );
		$this->assertSame( '1.0.0', $result['serverInfo']['version'] );
		$this->assertSame( 'Desc', $result['instructions'] );
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

		$this->assertSame( McpVersionNegotiator::LEGACY_PROTOCOL_VERSION, $result['protocolVersion'] );

		// Verify other fields are still correct.
		$this->assertSame( 'Test Server', $result['serverInfo']['name'] );
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

		$this->assertSame( McpVersionNegotiator::LEGACY_PROTOCOL_VERSION, $result['protocolVersion'] );

		// Verify other fields are still correct.
		$this->assertSame( 'Test Server', $result['serverInfo']['name'] );
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

		$filter = function ( InitializeResult $result ): InitializeResult {
			$this->assertSame( 'Original instructions', $result->getInstructions() );
			$data                 = $result->toArray();
			$data['instructions'] = 'Custom instructions';

			return InitializeResult::fromArray( $data );
		};
		add_filter( 'mcp_adapter_initialize_response', $filter );

		$handler = new InitializeHandler( $server );
		$result  = $handler->handle( McpVersionNegotiator::LEGACY_PROTOCOL_VERSION );

		$this->assertSame( 'Custom instructions', $result['instructions'] );

		remove_filter( 'mcp_adapter_initialize_response', $filter );
	}
}
