<?php
/**
 * Tests for lossless JSON-RPC request decoding.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Transport\Infrastructure;

use WP\MCP\Tests\TestCase;
use WP\MCP\Transport\Infrastructure\JsonRpcRequestDecoder;

final class JsonRpcRequestDecoderTest extends TestCase {

	public function test_preserves_nested_empty_object_and_list_identity(): void {
		$valid   = false;
		$decoded = JsonRpcRequestDecoder::decode(
			'{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"object":{},"list":[]}}',
			$valid
		);

		$this->assertTrue( $valid );
		$this->assertIsArray( $decoded );
		$this->assertInstanceOf( \stdClass::class, $decoded['params'] );
		$this->assertInstanceOf( \stdClass::class, $decoded['params']->object );
		$this->assertSame( array(), $decoded['params']->list );
	}

	public function test_preserves_batch_message_boundaries(): void {
		$valid   = false;
		$decoded = JsonRpcRequestDecoder::decode(
			'[{"jsonrpc":"2.0","id":1,"method":"ping"},{"jsonrpc":"2.0","id":2,"method":"ping"}]',
			$valid
		);

		$this->assertTrue( $valid );
		$this->assertIsArray( $decoded );
		$this->assertCount( 2, $decoded );
		$this->assertIsArray( $decoded[0] );
		$this->assertSame( 2, $decoded[1]['id'] );
	}

	public function test_reports_invalid_json_without_inventing_a_value(): void {
		$valid   = true;
		$decoded = JsonRpcRequestDecoder::decode( '{', $valid );

		$this->assertFalse( $valid );
		$this->assertNull( $decoded );
	}
}
