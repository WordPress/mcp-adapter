<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Infrastructure\ErrorHandling;

use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;
use WP\MCP\Tests\TestCase;
use WP\McpSchema\Common\JsonRpc\DTO\Error;
use WP\McpSchema\Common\JsonRpc\DTO\JSONRPCErrorResponse;

final class McpErrorFactoryTest extends TestCase {

	/**
	 * Test that create_error_response returns a JSONRPCErrorResponse DTO.
	 */
	public function test_create_error_response_returns_dto(): void {
		$response = McpErrorFactory::create_error_response( 1, -32603, 'Test error' );

		$this->assertInstanceOf( JSONRPCErrorResponse::class, $response );
		$this->assertSame( '2.0', $response->getJsonrpc() );
		$this->assertSame( 1, $response->getId() );
		$this->assertInstanceOf( Error::class, $response->getError() );
		$this->assertSame( -32603, $response->getError()->getCode() );
		$this->assertSame( 'Test error', $response->getError()->getMessage() );
	}

	/**
	 * Test that create_error_response toArray produces valid structure.
	 */
	public function test_create_error_response_to_array_creates_valid_structure(): void {
		$response = McpErrorFactory::create_error_response( 1, -32603, 'Test error' );
		$array    = $response->toArray();

		$this->assertArrayHasKey( 'jsonrpc', $array );
		$this->assertSame( '2.0', $array['jsonrpc'] );
		$this->assertArrayHasKey( 'id', $array );
		$this->assertSame( 1, $array['id'] );
		$this->assertArrayHasKey( 'error', $array );
		$this->assertArrayHasKey( 'code', $array['error'] );
		$this->assertArrayHasKey( 'message', $array['error'] );
		$this->assertSame( -32603, $array['error']['code'] );
		$this->assertSame( 'Test error', $array['error']['message'] );
	}

	/**
	 * Test that create_error_response includes data when provided.
	 */
	public function test_create_error_response_includes_data_when_provided(): void {
		$data     = array( 'key' => 'value' );
		$response = McpErrorFactory::create_error_response( 1, -32603, 'Test error', $data );

		$this->assertSame( $data, $response->getError()->getData() );

		$array = $response->toArray();
		$this->assertArrayHasKey( 'data', $array['error'] );
		$this->assertSame( $data, $array['error']['data'] );
	}

	/**
	 * Test that create_error_response excludes data when null.
	 */
	public function test_create_error_response_excludes_data_when_null(): void {
		$response = McpErrorFactory::create_error_response( 1, -32603, 'Test error', null );

		$this->assertNull( $response->getError()->getData() );

		$array = $response->toArray();
		$this->assertArrayNotHasKey( 'data', $array['error'] );
	}

	/**
	 * Test that create_error_response accepts string IDs.
	 */
	public function test_create_error_response_accepts_string_id(): void {
		$response = McpErrorFactory::create_error_response( 'request-123', -32603, 'Test error' );

		$this->assertSame( 'request-123', $response->getId() );
	}

	/**
	 * Test that create_error_response accepts null IDs.
	 */
	public function test_create_error_response_accepts_null_id(): void {
		$response = McpErrorFactory::create_error_response( null, -32603, 'Test error' );

		$this->assertNull( $response->getId() );

		$array = $response->toArray();
		$this->assertArrayNotHasKey( 'id', $array );
	}

	/**
	 * Test parse_error returns a DTO with correct error code.
	 */
	public function test_parse_error_returns_dto(): void {
		$response = McpErrorFactory::parse_error( 1, 'Invalid JSON' );

		$this->assertInstanceOf( JSONRPCErrorResponse::class, $response );
		$this->assertSame( McpErrorFactory::PARSE_ERROR, $response->getError()->getCode() );
		$this->assertStringContainsString( 'Parse error', $response->getError()->getMessage() );
		$this->assertStringContainsString( 'Invalid JSON', $response->getError()->getMessage() );
	}

	/**
	 * Test parse_error without details.
	 */
	public function test_parse_error_without_details(): void {
		$response = McpErrorFactory::parse_error( 1 );

		$this->assertInstanceOf( JSONRPCErrorResponse::class, $response );
		$this->assertSame( McpErrorFactory::PARSE_ERROR, $response->getError()->getCode() );
		$this->assertStringContainsString( 'Parse error', $response->getError()->getMessage() );
	}

	/**
	 * Test invalid_request returns a DTO with correct error code.
	 */
	public function test_invalid_request_returns_dto(): void {
		$response = McpErrorFactory::invalid_request( 1, 'Missing method' );

		$this->assertInstanceOf( JSONRPCErrorResponse::class, $response );
		$this->assertSame( McpErrorFactory::INVALID_REQUEST, $response->getError()->getCode() );
		$this->assertStringContainsString( 'Invalid Request', $response->getError()->getMessage() );
		$this->assertStringContainsString( 'Missing method', $response->getError()->getMessage() );
	}

	/**
	 * Test method_not_found returns a DTO with correct error code.
	 */
	public function test_method_not_found_returns_dto(): void {
		$response = McpErrorFactory::method_not_found( 1, 'test/method' );

		$this->assertInstanceOf( JSONRPCErrorResponse::class, $response );
		$this->assertSame( McpErrorFactory::METHOD_NOT_FOUND, $response->getError()->getCode() );
		$this->assertStringContainsString( 'test/method', $response->getError()->getMessage() );
	}

	/**
	 * Test invalid_params returns a DTO with correct error code.
	 */
	public function test_invalid_params_returns_dto(): void {
		$response = McpErrorFactory::invalid_params( 1, 'Parameter validation failed' );

		$this->assertInstanceOf( JSONRPCErrorResponse::class, $response );
		$this->assertSame( McpErrorFactory::INVALID_PARAMS, $response->getError()->getCode() );
		$this->assertStringContainsString( 'Invalid params', $response->getError()->getMessage() );
		$this->assertStringContainsString( 'Parameter validation failed', $response->getError()->getMessage() );
	}

	/**
	 * Test internal_error returns a DTO with correct error code.
	 */
	public function test_internal_error_returns_dto(): void {
		$response = McpErrorFactory::internal_error( 1, 'Database connection failed' );

		$this->assertInstanceOf( JSONRPCErrorResponse::class, $response );
		$this->assertSame( McpErrorFactory::INTERNAL_ERROR, $response->getError()->getCode() );
		$this->assertStringContainsString( 'Internal error', $response->getError()->getMessage() );
		$this->assertStringContainsString( 'Database connection failed', $response->getError()->getMessage() );
	}

	/**
	 * Test mcp_disabled returns a DTO with correct error code.
	 */
	public function test_mcp_disabled_returns_dto(): void {
		$response = McpErrorFactory::mcp_disabled( 1 );

		$this->assertInstanceOf( JSONRPCErrorResponse::class, $response );
		$this->assertSame( McpErrorFactory::SERVER_ERROR, $response->getError()->getCode() );
		$this->assertStringContainsString( 'MCP functionality is currently disabled', $response->getError()->getMessage() );
	}

	/**
	 * Test validation_error returns a DTO with correct error code.
	 */
	public function test_validation_error_returns_dto(): void {
		$response = McpErrorFactory::validation_error( 1, 'Tool name is required' );

		$this->assertInstanceOf( JSONRPCErrorResponse::class, $response );
		$this->assertSame( McpErrorFactory::INVALID_PARAMS, $response->getError()->getCode() );
		$this->assertStringContainsString( 'Validation error', $response->getError()->getMessage() );
		$this->assertStringContainsString( 'Tool name is required', $response->getError()->getMessage() );
	}

	/**
	 * Test missing_parameter returns a DTO with correct error code.
	 */
	public function test_missing_parameter_returns_dto(): void {
		$response = McpErrorFactory::missing_parameter( 1, 'tool_name' );

		$this->assertInstanceOf( JSONRPCErrorResponse::class, $response );
		$this->assertSame( McpErrorFactory::INVALID_PARAMS, $response->getError()->getCode() );
		$this->assertStringContainsString( 'Missing required parameter', $response->getError()->getMessage() );
		$this->assertStringContainsString( 'tool_name', $response->getError()->getMessage() );
	}

	/**
	 * Test resource_not_found returns a DTO with correct error code.
	 */
	public function test_resource_not_found_returns_dto(): void {
		$response = McpErrorFactory::resource_not_found( 1, 'mcp://resource/test' );

		$this->assertInstanceOf( JSONRPCErrorResponse::class, $response );
		$this->assertSame( McpErrorFactory::RESOURCE_NOT_FOUND, $response->getError()->getCode() );
		$this->assertStringContainsString( 'Resource not found', $response->getError()->getMessage() );
		$this->assertStringContainsString( 'mcp://resource/test', $response->getError()->getMessage() );
	}

	/**
	 * Test tool_not_found returns a DTO with correct error code.
	 */
	public function test_tool_not_found_returns_dto(): void {
		$response = McpErrorFactory::tool_not_found( 1, 'test-tool' );

		$this->assertInstanceOf( JSONRPCErrorResponse::class, $response );
		$this->assertSame( McpErrorFactory::TOOL_NOT_FOUND, $response->getError()->getCode() );
		$this->assertStringContainsString( 'Tool not found', $response->getError()->getMessage() );
		$this->assertStringContainsString( 'test-tool', $response->getError()->getMessage() );
	}

	/**
	 * Test ability_not_found returns a DTO with correct error code.
	 */
	public function test_ability_not_found_returns_dto(): void {
		$response = McpErrorFactory::ability_not_found( 1, 'test-ability' );

		$this->assertInstanceOf( JSONRPCErrorResponse::class, $response );
		$this->assertSame( McpErrorFactory::TOOL_NOT_FOUND, $response->getError()->getCode() );
		$this->assertStringContainsString( 'Ability not found', $response->getError()->getMessage() );
		$this->assertStringContainsString( 'test-ability', $response->getError()->getMessage() );
	}

	/**
	 * Test prompt_not_found returns a DTO with correct error code.
	 */
	public function test_prompt_not_found_returns_dto(): void {
		$response = McpErrorFactory::prompt_not_found( 1, 'test-prompt' );

		$this->assertInstanceOf( JSONRPCErrorResponse::class, $response );
		$this->assertSame( McpErrorFactory::PROMPT_NOT_FOUND, $response->getError()->getCode() );
		$this->assertStringContainsString( 'Prompt not found', $response->getError()->getMessage() );
		$this->assertStringContainsString( 'test-prompt', $response->getError()->getMessage() );
	}

	/**
	 * Test permission_denied returns a DTO with correct error code.
	 */
	public function test_permission_denied_returns_dto(): void {
		$response = McpErrorFactory::permission_denied( 1, 'User lacks required capability' );

		$this->assertInstanceOf( JSONRPCErrorResponse::class, $response );
		$this->assertSame( McpErrorFactory::PERMISSION_DENIED, $response->getError()->getCode() );
		$this->assertStringContainsString( 'Permission denied', $response->getError()->getMessage() );
		$this->assertStringContainsString( 'User lacks required capability', $response->getError()->getMessage() );
	}

	/**
	 * Test permission_denied without details.
	 */
	public function test_permission_denied_without_details(): void {
		$response = McpErrorFactory::permission_denied( 1 );

		$this->assertInstanceOf( JSONRPCErrorResponse::class, $response );
		$this->assertSame( McpErrorFactory::PERMISSION_DENIED, $response->getError()->getCode() );
		$this->assertStringContainsString( 'Permission denied', $response->getError()->getMessage() );
	}

	/**
	 * Test unauthorized returns a DTO with correct error code.
	 */
	public function test_unauthorized_returns_dto(): void {
		$response = McpErrorFactory::unauthorized( 1, 'Authentication required' );

		$this->assertInstanceOf( JSONRPCErrorResponse::class, $response );
		$this->assertSame( McpErrorFactory::UNAUTHORIZED, $response->getError()->getCode() );
		$this->assertStringContainsString( 'Unauthorized', $response->getError()->getMessage() );
		$this->assertStringContainsString( 'Authentication required', $response->getError()->getMessage() );
	}

	/**
	 * Test unauthorized without details.
	 */
	public function test_unauthorized_without_details(): void {
		$response = McpErrorFactory::unauthorized( 1 );

		$this->assertInstanceOf( JSONRPCErrorResponse::class, $response );
		$this->assertSame( McpErrorFactory::UNAUTHORIZED, $response->getError()->getCode() );
		$this->assertStringContainsString( 'Unauthorized', $response->getError()->getMessage() );
	}

	/**
	 * Test mcp_error_to_http_status with parse error.
	 */
	public function test_mcp_error_to_http_status_parse_error(): void {
		$this->assertSame( 400, McpErrorFactory::mcp_error_to_http_status( McpErrorFactory::PARSE_ERROR ) );
	}

	/**
	 * Test mcp_error_to_http_status with invalid request.
	 */
	public function test_mcp_error_to_http_status_invalid_request(): void {
		$this->assertSame( 400, McpErrorFactory::mcp_error_to_http_status( McpErrorFactory::INVALID_REQUEST ) );
	}

	/**
	 * Test mcp_error_to_http_status with unauthorized.
	 */
	public function test_mcp_error_to_http_status_unauthorized(): void {
		$this->assertSame( 401, McpErrorFactory::mcp_error_to_http_status( McpErrorFactory::UNAUTHORIZED ) );
	}

	/**
	 * Test mcp_error_to_http_status with permission denied.
	 */
	public function test_mcp_error_to_http_status_permission_denied(): void {
		$this->assertSame( 403, McpErrorFactory::mcp_error_to_http_status( McpErrorFactory::PERMISSION_DENIED ) );
	}

	/**
	 * Test mcp_error_to_http_status with resource not found.
	 */
	public function test_mcp_error_to_http_status_resource_not_found(): void {
		$this->assertSame( 404, McpErrorFactory::mcp_error_to_http_status( McpErrorFactory::RESOURCE_NOT_FOUND ) );
	}

	/**
	 * Test mcp_error_to_http_status with tool not found.
	 */
	public function test_mcp_error_to_http_status_tool_not_found(): void {
		$this->assertSame( 404, McpErrorFactory::mcp_error_to_http_status( McpErrorFactory::TOOL_NOT_FOUND ) );
	}

	/**
	 * Test mcp_error_to_http_status with prompt not found.
	 */
	public function test_mcp_error_to_http_status_prompt_not_found(): void {
		$this->assertSame( 404, McpErrorFactory::mcp_error_to_http_status( McpErrorFactory::PROMPT_NOT_FOUND ) );
	}

	/**
	 * Test mcp_error_to_http_status with method not found.
	 */
	public function test_mcp_error_to_http_status_method_not_found(): void {
		$this->assertSame( 404, McpErrorFactory::mcp_error_to_http_status( McpErrorFactory::METHOD_NOT_FOUND ) );
	}

	/**
	 * Test mcp_error_to_http_status with internal error.
	 */
	public function test_mcp_error_to_http_status_internal_error(): void {
		$this->assertSame( 500, McpErrorFactory::mcp_error_to_http_status( McpErrorFactory::INTERNAL_ERROR ) );
	}

	/**
	 * Test mcp_error_to_http_status with server error.
	 */
	public function test_mcp_error_to_http_status_server_error(): void {
		$this->assertSame( 500, McpErrorFactory::mcp_error_to_http_status( McpErrorFactory::SERVER_ERROR ) );
	}

	/**
	 * Test mcp_error_to_http_status with timeout error.
	 */
	public function test_mcp_error_to_http_status_timeout_error(): void {
		$this->assertSame( 504, McpErrorFactory::mcp_error_to_http_status( McpErrorFactory::TIMEOUT_ERROR ) );
	}

	/**
	 * Test mcp_error_to_http_status with invalid params returns 200.
	 */
	public function test_mcp_error_to_http_status_invalid_params_returns_200(): void {
		$this->assertSame( 200, McpErrorFactory::mcp_error_to_http_status( McpErrorFactory::INVALID_PARAMS ) );
	}

	/**
	 * Test mcp_error_to_http_status with unknown code returns 200.
	 */
	public function test_mcp_error_to_http_status_unknown_code_returns_200(): void {
		$this->assertSame( 200, McpErrorFactory::mcp_error_to_http_status( -99999 ) );
	}

	/**
	 * Test mcp_error_to_http_status with string code.
	 */
	public function test_mcp_error_to_http_status_string_code(): void {
		// Test with string code (should default to 200)
		$this->assertSame( 200, McpErrorFactory::mcp_error_to_http_status( 'invalid' ) );
	}

	/**
	 * Test get_http_status_for_error with a DTO.
	 */
	public function test_get_http_status_for_error_with_dto(): void {
		$error_response = McpErrorFactory::parse_error( 1 );
		$status         = McpErrorFactory::get_http_status_for_error( $error_response );

		$this->assertSame( 400, $status );
	}

	/**
	 * Test get_http_status_for_error with an array (legacy support).
	 */
	public function test_get_http_status_for_error_with_array(): void {
		$error_response = array(
			'jsonrpc' => '2.0',
			'id'      => 1,
			'error'   => array(
				'code'    => McpErrorFactory::PARSE_ERROR,
				'message' => 'Parse error',
			),
		);

		$status = McpErrorFactory::get_http_status_for_error( $error_response );
		$this->assertSame( 400, $status );
	}

	/**
	 * Test get_http_status_for_error with missing code returns 500.
	 */
	public function test_get_http_status_for_error_with_missing_code_returns_500(): void {
		$error_response = array(
			'jsonrpc' => '2.0',
			'id'      => 1,
			'error'   => array(
				'message' => 'Test error',
				// Missing 'code' key
			),
		);

		$status = McpErrorFactory::get_http_status_for_error( $error_response );
		$this->assertSame( 500, $status );
	}

	/**
	 * Test get_http_status_for_error with missing error key returns 500.
	 */
	public function test_get_http_status_for_error_with_missing_error_key_returns_500(): void {
		$error_response = array(
			'jsonrpc' => '2.0',
			'id'      => 1,
			// Missing 'error' key
		);

		$status = McpErrorFactory::get_http_status_for_error( $error_response );
		$this->assertSame( 500, $status );
	}

	/**
	 * Test validate_jsonrpc_message with valid request.
	 */
	public function test_validate_jsonrpc_message_valid_request(): void {
		$message = array(
			'jsonrpc' => '2.0',
			'method'  => 'test/method',
			'id'      => 1,
		);

		$result = McpErrorFactory::validate_jsonrpc_message( $message );
		$this->assertTrue( $result );
	}

	/**
	 * Test validate_jsonrpc_message with valid notification.
	 */
	public function test_validate_jsonrpc_message_valid_notification(): void {
		$message = array(
			'jsonrpc' => '2.0',
			'method'  => 'test/method',
			// No 'id' for notifications
		);

		$result = McpErrorFactory::validate_jsonrpc_message( $message );
		$this->assertTrue( $result );
	}

	/**
	 * Test validate_jsonrpc_message with valid response.
	 */
	public function test_validate_jsonrpc_message_valid_response(): void {
		$message = array(
			'jsonrpc' => '2.0',
			'id'      => 1,
			'result'  => array( 'success' => true ),
		);

		$result = McpErrorFactory::validate_jsonrpc_message( $message );
		$this->assertTrue( $result );
	}

	/**
	 * Test validate_jsonrpc_message with valid error response.
	 */
	public function test_validate_jsonrpc_message_valid_error_response(): void {
		$message = array(
			'jsonrpc' => '2.0',
			'id'      => 1,
			'error'   => array(
				'code'    => -32603,
				'message' => 'Internal error',
			),
		);

		$result = McpErrorFactory::validate_jsonrpc_message( $message );
		$this->assertTrue( $result );
	}

	/**
	 * Test validate_jsonrpc_message returns DTO for non-array.
	 */
	public function test_validate_jsonrpc_message_not_array_returns_dto(): void {
		$result = McpErrorFactory::validate_jsonrpc_message( 'not an array' );

		$this->assertInstanceOf( JSONRPCErrorResponse::class, $result );
		$this->assertSame( McpErrorFactory::INVALID_REQUEST, $result->getError()->getCode() );
		$this->assertStringContainsString( 'JSON object', $result->getError()->getMessage() );
	}

	/**
	 * Test validate_jsonrpc_message returns DTO for missing jsonrpc version.
	 */
	public function test_validate_jsonrpc_message_missing_jsonrpc_version_returns_dto(): void {
		$message = array(
			'method' => 'test/method',
			'id'     => 1,
		);

		$result = McpErrorFactory::validate_jsonrpc_message( $message );

		$this->assertInstanceOf( JSONRPCErrorResponse::class, $result );
		$this->assertSame( McpErrorFactory::INVALID_REQUEST, $result->getError()->getCode() );
		$this->assertStringContainsString( 'jsonrpc version', $result->getError()->getMessage() );
	}

	/**
	 * Test validate_jsonrpc_message returns DTO for wrong jsonrpc version.
	 */
	public function test_validate_jsonrpc_message_wrong_jsonrpc_version_returns_dto(): void {
		$message = array(
			'jsonrpc' => '1.0',
			'method'  => 'test/method',
			'id'      => 1,
		);

		$result = McpErrorFactory::validate_jsonrpc_message( $message );

		$this->assertInstanceOf( JSONRPCErrorResponse::class, $result );
		$this->assertSame( McpErrorFactory::INVALID_REQUEST, $result->getError()->getCode() );
	}

	/**
	 * Test validate_jsonrpc_message returns DTO for missing method and result/error.
	 */
	public function test_validate_jsonrpc_message_missing_method_and_result_error_returns_dto(): void {
		$message = array(
			'jsonrpc' => '2.0',
			'id'      => 1,
			// No method, result, or error
		);

		$result = McpErrorFactory::validate_jsonrpc_message( $message );

		$this->assertInstanceOf( JSONRPCErrorResponse::class, $result );
		$this->assertSame( McpErrorFactory::INVALID_REQUEST, $result->getError()->getCode() );
		$this->assertStringContainsString( 'method or result/error field', $result->getError()->getMessage() );
	}

	/**
	 * Test validate_jsonrpc_message returns DTO for response missing id.
	 */
	public function test_validate_jsonrpc_message_response_missing_id_returns_dto(): void {
		$message = array(
			'jsonrpc' => '2.0',
			'result'  => array( 'success' => true ),
			// Missing 'id' for response
		);

		$result = McpErrorFactory::validate_jsonrpc_message( $message );

		$this->assertInstanceOf( JSONRPCErrorResponse::class, $result );
		$this->assertSame( McpErrorFactory::INVALID_REQUEST, $result->getError()->getCode() );
		$this->assertStringContainsString( 'id field', $result->getError()->getMessage() );
	}

	/**
	 * Test validate_jsonrpc_message returns null ID for non-array input.
	 *
	 * JSON-RPC 2.0 spec: When request ID cannot be determined, use null.
	 */
	public function test_validate_jsonrpc_message_not_array_returns_null_id(): void {
		$result = McpErrorFactory::validate_jsonrpc_message( 'not an array' );

		$this->assertInstanceOf( JSONRPCErrorResponse::class, $result );
		$this->assertNull( $result->getId() );

		$array = $result->toArray();
		$this->assertArrayNotHasKey( 'id', $array );
	}

	/**
	 * Test validate_jsonrpc_message returns null ID for missing jsonrpc version.
	 *
	 * JSON-RPC 2.0 spec: When request ID cannot be determined, use null.
	 */
	public function test_validate_jsonrpc_message_missing_version_returns_null_id(): void {
		$message = array(
			'method' => 'test/method',
			'id'     => 1,
		);

		$result = McpErrorFactory::validate_jsonrpc_message( $message );

		$this->assertInstanceOf( JSONRPCErrorResponse::class, $result );
		$this->assertNull( $result->getId() );
	}

	/**
	 * Test validate_jsonrpc_message returns null ID for wrong jsonrpc version.
	 *
	 * JSON-RPC 2.0 spec: When request ID cannot be determined, use null.
	 */
	public function test_validate_jsonrpc_message_wrong_version_returns_null_id(): void {
		$message = array(
			'jsonrpc' => '1.0',
			'method'  => 'test/method',
			'id'      => 1,
		);

		$result = McpErrorFactory::validate_jsonrpc_message( $message );

		$this->assertInstanceOf( JSONRPCErrorResponse::class, $result );
		$this->assertNull( $result->getId() );
	}

	/**
	 * Test validate_jsonrpc_message returns null ID for missing method/result/error.
	 *
	 * JSON-RPC 2.0 spec: When request ID cannot be determined, use null.
	 */
	public function test_validate_jsonrpc_message_missing_method_returns_null_id(): void {
		$message = array(
			'jsonrpc' => '2.0',
			'id'      => 1,
		);

		$result = McpErrorFactory::validate_jsonrpc_message( $message );

		$this->assertInstanceOf( JSONRPCErrorResponse::class, $result );
		$this->assertNull( $result->getId() );
	}

	/**
	 * Test validate_jsonrpc_message returns null ID for response missing id.
	 *
	 * JSON-RPC 2.0 spec: When request ID cannot be determined, use null.
	 */
	public function test_validate_jsonrpc_message_response_missing_id_returns_null_id(): void {
		$message = array(
			'jsonrpc' => '2.0',
			'result'  => array( 'success' => true ),
		);

		$result = McpErrorFactory::validate_jsonrpc_message( $message );

		$this->assertInstanceOf( JSONRPCErrorResponse::class, $result );
		$this->assertNull( $result->getId() );
	}

	/**
	 * Test create_error helper method returns Error DTO.
	 */
	public function test_create_error_returns_dto(): void {
		$error = McpErrorFactory::create_error( -32603, 'Test error' );

		$this->assertInstanceOf( Error::class, $error );
		$this->assertSame( -32603, $error->getCode() );
		$this->assertSame( 'Test error', $error->getMessage() );
		$this->assertNull( $error->getData() );
	}

	/**
	 * Test create_error with data.
	 */
	public function test_create_error_with_data(): void {
		$data  = array( 'key' => 'value' );
		$error = McpErrorFactory::create_error( -32603, 'Test error', $data );

		$this->assertInstanceOf( Error::class, $error );
		$this->assertSame( $data, $error->getData() );
	}
}
