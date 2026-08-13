<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\ErrorHandlers;

use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;
use WP\MCP\Tests\TestCase;

/**
 * Tests for McpErrorFactory error envelope structure.
 */
final class ErrorEnvelopeTest extends TestCase {

	public function test_error_envelopes_have_consistent_shape(): void {
		$error = McpErrorFactory::missing_parameter( 0, 'name' );

		$this->assertSame( '2.0', $error['jsonrpc'] );
		$this->assertSame( 0, $error['id'] );
		$this->assertIsInt( $error['error']['code'] );
		$this->assertIsString( $error['error']['message'] );
	}

	/**
	 * @dataProvider provide_convenience_error_envelopes
	 *
	 * @param array{jsonrpc: string, error: array{code: int, message: string}, id: int} $error Error response.
	 * @param int                                                                      $expected_id Expected request ID.
	 * @param int                                                                      $expected_code Expected error code.
	 * @param string                                                                   $expected_message Expected message fragment.
	 */
	public function test_convenience_error_envelopes( array $error, int $expected_id, int $expected_code, string $expected_message ): void {
		$this->assertSame( $expected_id, $error['id'] );
		$this->assertSame( $expected_code, $error['error']['code'] );
		$this->assertStringContainsString( $expected_message, $error['error']['message'] );
	}

	/**
	 * @return array<string, array{array<string, mixed>, int, int, string}>
	 */
	public function provide_convenience_error_envelopes(): array {
		return array(
			'missing parameter'  => array( McpErrorFactory::missing_parameter( 123, 'test_param' ), 123, McpErrorFactory::INVALID_PARAMS, 'test_param' ),
			'method not found'   => array( McpErrorFactory::method_not_found( 456, 'test/method' ), 456, McpErrorFactory::METHOD_NOT_FOUND, 'test/method' ),
			'internal error'     => array( McpErrorFactory::internal_error( 789, 'Something went wrong' ), 789, McpErrorFactory::INTERNAL_ERROR, 'Something went wrong' ),
			'tool not found'     => array( McpErrorFactory::tool_not_found( 101, 'missing-tool' ), 101, McpErrorFactory::TOOL_NOT_FOUND, 'missing-tool' ),
			'resource not found' => array( McpErrorFactory::resource_not_found( 102, 'missing-resource' ), 102, McpErrorFactory::RESOURCE_NOT_FOUND, 'missing-resource' ),
			'prompt not found'   => array( McpErrorFactory::prompt_not_found( 103, 'missing-prompt' ), 103, McpErrorFactory::PROMPT_NOT_FOUND, 'missing-prompt' ),
			'permission denied'  => array( McpErrorFactory::permission_denied( 104, 'Access denied' ), 104, McpErrorFactory::PERMISSION_DENIED, 'Access denied' ),
			'unauthorized'       => array( McpErrorFactory::unauthorized( 105, 'Not logged in' ), 105, McpErrorFactory::UNAUTHORIZED, 'Not logged in' ),
			'parse error'        => array( McpErrorFactory::parse_error( 106, 'Invalid JSON' ), 106, McpErrorFactory::PARSE_ERROR, 'Invalid JSON' ),
			'invalid request'    => array( McpErrorFactory::invalid_request( 107, 'Missing field' ), 107, McpErrorFactory::INVALID_REQUEST, 'Missing field' ),
			'invalid params'     => array( McpErrorFactory::invalid_params( 108, 'Wrong type' ), 108, McpErrorFactory::INVALID_PARAMS, 'Wrong type' ),
			'mcp disabled'       => array( McpErrorFactory::mcp_disabled( 109 ), 109, McpErrorFactory::SERVER_ERROR, 'disabled' ),
		);
	}

	public function test_jsonrpc_message_validation_valid(): void {
		$valid_message = array(
			'jsonrpc' => '2.0',
			'method'  => 'test',
			'id'      => 1,
		);

		$this->assertTrue( McpErrorFactory::validate_jsonrpc_message( $valid_message ) );
	}

	public function test_jsonrpc_message_validation_invalid_version_returns_array(): void {
		$invalid_message = array(
			'jsonrpc' => '1.0',
			'method'  => 'test',
			'id'      => 1,
		);

		$result = McpErrorFactory::validate_jsonrpc_message( $invalid_message );

		$this->assertIsArray( $result );
		$this->assertSame( McpErrorFactory::INVALID_REQUEST, $result['error']['code'] );
		$this->assertArrayHasKey( 'id', $result );
		$this->assertNull( $result['id'] );
	}

	public function test_jsonrpc_message_validation_missing_payload_returns_array(): void {
		$invalid_message = array(
			'jsonrpc' => '2.0',
			'id'      => 1,
		);

		$result = McpErrorFactory::validate_jsonrpc_message( $invalid_message );

		$this->assertIsArray( $result );
		$this->assertSame( McpErrorFactory::INVALID_REQUEST, $result['error']['code'] );
		$this->assertArrayHasKey( 'id', $result );
		$this->assertNull( $result['id'] );
	}
}
