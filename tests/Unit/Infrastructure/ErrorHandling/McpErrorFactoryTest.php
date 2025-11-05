<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Infrastructure\ErrorHandling;

use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;
use WP\MCP\Tests\TestCase;

final class McpErrorFactoryTest extends TestCase {

	public function test_create_error_response_creates_valid_structure(): void {
		$response = McpErrorFactory::create_error_response( 1, -32603, 'Test error' );

		$this->assertArrayHasKey( 'jsonrpc', $response );
		$this->assertSame( '2.0', $response['jsonrpc'] );
		$this->assertArrayHasKey( 'id', $response );
		$this->assertSame( 1, $response['id'] );
		$this->assertArrayHasKey( 'error', $response );
		$this->assertArrayHasKey( 'code', $response['error'] );
		$this->assertArrayHasKey( 'message', $response['error'] );
		$this->assertSame( -32603, $response['error']['code'] );
		$this->assertSame( 'Test error', $response['error']['message'] );
	}

	public function test_create_error_response_includes_data_when_provided(): void {
		$data     = array( 'key' => 'value' );
		$response = McpErrorFactory::create_error_response( 1, -32603, 'Test error', $data );

		$this->assertArrayHasKey( 'data', $response['error'] );
		$this->assertSame( $data, $response['error']['data'] );
	}

	public function test_create_error_response_excludes_data_when_null(): void {
		$response = McpErrorFactory::create_error_response( 1, -32603, 'Test error', null );

		$this->assertArrayNotHasKey( 'data', $response['error'] );
	}

	public function test_parse_error_creates_correct_error(): void {
		$response = McpErrorFactory::parse_error( 1, 'Invalid JSON' );

		$this->assertSame( McpErrorFactory::PARSE_ERROR, $response['error']['code'] );
		$this->assertStringContainsString( 'Parse error', $response['error']['message'] );
		$this->assertStringContainsString( 'Invalid JSON', $response['error']['message'] );
	}

	public function test_parse_error_without_details(): void {
		$response = McpErrorFactory::parse_error( 1 );

		$this->assertSame( McpErrorFactory::PARSE_ERROR, $response['error']['code'] );
		$this->assertStringContainsString( 'Parse error', $response['error']['message'] );
	}

	public function test_invalid_request_creates_correct_error(): void {
		$response = McpErrorFactory::invalid_request( 1, 'Missing method' );

		$this->assertSame( McpErrorFactory::INVALID_REQUEST, $response['error']['code'] );
		$this->assertStringContainsString( 'Invalid Request', $response['error']['message'] );
		$this->assertStringContainsString( 'Missing method', $response['error']['message'] );
	}

	public function test_method_not_found_creates_correct_error(): void {
		$response = McpErrorFactory::method_not_found( 1, 'test/method' );

		$this->assertSame( McpErrorFactory::METHOD_NOT_FOUND, $response['error']['code'] );
		$this->assertStringContainsString( 'test/method', $response['error']['message'] );
	}

	public function test_invalid_params_creates_correct_error(): void {
		$response = McpErrorFactory::invalid_params( 1, 'Parameter validation failed' );

		$this->assertSame( McpErrorFactory::INVALID_PARAMS, $response['error']['code'] );
		$this->assertStringContainsString( 'Invalid params', $response['error']['message'] );
		$this->assertStringContainsString( 'Parameter validation failed', $response['error']['message'] );
	}

	public function test_internal_error_creates_correct_error(): void {
		$response = McpErrorFactory::internal_error( 1, 'Database connection failed' );

		$this->assertSame( McpErrorFactory::INTERNAL_ERROR, $response['error']['code'] );
		$this->assertStringContainsString( 'Internal error', $response['error']['message'] );
		$this->assertStringContainsString( 'Database connection failed', $response['error']['message'] );
	}

	public function test_mcp_disabled_creates_correct_error(): void {
		$response = McpErrorFactory::mcp_disabled( 1 );

		$this->assertSame( McpErrorFactory::SERVER_ERROR, $response['error']['code'] );
		$this->assertStringContainsString( 'MCP functionality is currently disabled', $response['error']['message'] );
	}

	public function test_validation_error_creates_correct_error(): void {
		$response = McpErrorFactory::validation_error( 1, 'Tool name is required' );

		$this->assertSame( McpErrorFactory::INVALID_PARAMS, $response['error']['code'] );
		$this->assertStringContainsString( 'Validation error', $response['error']['message'] );
		$this->assertStringContainsString( 'Tool name is required', $response['error']['message'] );
	}

	public function test_missing_parameter_creates_correct_error(): void {
		$response = McpErrorFactory::missing_parameter( 1, 'tool_name' );

		$this->assertSame( McpErrorFactory::INVALID_PARAMS, $response['error']['code'] );
		$this->assertStringContainsString( 'Missing required parameter', $response['error']['message'] );
		$this->assertStringContainsString( 'tool_name', $response['error']['message'] );
	}

	public function test_resource_not_found_creates_correct_error(): void {
		$response = McpErrorFactory::resource_not_found( 1, 'mcp://resource/test' );

		$this->assertSame( McpErrorFactory::RESOURCE_NOT_FOUND, $response['error']['code'] );
		$this->assertStringContainsString( 'Resource not found', $response['error']['message'] );
		$this->assertStringContainsString( 'mcp://resource/test', $response['error']['message'] );
	}

	public function test_tool_not_found_creates_correct_error(): void {
		$response = McpErrorFactory::tool_not_found( 1, 'test-tool' );

		$this->assertSame( McpErrorFactory::TOOL_NOT_FOUND, $response['error']['code'] );
		$this->assertStringContainsString( 'Tool not found', $response['error']['message'] );
		$this->assertStringContainsString( 'test-tool', $response['error']['message'] );
	}

	public function test_ability_not_found_creates_correct_error(): void {
		$response = McpErrorFactory::ability_not_found( 1, 'test-ability' );

		$this->assertSame( McpErrorFactory::TOOL_NOT_FOUND, $response['error']['code'] );
		$this->assertStringContainsString( 'Ability not found', $response['error']['message'] );
		$this->assertStringContainsString( 'test-ability', $response['error']['message'] );
	}

	public function test_prompt_not_found_creates_correct_error(): void {
		$response = McpErrorFactory::prompt_not_found( 1, 'test-prompt' );

		$this->assertSame( McpErrorFactory::PROMPT_NOT_FOUND, $response['error']['code'] );
		$this->assertStringContainsString( 'Prompt not found', $response['error']['message'] );
		$this->assertStringContainsString( 'test-prompt', $response['error']['message'] );
	}

	public function test_permission_denied_creates_correct_error(): void {
		$response = McpErrorFactory::permission_denied( 1, 'User lacks required capability' );

		$this->assertSame( McpErrorFactory::PERMISSION_DENIED, $response['error']['code'] );
		$this->assertStringContainsString( 'Permission denied', $response['error']['message'] );
		$this->assertStringContainsString( 'User lacks required capability', $response['error']['message'] );
	}

	public function test_permission_denied_without_details(): void {
		$response = McpErrorFactory::permission_denied( 1 );

		$this->assertSame( McpErrorFactory::PERMISSION_DENIED, $response['error']['code'] );
		$this->assertStringContainsString( 'Permission denied', $response['error']['message'] );
	}

	public function test_unauthorized_creates_correct_error(): void {
		$response = McpErrorFactory::unauthorized( 1, 'Authentication required' );

		$this->assertSame( McpErrorFactory::UNAUTHORIZED, $response['error']['code'] );
		$this->assertStringContainsString( 'Unauthorized', $response['error']['message'] );
		$this->assertStringContainsString( 'Authentication required', $response['error']['message'] );
	}

	public function test_unauthorized_without_details(): void {
		$response = McpErrorFactory::unauthorized( 1 );

		$this->assertSame( McpErrorFactory::UNAUTHORIZED, $response['error']['code'] );
		$this->assertStringContainsString( 'Unauthorized', $response['error']['message'] );
	}

	public function test_mcp_error_to_http_status_parse_error(): void {
		$this->assertSame( 400, McpErrorFactory::mcp_error_to_http_status( McpErrorFactory::PARSE_ERROR ) );
	}

	public function test_mcp_error_to_http_status_invalid_request(): void {
		$this->assertSame( 400, McpErrorFactory::mcp_error_to_http_status( McpErrorFactory::INVALID_REQUEST ) );
	}

	public function test_mcp_error_to_http_status_unauthorized(): void {
		$this->assertSame( 401, McpErrorFactory::mcp_error_to_http_status( McpErrorFactory::UNAUTHORIZED ) );
	}

	public function test_mcp_error_to_http_status_permission_denied(): void {
		$this->assertSame( 403, McpErrorFactory::mcp_error_to_http_status( McpErrorFactory::PERMISSION_DENIED ) );
	}

	public function test_mcp_error_to_http_status_resource_not_found(): void {
		$this->assertSame( 404, McpErrorFactory::mcp_error_to_http_status( McpErrorFactory::RESOURCE_NOT_FOUND ) );
	}

	public function test_mcp_error_to_http_status_tool_not_found(): void {
		$this->assertSame( 404, McpErrorFactory::mcp_error_to_http_status( McpErrorFactory::TOOL_NOT_FOUND ) );
	}

	public function test_mcp_error_to_http_status_prompt_not_found(): void {
		$this->assertSame( 404, McpErrorFactory::mcp_error_to_http_status( McpErrorFactory::PROMPT_NOT_FOUND ) );
	}

	public function test_mcp_error_to_http_status_method_not_found(): void {
		$this->assertSame( 404, McpErrorFactory::mcp_error_to_http_status( McpErrorFactory::METHOD_NOT_FOUND ) );
	}

	public function test_mcp_error_to_http_status_internal_error(): void {
		$this->assertSame( 500, McpErrorFactory::mcp_error_to_http_status( McpErrorFactory::INTERNAL_ERROR ) );
	}

	public function test_mcp_error_to_http_status_server_error(): void {
		$this->assertSame( 500, McpErrorFactory::mcp_error_to_http_status( McpErrorFactory::SERVER_ERROR ) );
	}

	public function test_mcp_error_to_http_status_timeout_error(): void {
		$this->assertSame( 504, McpErrorFactory::mcp_error_to_http_status( McpErrorFactory::TIMEOUT_ERROR ) );
	}

	public function test_mcp_error_to_http_status_invalid_params_returns_200(): void {
		$this->assertSame( 200, McpErrorFactory::mcp_error_to_http_status( McpErrorFactory::INVALID_PARAMS ) );
	}

	public function test_mcp_error_to_http_status_unknown_code_returns_200(): void {
		$this->assertSame( 200, McpErrorFactory::mcp_error_to_http_status( -99999 ) );
	}

	public function test_mcp_error_to_http_status_string_code(): void {
		// Test with string code (should default to 200)
		$this->assertSame( 200, McpErrorFactory::mcp_error_to_http_status( 'invalid' ) );
	}

	public function test_get_http_status_for_error_with_valid_error_response(): void {
		$error_response = McpErrorFactory::parse_error( 1 );
		$status         = McpErrorFactory::get_http_status_for_error( $error_response );

		$this->assertSame( 400, $status );
	}

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

	public function test_get_http_status_for_error_with_missing_error_key_returns_500(): void {
		$error_response = array(
			'jsonrpc' => '2.0',
			'id'      => 1,
			// Missing 'error' key
		);

		$status = McpErrorFactory::get_http_status_for_error( $error_response );
		$this->assertSame( 500, $status );
	}

	public function test_validate_jsonrpc_message_valid_request(): void {
		$message = array(
			'jsonrpc' => '2.0',
			'method'  => 'test/method',
			'id'      => 1,
		);

		$result = McpErrorFactory::validate_jsonrpc_message( $message );
		$this->assertTrue( $result );
	}

	public function test_validate_jsonrpc_message_valid_notification(): void {
		$message = array(
			'jsonrpc' => '2.0',
			'method'  => 'test/method',
			// No 'id' for notifications
		);

		$result = McpErrorFactory::validate_jsonrpc_message( $message );
		$this->assertTrue( $result );
	}

	public function test_validate_jsonrpc_message_valid_response(): void {
		$message = array(
			'jsonrpc' => '2.0',
			'id'      => 1,
			'result'  => array( 'success' => true ),
		);

		$result = McpErrorFactory::validate_jsonrpc_message( $message );
		$this->assertTrue( $result );
	}

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

	public function test_validate_jsonrpc_message_not_array(): void {
		$result = McpErrorFactory::validate_jsonrpc_message( 'not an array' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertSame( McpErrorFactory::INVALID_REQUEST, $result['error']['code'] );
		$this->assertStringContainsString( 'JSON object', $result['error']['message'] );
	}

	public function test_validate_jsonrpc_message_missing_jsonrpc_version(): void {
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

	public function test_validate_jsonrpc_message_wrong_jsonrpc_version(): void {
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

	public function test_validate_jsonrpc_message_missing_method_and_result_error(): void {
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

	public function test_validate_jsonrpc_message_response_missing_id(): void {
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
}

