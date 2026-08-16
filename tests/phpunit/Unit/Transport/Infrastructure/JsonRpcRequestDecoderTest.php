<?php
/**
 * Tests for identity-preserving JSON-RPC request decoding.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Transport\Infrastructure;

use WP\MCP\Tests\TestCase;
use WP\MCP\Transport\Infrastructure\JsonRpcRequestDecoder;

/**
 * JsonRpcRequestDecoder test case.
 */
final class JsonRpcRequestDecoderTest extends TestCase {

	public function test_decode_preserves_objects_lists_and_numeric_key_objects(): void {
		$decoded = JsonRpcRequestDecoder::decode( '{"emptyObject":{},"emptyList":[],"numeric":{"0":"value"}}' );

		$this->assertInstanceOf( \stdClass::class, $decoded );
		$this->assertInstanceOf( \stdClass::class, $decoded->emptyObject );
		$this->assertIsArray( $decoded->emptyList );
		$this->assertInstanceOf( \stdClass::class, $decoded->numeric );
		$this->assertSame( 'value', $decoded->numeric->{'0'} );
	}

	public function test_to_associative_recursively_derives_callback_arrays(): void {
		$decoded     = JsonRpcRequestDecoder::decode( '{"params":{"arguments":{"nested":{}}}}' );
		$associative = JsonRpcRequestDecoder::to_associative( $decoded );

		$this->assertIsArray( $associative );
		$this->assertSame( array(), $associative['params']['arguments']['nested'] );
	}

	public function test_batch_detection_uses_the_identity_preserving_root(): void {
		$batch          = JsonRpcRequestDecoder::decode( '[{"jsonrpc":"2.0"}]' );
		$empty_batch    = JsonRpcRequestDecoder::decode( '[]' );
		$numeric_object = JsonRpcRequestDecoder::decode( '{"0":{"jsonrpc":"2.0"}}' );

		$this->assertTrue( JsonRpcRequestDecoder::is_batch_request( $batch ) );
		$this->assertFalse( JsonRpcRequestDecoder::is_batch_request( $empty_batch ) );
		$this->assertFalse( JsonRpcRequestDecoder::is_batch_request( $numeric_object ) );
	}

	public function test_normalize_messages_keeps_numeric_key_object_as_one_message(): void {
		$decoded  = JsonRpcRequestDecoder::decode( '{"0":{"jsonrpc":"2.0"}}' );
		$messages = JsonRpcRequestDecoder::normalize_messages( $decoded );

		$this->assertCount( 1, $messages );
		$this->assertArrayHasKey( 0, $messages[0] );
		$this->assertSame( '2.0', $messages[0][0]['jsonrpc'] );
	}

	public function test_invalid_json_throws_json_exception(): void {
		$this->expectException( \JsonException::class );

		JsonRpcRequestDecoder::decode( '{"jsonrpc":' );
	}
}
