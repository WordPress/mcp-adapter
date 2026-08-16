<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Infrastructure\ErrorHandling;

use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;
use WP\MCP\Tests\TestCase;

final class McpErrorFactoryTest extends TestCase {

	/**
	 * Test that create_error_response returns a JSON-RPC error envelope array.
	 */
	public function test_create_error_response_returns_error_envelope(): void {
		$response = McpErrorFactory::create_error_response( 1, -32603, 'Test error' );

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'error', $response );
		$this->assertSame( '2.0', $response['jsonrpc'] );
		$this->assertSame( 1, $response['id'] );
		$this->assertIsArray( $response['error'] );
		$this->assertSame( -32603, $response['error']['code'] );
		$this->assertSame( 'Test error', $response['error']['message'] );
	}

	/**
	 * Test that create_error_response produces valid structure.
	 */
	public function test_create_error_response_creates_valid_structure(): void {
		$array = McpErrorFactory::create_error_response( 1, -32603, 'Test error' );

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

		$this->assertArrayHasKey( 'data', $response['error'] );
		$this->assertSame( $data, $response['error']['data'] );
	}

	/**
	 * Test that create_error_response excludes data when null.
	 */
	public function test_create_error_response_excludes_data_when_null(): void {
		$response = McpErrorFactory::create_error_response( 1, -32603, 'Test error', null );

		$this->assertArrayNotHasKey( 'data', $response['error'] );
	}

	/**
	 * Test that create_error_response accepts string IDs.
	 */
	public function test_create_error_response_accepts_string_id(): void {
		$response = McpErrorFactory::create_error_response( 'request-123', -32603, 'Test error' );

		$this->assertSame( 'request-123', $response['id'] );
	}

	/**
	 * Test that create_error_response accepts null IDs.
	 */
	public function test_create_error_response_accepts_null_id(): void {
		$response = McpErrorFactory::create_error_response( null, -32603, 'Test error' );

		$this->assertArrayNotHasKey( 'id', $response );
	}

	/**
	 * Test parse_error returns an error envelope with correct error code.
	 */
	public function test_parse_error_returns_error_envelope(): void {
		$response = McpErrorFactory::parse_error( 1, 'Invalid JSON' );

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'error', $response );
		$this->assertSame( McpErrorFactory::PARSE_ERROR, $response['error']['code'] );
		$this->assertStringContainsString( 'Parse error', $response['error']['message'] );
		$this->assertStringContainsString( 'Invalid JSON', $response['error']['message'] );
	}

	/**
	 * Test parse_error without details.
	 */
	public function test_parse_error_without_details(): void {
		$response = McpErrorFactory::parse_error( 1 );

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'error', $response );
		$this->assertSame( McpErrorFactory::PARSE_ERROR, $response['error']['code'] );
		$this->assertStringContainsString( 'Parse error', $response['error']['message'] );
	}

	/**
	 * Test invalid_request returns an error envelope with correct error code.
	 */
	public function test_invalid_request_returns_error_envelope(): void {
		$response = McpErrorFactory::invalid_request( 1, 'Missing method' );

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'error', $response );
		$this->assertSame( McpErrorFactory::INVALID_REQUEST, $response['error']['code'] );
		$this->assertStringContainsString( 'Invalid Request', $response['error']['message'] );
		$this->assertStringContainsString( 'Missing method', $response['error']['message'] );
	}

	/**
	 * Test method_not_found returns an error envelope with correct error code.
	 */
	public function test_method_not_found_returns_error_envelope(): void {
		$response = McpErrorFactory::method_not_found( 1, 'test/method' );

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'error', $response );
		$this->assertSame( McpErrorFactory::METHOD_NOT_FOUND, $response['error']['code'] );
		$this->assertStringContainsString( 'test/method', $response['error']['message'] );
	}

	/**
	 * Test invalid_params returns an error envelope with correct error code.
	 */
	public function test_invalid_params_returns_error_envelope(): void {
		$response = McpErrorFactory::invalid_params( 1, 'Parameter validation failed' );

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'error', $response );
		$this->assertSame( McpErrorFactory::INVALID_PARAMS, $response['error']['code'] );
		$this->assertStringContainsString( 'Invalid params', $response['error']['message'] );
		$this->assertStringContainsString( 'Parameter validation failed', $response['error']['message'] );
	}

	/**
	 * Test internal_error returns an error envelope with correct error code.
	 */
	public function test_internal_error_returns_error_envelope(): void {
		$response = McpErrorFactory::internal_error( 1, 'Database connection failed' );

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'error', $response );
		$this->assertSame( McpErrorFactory::INTERNAL_ERROR, $response['error']['code'] );
		$this->assertStringContainsString( 'Internal error', $response['error']['message'] );
		$this->assertStringContainsString( 'Database connection failed', $response['error']['message'] );
	}

	/**
	 * Test mcp_disabled returns an error envelope with correct error code.
	 */
	public function test_mcp_disabled_returns_error_envelope(): void {
		$response = McpErrorFactory::mcp_disabled( 1 );

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'error', $response );
		$this->assertSame( McpErrorFactory::SERVER_ERROR, $response['error']['code'] );
		$this->assertStringContainsString( 'MCP functionality is currently disabled', $response['error']['message'] );
	}

	/**
	 * Test validation_error returns an error envelope with correct error code.
	 */
	public function test_validation_error_returns_error_envelope(): void {
		$response = McpErrorFactory::validation_error( 1, 'Tool name is required' );

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'error', $response );
		$this->assertSame( McpErrorFactory::INVALID_PARAMS, $response['error']['code'] );
		$this->assertStringContainsString( 'Validation error', $response['error']['message'] );
		$this->assertStringContainsString( 'Tool name is required', $response['error']['message'] );
	}

	/**
	 * Test missing_parameter returns an error envelope with correct error code.
	 */
	public function test_missing_parameter_returns_error_envelope(): void {
		$response = McpErrorFactory::missing_parameter( 1, 'tool_name' );

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'error', $response );
		$this->assertSame( McpErrorFactory::INVALID_PARAMS, $response['error']['code'] );
		$this->assertStringContainsString( 'Missing required parameter', $response['error']['message'] );
		$this->assertStringContainsString( 'tool_name', $response['error']['message'] );
	}

	/**
	 * Test resource_not_found returns an error envelope with correct error code.
	 */
	public function test_resource_not_found_returns_error_envelope(): void {
		$response = McpErrorFactory::resource_not_found( 1, 'mcp://resource/test' );

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'error', $response );
		$this->assertSame( McpErrorFactory::RESOURCE_NOT_FOUND, $response['error']['code'] );
		$this->assertStringContainsString( 'Resource not found', $response['error']['message'] );
		$this->assertStringContainsString( 'mcp://resource/test', $response['error']['message'] );
	}

	/**
	 * Test tool_not_found returns an error envelope with correct error code.
	 */
	public function test_tool_not_found_returns_error_envelope(): void {
		$response = McpErrorFactory::tool_not_found( 1, 'test-tool' );

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'error', $response );
		$this->assertSame( McpErrorFactory::TOOL_NOT_FOUND, $response['error']['code'] );
		$this->assertStringContainsString( 'Tool not found', $response['error']['message'] );
		$this->assertStringContainsString( 'test-tool', $response['error']['message'] );
	}

	/**
	 * Test ability_not_found returns an error envelope with correct error code.
	 */
	public function test_ability_not_found_returns_error_envelope(): void {
		$response = McpErrorFactory::ability_not_found( 1, 'test-ability' );

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'error', $response );
		$this->assertSame( McpErrorFactory::TOOL_NOT_FOUND, $response['error']['code'] );
		$this->assertStringContainsString( 'Ability not found', $response['error']['message'] );
		$this->assertStringContainsString( 'test-ability', $response['error']['message'] );
	}

	/**
	 * Test prompt_not_found returns an error envelope with correct error code.
	 */
	public function test_prompt_not_found_returns_error_envelope(): void {
		$response = McpErrorFactory::prompt_not_found( 1, 'test-prompt' );

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'error', $response );
		$this->assertSame( McpErrorFactory::PROMPT_NOT_FOUND, $response['error']['code'] );
		$this->assertStringContainsString( 'Prompt not found', $response['error']['message'] );
		$this->assertStringContainsString( 'test-prompt', $response['error']['message'] );
	}

	public function test_modern_component_not_found_errors_use_invalid_params(): void {
		$this->assertSame( McpErrorFactory::INVALID_PARAMS, McpErrorFactory::tool_not_found( 1, 'tool', '2026-07-28' )['error']['code'] );
		$this->assertSame( McpErrorFactory::INVALID_PARAMS, McpErrorFactory::resource_not_found( 1, 'resource', '2026-07-28' )['error']['code'] );
		$this->assertSame( McpErrorFactory::INVALID_PARAMS, McpErrorFactory::prompt_not_found( 1, 'prompt', '2026-07-28' )['error']['code'] );
	}

	/**
	 * Test permission_denied returns an error envelope with correct error code.
	 */
	public function test_permission_denied_returns_error_envelope(): void {
		$response = McpErrorFactory::permission_denied( 1, 'User lacks required capability' );

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'error', $response );
		$this->assertSame( McpErrorFactory::PERMISSION_DENIED, $response['error']['code'] );
		$this->assertStringContainsString( 'Permission denied', $response['error']['message'] );
		$this->assertStringContainsString( 'User lacks required capability', $response['error']['message'] );
	}

	/**
	 * Test permission_denied without details.
	 */
	public function test_permission_denied_without_details(): void {
		$response = McpErrorFactory::permission_denied( 1 );

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'error', $response );
		$this->assertSame( McpErrorFactory::PERMISSION_DENIED, $response['error']['code'] );
		$this->assertStringContainsString( 'Permission denied', $response['error']['message'] );
	}

	/**
	 * Test unauthorized returns an error envelope with correct error code.
	 */
	public function test_unauthorized_returns_error_envelope(): void {
		$response = McpErrorFactory::unauthorized( 1, 'Authentication required' );

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'error', $response );
		$this->assertSame( McpErrorFactory::UNAUTHORIZED, $response['error']['code'] );
		$this->assertStringContainsString( 'Unauthorized', $response['error']['message'] );
		$this->assertStringContainsString( 'Authentication required', $response['error']['message'] );
	}

	/**
	 * Test unauthorized without details.
	 */
	public function test_unauthorized_without_details(): void {
		$response = McpErrorFactory::unauthorized( 1 );

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'error', $response );
		$this->assertSame( McpErrorFactory::UNAUTHORIZED, $response['error']['code'] );
		$this->assertStringContainsString( 'Unauthorized', $response['error']['message'] );
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

	public function test_modern_method_not_found_maps_to_http_200(): void {
		$this->assertSame( 200, McpErrorFactory::mcp_error_to_http_status( McpErrorFactory::METHOD_NOT_FOUND, '2026-07-28' ) );
	}

	public function test_modern_typed_errors_map_to_http_400(): void {
		$this->assertSame( 400, McpErrorFactory::mcp_error_to_http_status( McpErrorFactory::HEADER_MISMATCH, '2026-07-28' ) );
		$this->assertSame( 400, McpErrorFactory::mcp_error_to_http_status( McpErrorFactory::MISSING_REQUIRED_CLIENT_CAPABILITY, '2026-07-28' ) );
		$this->assertSame( 400, McpErrorFactory::mcp_error_to_http_status( McpErrorFactory::UNSUPPORTED_PROTOCOL_VERSION, '2026-07-28' ) );
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
	 * Test get_http_status_for_error with a factory-built envelope.
	 */
	public function test_get_http_status_for_error_with_factory_envelope(): void {
		$error_response = McpErrorFactory::parse_error( 1 );
		$status         = McpErrorFactory::get_http_status_for_error( $error_response );

		$this->assertSame( 400, $status );
	}

	/**
	 * Test get_http_status_for_error with a hand-built array.
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
	 * Test validate_jsonrpc_message returns an error envelope for non-array.
	 */
	public function test_validate_jsonrpc_message_not_array_returns_error_envelope(): void {
		$result = McpErrorFactory::validate_jsonrpc_message( 'not an array' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertSame( McpErrorFactory::INVALID_REQUEST, $result['error']['code'] );
		$this->assertStringContainsString( 'JSON object', $result['error']['message'] );
	}

	/**
	 * Test validate_jsonrpc_message returns an error envelope for missing jsonrpc version.
	 */
	public function test_validate_jsonrpc_message_missing_jsonrpc_version_returns_error_envelope(): void {
		$message = array(
			'method' => 'test/method',
			'id'     => 1,
		);

		$result = McpErrorFactory::validate_jsonrpc_message( $message );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertSame( McpErrorFactory::INVALID_REQUEST, $result['error']['code'] );
		$this->assertStringContainsString( 'jsonrpc version', $result['error']['message'] );
	}

	/**
	 * Test validate_jsonrpc_message returns an error envelope for wrong jsonrpc version.
	 */
	public function test_validate_jsonrpc_message_wrong_jsonrpc_version_returns_error_envelope(): void {
		$message = array(
			'jsonrpc' => '1.0',
			'method'  => 'test/method',
			'id'      => 1,
		);

		$result = McpErrorFactory::validate_jsonrpc_message( $message );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertSame( McpErrorFactory::INVALID_REQUEST, $result['error']['code'] );
	}

	/**
	 * Test validate_jsonrpc_message returns an error envelope for missing method and result/error.
	 */
	public function test_validate_jsonrpc_message_missing_method_and_result_error_returns_error_envelope(): void {
		$message = array(
			'jsonrpc' => '2.0',
			'id'      => 1,
			// No method, result, or error
		);

		$result = McpErrorFactory::validate_jsonrpc_message( $message );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertSame( McpErrorFactory::INVALID_REQUEST, $result['error']['code'] );
		$this->assertStringContainsString( 'method or result/error field', $result['error']['message'] );
	}

	/**
	 * Test validate_jsonrpc_message returns an error envelope for response missing id.
	 */
	public function test_validate_jsonrpc_message_response_missing_id_returns_error_envelope(): void {
		$message = array(
			'jsonrpc' => '2.0',
			'result'  => array( 'success' => true ),
			// Missing 'id' for response
		);

		$result = McpErrorFactory::validate_jsonrpc_message( $message );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertSame( McpErrorFactory::INVALID_REQUEST, $result['error']['code'] );
		$this->assertStringContainsString( 'id field', $result['error']['message'] );
	}

	/**
	 * Test validate_jsonrpc_message returns null ID for non-array input.
	 *
	 * JSON-RPC 2.0 spec: When request ID cannot be determined, use null.
	 * A null ID is omitted from the envelope in wire shape.
	 */
	public function test_validate_jsonrpc_message_not_array_returns_null_id(): void {
		$result = McpErrorFactory::validate_jsonrpc_message( 'not an array' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertArrayNotHasKey( 'id', $result );
	}

	/**
	 * Test validate_jsonrpc_message returns null ID for missing jsonrpc version.
	 *
	 * JSON-RPC 2.0 spec: When request ID cannot be determined, use null.
	 * A null ID is omitted from the envelope in wire shape.
	 */
	public function test_validate_jsonrpc_message_missing_version_returns_null_id(): void {
		$message = array(
			'method' => 'test/method',
			'id'     => 1,
		);

		$result = McpErrorFactory::validate_jsonrpc_message( $message );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertArrayNotHasKey( 'id', $result );
	}

	/**
	 * Test validate_jsonrpc_message returns null ID for wrong jsonrpc version.
	 *
	 * JSON-RPC 2.0 spec: When request ID cannot be determined, use null.
	 * A null ID is omitted from the envelope in wire shape.
	 */
	public function test_validate_jsonrpc_message_wrong_version_returns_null_id(): void {
		$message = array(
			'jsonrpc' => '1.0',
			'method'  => 'test/method',
			'id'      => 1,
		);

		$result = McpErrorFactory::validate_jsonrpc_message( $message );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertArrayNotHasKey( 'id', $result );
	}

	/**
	 * Test validate_jsonrpc_message returns null ID for missing method/result/error.
	 *
	 * JSON-RPC 2.0 spec: When request ID cannot be determined, use null.
	 * A null ID is omitted from the envelope in wire shape.
	 */
	public function test_validate_jsonrpc_message_missing_method_returns_null_id(): void {
		$message = array(
			'jsonrpc' => '2.0',
			'id'      => 1,
		);

		$result = McpErrorFactory::validate_jsonrpc_message( $message );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertArrayNotHasKey( 'id', $result );
	}

	/**
	 * Test validate_jsonrpc_message returns null ID for response missing id.
	 *
	 * JSON-RPC 2.0 spec: When request ID cannot be determined, use null.
	 * A null ID is omitted from the envelope in wire shape.
	 */
	public function test_validate_jsonrpc_message_response_missing_id_returns_null_id(): void {
		$message = array(
			'jsonrpc' => '2.0',
			'result'  => array( 'success' => true ),
		);

		$result = McpErrorFactory::validate_jsonrpc_message( $message );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertArrayNotHasKey( 'id', $result );
	}

	/**
	 * Test create_error helper method returns an error array.
	 */
	public function test_create_error_returns_error_array(): void {
		$error = McpErrorFactory::create_error( -32603, 'Test error' );

		$this->assertIsArray( $error );
		$this->assertSame( -32603, $error['code'] );
		$this->assertSame( 'Test error', $error['message'] );
		$this->assertArrayNotHasKey( 'data', $error );
	}

	/**
	 * Test create_error with data.
	 */
	public function test_create_error_with_data(): void {
		$data  = array( 'key' => 'value' );
		$error = McpErrorFactory::create_error( -32603, 'Test error', $data );

		$this->assertIsArray( $error );
		$this->assertSame( $data, $error['data'] );
	}
}
