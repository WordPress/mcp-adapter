<?php
/**
 * Regression tests for DTO serialization at the transport boundary.
 *
 * These tests ensure schema DTOs round-trip to arrays and JSON without producing placeholder `{}` objects
 * for nested DTOs.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Transport\Infrastructure;

use WP\MCP\Domain\Utils\ContentBlockHelper;
use WP\MCP\Tests\TestCase;
use WP\MCP\Transport\Infrastructure\JsonRpcResponseBuilder;
use WP\McpSchema\Common\Protocol\DTO\BlobResourceContents;
use WP\McpSchema\Common\Protocol\DTO\TextResourceContents;
use WP\McpSchema\Server\Resources\DTO\ReadResourceResult;
use WP\McpSchema\Server\Tools\DTO\CallToolResult;

final class DtoSerializationRegressionTest extends TestCase {

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

		$dto = CallToolResult::fromArray(
			array(
				'content' => array( $block ),
				'isError' => false,
			)
		);

		$result = $dto->toArray();

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
		$text = TextResourceContents::fromArray(
			array(
				'uri'      => 'WordPress://local/resource-1',
				'text'     => 'content',
				'mimeType' => 'text/plain',
				'_meta'    => array(
					'keep' => 'value',
				),
			)
		);

		$blob = BlobResourceContents::fromArray(
			array(
				'uri'      => 'WordPress://local/resource-2',
				'blob'     => 'YmFzZTY0', // "base64" - not important for this test.
				'mimeType' => 'application/octet-stream',
				'_meta'    => array(
					'keep' => 'blob-meta',
				),
			)
		);

		$dto = ReadResourceResult::fromArray(
			array(
				'contents' => array( $text, $blob ),
				'_meta'    => array(
					'keep' => 'top-meta',
				),
			)
		);

		$result = $dto->toArray();

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
