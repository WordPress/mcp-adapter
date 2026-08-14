<?php
/**
 * Regression tests for wire serialization at the transport boundary.
 *
 * These tests drive the descriptor-backed encoder the handlers use and guard the
 * nesting that used to produce placeholder `{}` objects: an embedded resource
 * inside a content block, and resource contents inside a read result.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Transport\Infrastructure;

use WP\MCP\Core\McpProtocolContext;
use WP\MCP\Domain\Utils\ContentBlockHelper;
use WP\MCP\Infrastructure\ErrorHandling\NullMcpErrorHandler;
use WP\MCP\Infrastructure\Protocol\WireEncoder;
use WP\MCP\Tests\TestCase;
use WP\MCP\Transport\Infrastructure\JsonRpcResponseBuilder;

final class WireSerializationRegressionTest extends TestCase {

	/**
	 * Build an encoder for the newest supported revision.
	 *
	 * @return WireEncoder
	 */
	private function encoder(): WireEncoder {
		return new WireEncoder( McpProtocolContext::default(), new NullMcpErrorHandler() );
	}

	public function test_embedded_resource_content_block_serializes_resource_fields_without_placeholder_objects(): void {
		$block = ContentBlockHelper::embedded_text_resource(
			'file:///test.txt',
			'Hello content',
			'text/plain',
			null,
			array(
				'keep' => array( 'public' => true ),
			)
		);

		$result = $this->encoder()->call_tool_result(
			array(
				'content' => array( $block ),
				'isError' => false,
			)
		);

		$response = JsonRpcResponseBuilder::create_success_response( 1, $result );
		$json     = wp_json_encode( $response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$this->assertNotFalse( $json );

		$this->assertStringNotContainsString( '"resource":{}', $json );

		/** @var array<string, mixed> $decoded */
		$decoded = json_decode( (string) $json, true );

		$this->assertArrayHasKey( 'result', $decoded );
		$this->assertArrayHasKey( 'content', $decoded['result'] );
		$this->assertIsArray( $decoded['result']['content'] );
		$this->assertArrayHasKey( 0, $decoded['result']['content'] );

		$item = $decoded['result']['content'][0];
		$this->assertIsArray( $item );
		$this->assertSame( 'resource', $item['type'] );
		$this->assertArrayHasKey( 'resource', $item );
		$this->assertIsArray( $item['resource'] );
		$this->assertSame( 'file:///test.txt', $item['resource']['uri'] );
		$this->assertSame( 'text/plain', $item['resource']['mimeType'] );
		$this->assertSame( 'Hello content', $item['resource']['text'] );
	}

	public function test_read_resource_result_serializes_contents_items_as_arrays_without_placeholder_objects(): void {
		$result = $this->encoder()->read_resource_result(
			array(
				'contents' => array(
					array(
						'uri'      => 'WordPress://local/resource-1',
						'text'     => 'content',
						'mimeType' => 'text/plain',
						'_meta'    => array( 'keep' => 'value' ),
					),
					array(
						'uri'      => 'WordPress://local/resource-2',
						'blob'     => 'YmFzZTY0', // "base64" - not important for this test.
						'mimeType' => 'application/octet-stream',
						'_meta'    => array( 'keep' => 'blob-meta' ),
					),
				),
				'_meta'    => array( 'keep' => 'top-meta' ),
			)
		);

		$response = JsonRpcResponseBuilder::create_success_response( 1, $result );
		$json     = wp_json_encode( $response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$this->assertNotFalse( $json );

		$this->assertStringNotContainsString( '"contents":[{}', $json );

		/** @var array<string, mixed> $decoded */
		$decoded = json_decode( (string) $json, true );

		$this->assertArrayHasKey( 'result', $decoded );
		$this->assertArrayHasKey( 'contents', $decoded['result'] );
		$this->assertIsArray( $decoded['result']['contents'] );
		$this->assertCount( 2, $decoded['result']['contents'] );

		$this->assertSame( 'WordPress://local/resource-1', $decoded['result']['contents'][0]['uri'] );
		$this->assertSame( 'content', $decoded['result']['contents'][0]['text'] );
		$this->assertSame( 'text/plain', $decoded['result']['contents'][0]['mimeType'] );
		$this->assertSame( 'value', $decoded['result']['contents'][0]['_meta']['keep'] );

		$this->assertSame( 'WordPress://local/resource-2', $decoded['result']['contents'][1]['uri'] );
		$this->assertSame( 'YmFzZTY0', $decoded['result']['contents'][1]['blob'] );
		$this->assertSame( 'application/octet-stream', $decoded['result']['contents'][1]['mimeType'] );
		$this->assertSame( 'blob-meta', $decoded['result']['contents'][1]['_meta']['keep'] );
	}
}
