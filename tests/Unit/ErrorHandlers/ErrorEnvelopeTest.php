<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\ErrorHandlers;

use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;
use WP\MCP\Tests\TestCase;
use WP\McpSchema\Common\JsonRpc\DTO\JSONRPCErrorResponse;

/**
 * Tests for McpErrorFactory error envelope structure.
 *
 * Note: McpErrorFactory now returns typed DTOs (JSONRPCErrorResponse) instead of arrays.
 * These tests verify that the DTOs contain the correct data and can be serialized properly.
 */
final class ErrorEnvelopeTest extends TestCase {

	public function test_error_envelopes_have_consistent_shape(): void {
		$err = McpErrorFactory::missing_parameter( 0, 'name' );

		// Verify it's a DTO
		$this->assertInstanceOf( JSONRPCErrorResponse::class, $err );

		// Verify DTO properties
		$this->assertSame( '2.0', $err->getJsonrpc() );
		$this->assertNotNull( $err->getError() );
		$this->assertNotNull( $err->getError()->getCode() );
		$this->assertNotNull( $err->getError()->getMessage() );

		// Verify toArray() produces consistent structure
		$arr = $err->toArray();
		$this->assertArrayHasKey( 'jsonrpc', $arr );
		$this->assertSame( '2.0', $arr['jsonrpc'] );
		$this->assertArrayHasKey( 'error', $arr );
		$this->assertArrayHasKey( 'code', $arr['error'] );
		$this->assertArrayHasKey( 'message', $arr['error'] );
	}

	/**
	 * Test missing_parameter() convenience wrapper.
	 * Note: This uses the standard INVALID_PARAMS error code.
	 */
	public function test_missing_parameter_error(): void {
		$err = McpErrorFactory::missing_parameter( 123, 'test_param' );

		$this->assertInstanceOf( JSONRPCErrorResponse::class, $err );
		$this->assertSame( 123, $err->getId() );
		$this->assertSame( McpErrorFactory::INVALID_PARAMS, $err->getError()->getCode() );
		$this->assertStringContainsString( 'test_param', $err->getError()->getMessage() );
	}

	public function test_method_not_found_error(): void {
		$err = McpErrorFactory::method_not_found( 456, 'test/method' );

		$this->assertInstanceOf( JSONRPCErrorResponse::class, $err );
		$this->assertSame( 456, $err->getId() );
		$this->assertSame( McpErrorFactory::METHOD_NOT_FOUND, $err->getError()->getCode() );
		$this->assertStringContainsString( 'test/method', $err->getError()->getMessage() );
	}

	public function test_internal_error(): void {
		$err = McpErrorFactory::internal_error( 789, 'Something went wrong' );

		$this->assertInstanceOf( JSONRPCErrorResponse::class, $err );
		$this->assertSame( 789, $err->getId() );
		$this->assertSame( McpErrorFactory::INTERNAL_ERROR, $err->getError()->getCode() );
		$this->assertStringContainsString( 'Something went wrong', $err->getError()->getMessage() );
	}

	public function test_tool_not_found_error(): void {
		$err = McpErrorFactory::tool_not_found( 101, 'missing-tool' );

		$this->assertInstanceOf( JSONRPCErrorResponse::class, $err );
		$this->assertSame( 101, $err->getId() );
		$this->assertSame( McpErrorFactory::TOOL_NOT_FOUND, $err->getError()->getCode() );
		$this->assertStringContainsString( 'missing-tool', $err->getError()->getMessage() );
	}

	public function test_resource_not_found_error(): void {
		$err = McpErrorFactory::resource_not_found( 102, 'missing-resource' );

		$this->assertInstanceOf( JSONRPCErrorResponse::class, $err );
		$this->assertSame( 102, $err->getId() );
		$this->assertSame( McpErrorFactory::RESOURCE_NOT_FOUND, $err->getError()->getCode() );
		$this->assertStringContainsString( 'missing-resource', $err->getError()->getMessage() );
	}

	public function test_prompt_not_found_error(): void {
		$err = McpErrorFactory::prompt_not_found( 103, 'missing-prompt' );

		$this->assertInstanceOf( JSONRPCErrorResponse::class, $err );
		$this->assertSame( 103, $err->getId() );
		$this->assertSame( McpErrorFactory::PROMPT_NOT_FOUND, $err->getError()->getCode() );
		$this->assertStringContainsString( 'missing-prompt', $err->getError()->getMessage() );
	}

	public function test_permission_denied_error(): void {
		$err = McpErrorFactory::permission_denied( 104, 'Access denied' );

		$this->assertInstanceOf( JSONRPCErrorResponse::class, $err );
		$this->assertSame( 104, $err->getId() );
		$this->assertSame( McpErrorFactory::PERMISSION_DENIED, $err->getError()->getCode() );
		$this->assertStringContainsString( 'Access denied', $err->getError()->getMessage() );
	}

	public function test_unauthorized_error(): void {
		$err = McpErrorFactory::unauthorized( 105, 'Not logged in' );

		$this->assertInstanceOf( JSONRPCErrorResponse::class, $err );
		$this->assertSame( 105, $err->getId() );
		$this->assertSame( McpErrorFactory::UNAUTHORIZED, $err->getError()->getCode() );
		$this->assertStringContainsString( 'Not logged in', $err->getError()->getMessage() );
	}

	public function test_parse_error(): void {
		$err = McpErrorFactory::parse_error( 106, 'Invalid JSON' );

		$this->assertInstanceOf( JSONRPCErrorResponse::class, $err );
		$this->assertSame( 106, $err->getId() );
		$this->assertSame( McpErrorFactory::PARSE_ERROR, $err->getError()->getCode() );
		$this->assertStringContainsString( 'Invalid JSON', $err->getError()->getMessage() );
	}

	public function test_invalid_request_error(): void {
		$err = McpErrorFactory::invalid_request( 107, 'Missing field' );

		$this->assertInstanceOf( JSONRPCErrorResponse::class, $err );
		$this->assertSame( 107, $err->getId() );
		$this->assertSame( McpErrorFactory::INVALID_REQUEST, $err->getError()->getCode() );
		$this->assertStringContainsString( 'Missing field', $err->getError()->getMessage() );
	}

	/**
	 * Test invalid_params() method.
	 * Note: missing_parameter() is a convenience wrapper that also uses this error code.
	 */
	public function test_invalid_params_error(): void {
		$err = McpErrorFactory::invalid_params( 108, 'Wrong type' );

		$this->assertInstanceOf( JSONRPCErrorResponse::class, $err );
		$this->assertSame( 108, $err->getId() );
		$this->assertSame( McpErrorFactory::INVALID_PARAMS, $err->getError()->getCode() );
		$this->assertStringContainsString( 'Wrong type', $err->getError()->getMessage() );
	}

	/**
	 * Test mcp_disabled() convenience wrapper.
	 * Note: This uses the standard SERVER_ERROR error code.
	 */
	public function test_mcp_disabled_error(): void {
		$err = McpErrorFactory::mcp_disabled( 109 );

		$this->assertInstanceOf( JSONRPCErrorResponse::class, $err );
		$this->assertSame( 109, $err->getId() );
		$this->assertSame( McpErrorFactory::SERVER_ERROR, $err->getError()->getCode() );
		$this->assertStringContainsString( 'disabled', $err->getError()->getMessage() );
	}

	public function test_jsonrpc_message_validation_valid(): void {
		$valid_message = array(
			'jsonrpc' => '2.0',
			'method'  => 'test',
			'id'      => 1,
		);

		$result = McpErrorFactory::validate_jsonrpc_message( $valid_message );
		$this->assertTrue( $result );
	}

	public function test_jsonrpc_message_validation_invalid_version(): void {
		$invalid_message = array(
			'jsonrpc' => '1.0',
			'method'  => 'test',
			'id'      => 1,
		);

		$result = McpErrorFactory::validate_jsonrpc_message( $invalid_message );
		// Now returns a DTO on error, not an array
		$this->assertInstanceOf( JSONRPCErrorResponse::class, $result );
		$this->assertNotNull( $result->getError() );
	}

	public function test_jsonrpc_message_validation_missing_method(): void {
		$invalid_message = array(
			'jsonrpc' => '2.0',
			'id'      => 1,
		);

		$result = McpErrorFactory::validate_jsonrpc_message( $invalid_message );
		// Now returns a DTO on error, not an array
		$this->assertInstanceOf( JSONRPCErrorResponse::class, $result );
		$this->assertNotNull( $result->getError() );
	}
}
