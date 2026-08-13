<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Infrastructure\ErrorHandling;

use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;
use WP\MCP\Tests\TestCase;

final class McpErrorFactoryTest extends TestCase {

	public function test_create_error_response_returns_complete_array_envelope(): void {
		$response = McpErrorFactory::create_error_response( 1, -32603, 'Test error' );

		$this->assertSame(
			array(
				'jsonrpc' => '2.0',
				'error'   => array(
					'code'    => -32603,
					'message' => 'Test error',
				),
				'id'      => 1,
			),
			$response
		);
	}

	public function test_create_error_response_includes_non_null_data(): void {
		$data     = array( 'key' => 'value' );
		$response = McpErrorFactory::create_error_response( 1, -32603, 'Test error', $data );

		$this->assertSame( $data, $response['error']['data'] );
	}

	public function test_create_error_response_omits_null_data(): void {
		$response = McpErrorFactory::create_error_response( 1, -32603, 'Test error', null );

		$this->assertArrayNotHasKey( 'data', $response['error'] );
	}

	public function test_create_error_response_accepts_string_id(): void {
		$response = McpErrorFactory::create_error_response( 'request-123', -32603, 'Test error' );

		$this->assertSame( 'request-123', $response['id'] );
	}

	public function test_create_error_response_includes_null_id(): void {
		$response = McpErrorFactory::create_error_response( null, -32603, 'Test error' );

		$this->assertArrayHasKey( 'id', $response );
		$this->assertNull( $response['id'] );
	}

	/**
	 * @dataProvider provide_error_responses
	 *
	 * @param callable(): array $factory Factory callback.
	 * @param int               $expected_code Expected error code.
	 * @param string            $expected_message Expected message fragment.
	 */
	public function test_error_response_factories_return_stable_arrays( callable $factory, int $expected_code, string $expected_message ): void {
		$response = $factory();

		$this->assertSame( '2.0', $response['jsonrpc'] );
		$this->assertSame( 1, $response['id'] );
		$this->assertSame( $expected_code, $response['error']['code'] );
		$this->assertStringContainsString( $expected_message, $response['error']['message'] );
	}

	/**
	 * @return array<string, array{callable(): array, int, string}>
	 */
	public function provide_error_responses(): array {
		return array(
			'parse error'        => array(
				static fn(): array => McpErrorFactory::parse_error( 1, 'Invalid JSON' ),
				McpErrorFactory::PARSE_ERROR,
				'Invalid JSON',
			),
			'invalid request'    => array(
				static fn(): array => McpErrorFactory::invalid_request( 1, 'Missing method' ),
				McpErrorFactory::INVALID_REQUEST,
				'Missing method',
			),
			'method not found'   => array(
				static fn(): array => McpErrorFactory::method_not_found( 1, 'test/method' ),
				McpErrorFactory::METHOD_NOT_FOUND,
				'test/method',
			),
			'invalid params'     => array(
				static fn(): array => McpErrorFactory::invalid_params( 1, 'Parameter validation failed' ),
				McpErrorFactory::INVALID_PARAMS,
				'Parameter validation failed',
			),
			'internal error'     => array(
				static fn(): array => McpErrorFactory::internal_error( 1, 'Database connection failed' ),
				McpErrorFactory::INTERNAL_ERROR,
				'Database connection failed',
			),
			'mcp disabled'       => array(
				static fn(): array => McpErrorFactory::mcp_disabled( 1 ),
				McpErrorFactory::SERVER_ERROR,
				'MCP functionality is currently disabled',
			),
			'validation error'   => array(
				static fn(): array => McpErrorFactory::validation_error( 1, 'Tool name is required' ),
				McpErrorFactory::INVALID_PARAMS,
				'Tool name is required',
			),
			'missing parameter'  => array(
				static fn(): array => McpErrorFactory::missing_parameter( 1, 'tool_name' ),
				McpErrorFactory::INVALID_PARAMS,
				'tool_name',
			),
			'resource not found' => array(
				static fn(): array => McpErrorFactory::resource_not_found( 1, 'mcp://resource/test' ),
				McpErrorFactory::RESOURCE_NOT_FOUND,
				'mcp://resource/test',
			),
			'tool not found'     => array(
				static fn(): array => McpErrorFactory::tool_not_found( 1, 'test-tool' ),
				McpErrorFactory::TOOL_NOT_FOUND,
				'test-tool',
			),
			'ability not found'  => array(
				static fn(): array => McpErrorFactory::ability_not_found( 1, 'test-ability' ),
				McpErrorFactory::TOOL_NOT_FOUND,
				'test-ability',
			),
			'prompt not found'   => array(
				static fn(): array => McpErrorFactory::prompt_not_found( 1, 'test-prompt' ),
				McpErrorFactory::PROMPT_NOT_FOUND,
				'test-prompt',
			),
			'session not found'  => array(
				static fn(): array => McpErrorFactory::session_not_found( 1, 'Expired' ),
				McpErrorFactory::SESSION_NOT_FOUND,
				'Expired',
			),
			'permission denied'  => array(
				static fn(): array => McpErrorFactory::permission_denied( 1, 'User lacks required capability' ),
				McpErrorFactory::PERMISSION_DENIED,
				'User lacks required capability',
			),
			'unauthorized'       => array(
				static fn(): array => McpErrorFactory::unauthorized( 1, 'Authentication required' ),
				McpErrorFactory::UNAUTHORIZED,
				'Authentication required',
			),
		);
	}

	public function test_optional_detail_factories_preserve_default_messages(): void {
		$this->assertSame( 'Parse error', McpErrorFactory::parse_error( 1 )['error']['message'] );
		$this->assertSame( 'Invalid Request', McpErrorFactory::invalid_request( 1 )['error']['message'] );
		$this->assertSame( 'Invalid params', McpErrorFactory::invalid_params( 1 )['error']['message'] );
		$this->assertSame( 'Internal error', McpErrorFactory::internal_error( 1 )['error']['message'] );
		$this->assertSame( 'Session not found', McpErrorFactory::session_not_found( 1 )['error']['message'] );
		$this->assertSame( 'Permission denied', McpErrorFactory::permission_denied( 1 )['error']['message'] );
		$this->assertSame( 'Unauthorized', McpErrorFactory::unauthorized( 1 )['error']['message'] );
	}

	public function test_header_mismatch_returns_exact_modern_error(): void {
		$response = McpErrorFactory::header_mismatch( 1, 'MCP-Protocol-Version', '2026-07-28', '2025-11-25' );

		$this->assertSame( McpErrorFactory::HEADER_MISMATCH, $response['error']['code'] );
		$this->assertSame(
			"Header mismatch: MCP-Protocol-Version header value '2026-07-28' does not match body value '2025-11-25'",
			$response['error']['message']
		);
		$this->assertSame(
			array(
				'header'   => 'MCP-Protocol-Version',
				'expected' => '2026-07-28',
				'actual'   => '2025-11-25',
			),
			$response['error']['data']
		);
	}

	public function test_missing_required_client_capability_returns_exact_modern_error(): void {
		$required = array( 'elicitation' => new \stdClass() );
		$response = McpErrorFactory::missing_required_client_capability( 1, $required );

		$this->assertSame( McpErrorFactory::MISSING_REQUIRED_CLIENT_CAPABILITY, $response['error']['code'] );
		$this->assertSame( $required, $response['error']['data']['requiredCapabilities'] );
	}

	public function test_unsupported_protocol_version_returns_exact_modern_error(): void {
		$response = McpErrorFactory::unsupported_protocol_version(
			1,
			'1900-01-01',
			array( '2026-07-28', '2025-11-25' )
		);

		$this->assertSame( McpErrorFactory::UNSUPPORTED_PROTOCOL_VERSION, $response['error']['code'] );
		$this->assertSame( 'Unsupported protocol version', $response['error']['message'] );
		$this->assertSame(
			array(
				'supported' => array( '2026-07-28', '2025-11-25' ),
				'requested' => '1900-01-01',
			),
			$response['error']['data']
		);
	}

	/**
	 * @dataProvider provide_http_status_mappings
	 */
	public function test_mcp_error_to_http_status( int $error_code, int $expected_status ): void {
		$this->assertSame( $expected_status, McpErrorFactory::mcp_error_to_http_status( $error_code ) );
	}

	/**
	 * @return array<string, array{int, int}>
	 */
	public function provide_http_status_mappings(): array {
		return array(
			'parse error'         => array( McpErrorFactory::PARSE_ERROR, 400 ),
			'invalid request'     => array( McpErrorFactory::INVALID_REQUEST, 400 ),
			'header mismatch'     => array( McpErrorFactory::HEADER_MISMATCH, 400 ),
			'missing capability'  => array( McpErrorFactory::MISSING_REQUIRED_CLIENT_CAPABILITY, 400 ),
			'unsupported version' => array( McpErrorFactory::UNSUPPORTED_PROTOCOL_VERSION, 400 ),
			'unauthorized'        => array( McpErrorFactory::UNAUTHORIZED, 401 ),
			'permission denied'   => array( McpErrorFactory::PERMISSION_DENIED, 403 ),
			'resource not found'  => array( McpErrorFactory::RESOURCE_NOT_FOUND, 404 ),
			'tool not found'      => array( McpErrorFactory::TOOL_NOT_FOUND, 404 ),
			'prompt not found'    => array( McpErrorFactory::PROMPT_NOT_FOUND, 404 ),
			'session not found'   => array( McpErrorFactory::SESSION_NOT_FOUND, 404 ),
			'method not found'    => array( McpErrorFactory::METHOD_NOT_FOUND, 404 ),
			'internal error'      => array( McpErrorFactory::INTERNAL_ERROR, 500 ),
			'server error'        => array( McpErrorFactory::SERVER_ERROR, 500 ),
			'timeout'             => array( McpErrorFactory::TIMEOUT_ERROR, 504 ),
			'invalid params'      => array( McpErrorFactory::INVALID_PARAMS, 200 ),
			'unknown'             => array( -99999, 200 ),
		);
	}

	public function test_mcp_error_to_http_status_defaults_non_numeric_codes_to_200(): void {
		$this->assertSame( 200, McpErrorFactory::mcp_error_to_http_status( 'invalid' ) );
	}

	public function test_get_http_status_for_error_reads_array_envelope(): void {
		$this->assertSame( 400, McpErrorFactory::get_http_status_for_error( McpErrorFactory::parse_error( 1 ) ) );
	}

	public function test_get_http_status_for_error_rejects_invalid_envelope(): void {
		$this->assertSame( 500, McpErrorFactory::get_http_status_for_error( array( 'jsonrpc' => '2.0' ) ) );
		$this->assertSame(
			500,
			McpErrorFactory::get_http_status_for_error(
				array(
					'jsonrpc' => '2.0',
					'error'   => array( 'message' => 'Missing code' ),
				)
			)
		);
	}

	/**
	 * @dataProvider provide_valid_jsonrpc_messages
	 *
	 * @param array<string, mixed> $message JSON-RPC message.
	 */
	public function test_validate_jsonrpc_message_accepts_valid_messages( array $message ): void {
		$this->assertTrue( McpErrorFactory::validate_jsonrpc_message( $message ) );
	}

	/**
	 * @return array<string, array{array<string, mixed>}>
	 */
	public function provide_valid_jsonrpc_messages(): array {
		return array(
			'request'        => array(
				array(
					'jsonrpc' => '2.0',
					'method'  => 'test/method',
					'id'      => 1,
				),
			),
			'notification'   => array(
				array(
					'jsonrpc' => '2.0',
					'method'  => 'test/method',
				),
			),
			'result'         => array(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'result'  => array( 'success' => true ),
				),
			),
			'error response' => array(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'error'   => array(
						'code'    => -32603,
						'message' => 'Internal error',
					),
				),
			),
		);
	}

	/**
	 * @dataProvider provide_invalid_jsonrpc_messages
	 *
	 * @param mixed  $message Invalid JSON-RPC message.
	 * @param string $message_fragment Expected validation message fragment.
	 */
	public function test_validate_jsonrpc_message_returns_array_error( $message, string $message_fragment ): void {
		$result = McpErrorFactory::validate_jsonrpc_message( $message );

		$this->assertIsArray( $result );
		$this->assertSame( '2.0', $result['jsonrpc'] );
		$this->assertArrayHasKey( 'id', $result );
		$this->assertNull( $result['id'] );
		$this->assertSame( McpErrorFactory::INVALID_REQUEST, $result['error']['code'] );
		$this->assertStringContainsString( $message_fragment, $result['error']['message'] );
	}

	/**
	 * @return array<string, array{mixed, string}>
	 */
	public function provide_invalid_jsonrpc_messages(): array {
		return array(
			'non-array'       => array( 'not an array', 'JSON object' ),
			'missing version' => array(
				array(
					'method' => 'test/method',
					'id'     => 1,
				),
				'jsonrpc version',
			),
			'wrong version'   => array(
				array(
					'jsonrpc' => '1.0',
					'method'  => 'test/method',
					'id'      => 1,
				),
				'jsonrpc version',
			),
			'missing payload' => array(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
				),
				'method or result/error field',
			),
			'missing id'      => array(
				array(
					'jsonrpc' => '2.0',
					'result'  => array( 'success' => true ),
				),
				'id field',
			),
		);
	}

	public function test_create_error_returns_nested_error_object(): void {
		$this->assertSame(
			array(
				'code'    => -32603,
				'message' => 'Test error',
			),
			McpErrorFactory::create_error( -32603, 'Test error' )
		);
	}

	public function test_create_error_includes_non_null_data(): void {
		$data = array( 'key' => 'value' );

		$this->assertSame( $data, McpErrorFactory::create_error( -32603, 'Test error', $data )['data'] );
	}
}
