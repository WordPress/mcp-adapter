<?php
/**
 * Tests for JsonRpcResponseBuilder class.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Transport\Infrastructure;

use WP\MCP\Tests\TestCase;
use WP\MCP\Transport\Infrastructure\JsonRpcRequestDecoder;
use WP\MCP\Transport\Infrastructure\JsonRpcResponseBuilder;

/**
 * JsonRpcResponseBuilder test case.
 */
class JsonRpcResponseBuilderTest extends TestCase {

	/**
	 * Test creating a success response.
	 */
	public function test_create_success_response(): void {
		$response = JsonRpcResponseBuilder::create_success_response( 123, array( 'status' => 'ok' ) );

		$this->assertEquals( '2.0', $response['jsonrpc'] );
		$this->assertEquals( 123, $response['id'] );
		$this->assertEquals( (object) array( 'status' => 'ok' ), $response['result'] );
	}

	/**
	 * Test creating an error response.
	 */
	public function test_create_error_response(): void {
		$error = array(
			'code'    => -32600,
			'message' => 'Invalid request',
		);

		$response = JsonRpcResponseBuilder::create_error_response( 456, $error );

		$this->assertEquals( '2.0', $response['jsonrpc'] );
		$this->assertEquals( 456, $response['id'] );
		$this->assertEquals( $error, $response['error'] );
	}

	/**
	 * Test batch request detection.
	 */
	public function test_is_batch_request(): void {
		// Test batch request
		$batch_body = JsonRpcRequestDecoder::decode( '[{"method":"test1","id":1},{"method":"test2","id":2}]' );
		$this->assertTrue( JsonRpcResponseBuilder::is_batch_request( $batch_body ) );

		// Test single request
		$single_body = JsonRpcRequestDecoder::decode( '{"method":"test","id":1}' );
		$this->assertFalse( JsonRpcResponseBuilder::is_batch_request( $single_body ) );

		// Numeric-key objects are still single requests.
		$numeric_key_object = JsonRpcRequestDecoder::decode( '{"0":{"method":"test","id":1}}' );
		$this->assertFalse( JsonRpcResponseBuilder::is_batch_request( $numeric_key_object ) );

		// An empty array is invalid as a batch and produces one error object.
		$empty_batch = JsonRpcRequestDecoder::decode( '[]' );
		$this->assertFalse( JsonRpcResponseBuilder::is_batch_request( $empty_batch ) );

		// Test non-array
		$this->assertFalse( JsonRpcResponseBuilder::is_batch_request( 'not_array' ) );
	}

	/**
	 * Test message normalization.
	 */
	public function test_normalize_messages(): void {
		// Test batch request normalization
		$batch_body = JsonRpcRequestDecoder::decode( '[{"method":"test1","id":1},{"method":"test2","id":2}]' );
		$normalized = JsonRpcResponseBuilder::normalize_messages( $batch_body );
		$this->assertSame(
			array(
				array(
					'method' => 'test1',
					'id'     => 1,
				),
				array(
					'method' => 'test2',
					'id'     => 2,
				),
			),
			$normalized
		);

		// Test single request normalization
		$single_body = JsonRpcRequestDecoder::decode( '{"method":"test","id":1}' );
		$normalized  = JsonRpcResponseBuilder::normalize_messages( $single_body );
		$this->assertSame(
			array(
				array(
					'method' => 'test',
					'id'     => 1,
				),
			),
			$normalized
		);
	}

	/**
	 * Test process messages functionality.
	 */
	public function test_process_messages(): void {
		$messages = array(
			array(
				'method' => 'test1',
				'id'     => 1,
			),
			array(
				'method' => 'test2',
				'id'     => 2,
			),
		);

		// Mock processor that returns a response for each message
		$processor = static function ( array $message ) {
			return array(
				'jsonrpc' => '2.0',
				'id'      => $message['id'],
				'result'  => 'processed',
			);
		};

		// Test batch processing
		$result = JsonRpcResponseBuilder::process_messages( $messages, true, $processor );
		$this->assertIsArray( $result );
		$this->assertCount( 2, $result );
		$this->assertEquals( 1, $result[0]['id'] );
		$this->assertEquals( 2, $result[1]['id'] );

		// Test single message processing
		$result = JsonRpcResponseBuilder::process_messages( $messages, false, $processor );
		$this->assertIsArray( $result );
		$this->assertEquals( 1, $result['id'] );
		$this->assertEquals( 'processed', $result['result'] );
	}
}
