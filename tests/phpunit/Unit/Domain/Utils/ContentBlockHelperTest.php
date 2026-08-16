<?php
/**
 * Tests for ContentBlockHelper factory class.
 *
 * @package WP\MCP\Tests\Unit\Domain\Utils
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Domain\Utils;

use WP\MCP\Domain\Utils\ContentBlockHelper;
use WP\MCP\Tests\TestCase;

/**
 * Test class for ContentBlockHelper.
 */
final class ContentBlockHelperTest extends TestCase {

	/**
	 * Test that text() creates a text content block.
	 */
	public function test_text_creates_text_content_block(): void {
		$content = ContentBlockHelper::text( 'Hello, World!' );

		$this->assertIsArray( $content );
		$this->assertSame( 'text', $content['type'] );
		$this->assertSame( 'Hello, World!', $content['text'] );
		$this->assertArrayNotHasKey( 'annotations', $content );
		$this->assertArrayNotHasKey( '_meta', $content );
	}

	/**
	 * Test that text() creates a text content block with empty string.
	 */
	public function test_text_accepts_empty_string(): void {
		$content = ContentBlockHelper::text( '' );

		$this->assertIsArray( $content );
		$this->assertSame( '', $content['text'] );
	}

	/**
	 * Test that text() creates a text content block with annotations.
	 */
	public function test_text_with_annotations(): void {
		$annotations = array(
			'audience' => array( 'user' ),
			'priority' => 0.8,
		);
		$content     = ContentBlockHelper::text( 'Test message', $annotations );

		$this->assertIsArray( $content );
		$this->assertSame( 'Test message', $content['text'] );
		$this->assertSame( $annotations, $content['annotations'] );
	}

	/**
	 * Test that text() creates a text content block with _meta.
	 */
	public function test_text_with_meta(): void {
		$meta    = array( 'key' => 'value' );
		$content = ContentBlockHelper::text( 'Test message', null, $meta );

		$this->assertIsArray( $content );
		$this->assertSame( $meta, $content['_meta'] );
	}

	/**
	 * Test that text() produces a valid structure.
	 */
	public function test_text_produces_valid_structure(): void {
		$content = ContentBlockHelper::text( 'Hello' );

		$this->assertArrayHasKey( 'type', $content );
		$this->assertArrayHasKey( 'text', $content );
		$this->assertSame( 'text', $content['type'] );
		$this->assertSame( 'Hello', $content['text'] );
	}

	/**
	 * Test that image() creates an image content block.
	 */
	public function test_image_creates_image_content_block(): void {
		$content = ContentBlockHelper::image( 'base64data', 'image/png' );

		$this->assertIsArray( $content );
		$this->assertSame( 'image', $content['type'] );
		$this->assertSame( 'base64data', $content['data'] );
		$this->assertSame( 'image/png', $content['mimeType'] );
		$this->assertArrayNotHasKey( 'annotations', $content );
		$this->assertArrayNotHasKey( '_meta', $content );
	}

	/**
	 * Test that image() creates an image content block with annotations.
	 */
	public function test_image_with_annotations(): void {
		$annotations = array(
			'audience' => array( 'user' ),
			'priority' => 1.0,
		);
		$content     = ContentBlockHelper::image( 'data', 'image/jpeg', $annotations );

		$this->assertIsArray( $content );
		$this->assertSame( $annotations, $content['annotations'] );
	}

	/**
	 * Test that image() produces a valid structure.
	 */
	public function test_image_produces_valid_structure(): void {
		$content = ContentBlockHelper::image( 'base64data', 'image/png' );

		$this->assertArrayHasKey( 'type', $content );
		$this->assertArrayHasKey( 'data', $content );
		$this->assertArrayHasKey( 'mimeType', $content );
		$this->assertSame( 'image', $content['type'] );
		$this->assertSame( 'base64data', $content['data'] );
		$this->assertSame( 'image/png', $content['mimeType'] );
	}

	/**
	 * Test that audio() creates an audio content block.
	 */
	public function test_audio_creates_audio_content_block(): void {
		$content = ContentBlockHelper::audio( 'base64audiodata', 'audio/mp3' );

		$this->assertIsArray( $content );
		$this->assertSame( 'audio', $content['type'] );
		$this->assertSame( 'base64audiodata', $content['data'] );
		$this->assertSame( 'audio/mp3', $content['mimeType'] );
		$this->assertArrayNotHasKey( 'annotations', $content );
		$this->assertArrayNotHasKey( '_meta', $content );
	}

	/**
	 * Test that audio() creates an audio content block with annotations.
	 */
	public function test_audio_with_annotations(): void {
		$annotations = array(
			'audience' => array( 'assistant' ),
			'priority' => 0.5,
		);
		$content     = ContentBlockHelper::audio( 'data', 'audio/wav', $annotations );

		$this->assertIsArray( $content );
		$this->assertSame( $annotations, $content['annotations'] );
	}

	/**
	 * Test that audio() produces a valid structure.
	 */
	public function test_audio_produces_valid_structure(): void {
		$content = ContentBlockHelper::audio( 'audiodata', 'audio/ogg' );

		$this->assertArrayHasKey( 'type', $content );
		$this->assertArrayHasKey( 'data', $content );
		$this->assertArrayHasKey( 'mimeType', $content );
		$this->assertSame( 'audio', $content['type'] );
		$this->assertSame( 'audiodata', $content['data'] );
		$this->assertSame( 'audio/ogg', $content['mimeType'] );
	}

	/**
	 * Test that embedded_text_resource() creates an embedded resource with text contents.
	 */
	public function test_embedded_text_resource_creates_embedded_resource_block(): void {
		$content = ContentBlockHelper::embedded_text_resource( 'file:///test.txt', 'Hello content' );

		$this->assertIsArray( $content );
		$this->assertSame( 'resource', $content['type'] );

		$resource = $content['resource'];
		$this->assertIsArray( $resource );
		$this->assertSame( 'file:///test.txt', $resource['uri'] );
		$this->assertSame( 'Hello content', $resource['text'] );
	}

	/**
	 * Test that embedded_text_resource() accepts optional mimeType.
	 */
	public function test_embedded_text_resource_with_mime_type(): void {
		$content  = ContentBlockHelper::embedded_text_resource( 'file:///test.json', '{}', 'application/json' );
		$resource = $content['resource'];

		$this->assertSame( 'application/json', $resource['mimeType'] );
	}

	/**
	 * Test that embedded_text_resource() with annotations.
	 */
	public function test_embedded_text_resource_with_annotations(): void {
		$annotations = array( 'audience' => array( 'user' ) );
		$content     = ContentBlockHelper::embedded_text_resource( 'file:///test.txt', 'content', null, $annotations );

		$this->assertSame( $annotations, $content['annotations'] );
	}

	/**
	 * Test that embedded_blob_resource() creates an embedded resource with blob contents.
	 */
	public function test_embedded_blob_resource_creates_embedded_resource_block(): void {
		$content = ContentBlockHelper::embedded_blob_resource( 'file:///image.png', 'base64blob', 'image/png' );

		$this->assertIsArray( $content );
		$this->assertSame( 'resource', $content['type'] );

		$resource = $content['resource'];
		$this->assertIsArray( $resource );
		$this->assertSame( 'file:///image.png', $resource['uri'] );
		$this->assertSame( 'base64blob', $resource['blob'] );
		$this->assertSame( 'image/png', $resource['mimeType'] );
	}

	/**
	 * Test that embedded_blob_resource() with annotations.
	 */
	public function test_embedded_blob_resource_with_annotations(): void {
		$annotations = array(
			'audience' => array( 'assistant' ),
			'priority' => 0.9,
		);
		$content     = ContentBlockHelper::embedded_blob_resource( 'file:///doc.pdf', 'data', 'application/pdf', $annotations );

		$this->assertSame( $annotations, $content['annotations'] );
	}

	/**
	 * Test that embedded_text_resource() puts each _meta on its own level of the block.
	 */
	public function test_embedded_text_resource_sets_block_and_resource_meta_independently(): void {
		$content = ContentBlockHelper::embedded_text_resource(
			'ui://example/app',
			'<!doctype html>',
			'text/html;profile=mcp-app',
			null,
			array( 'block' => 'level' ),
			array( 'ui' => array( 'prefersBorder' => true ) )
		);

		$this->assertSame( array( 'block' => 'level' ), $content['_meta'] );

		$resource = $content['resource'];
		$this->assertSame( array( 'ui' => array( 'prefersBorder' => true ) ), $resource['_meta'] );
	}

	/**
	 * Test that embedded_blob_resource() puts each _meta on its own level of the block.
	 */
	public function test_embedded_blob_resource_sets_block_and_resource_meta_independently(): void {
		$content = ContentBlockHelper::embedded_blob_resource(
			'file:///doc.pdf',
			'data',
			'application/pdf',
			null,
			array( 'block' => 'level' ),
			array( 'pages' => 3 )
		);

		$this->assertSame( array( 'block' => 'level' ), $content['_meta'] );

		$resource = $content['resource'];
		$this->assertSame( array( 'pages' => 3 ), $resource['_meta'] );
	}

	/**
	 * Test that a list-shaped _meta is treated as absent by text().
	 *
	 * A list serializes to a JSON array, and MCP declares `_meta` a JSON object.
	 */
	public function test_text_with_list_shaped_meta_omits_meta(): void {
		$content = ContentBlockHelper::text( 'Test message', null, array( 'first', 'second' ) );

		$this->assertArrayNotHasKey( '_meta', $content );
	}

	/**
	 * Test that embedded_text_resource() drops a list-shaped _meta on both levels of the block.
	 */
	public function test_embedded_text_resource_with_list_shaped_meta_omits_meta_on_both_levels(): void {
		$content = ContentBlockHelper::embedded_text_resource(
			'ui://example/app',
			'<!doctype html>',
			'text/html;profile=mcp-app',
			null,
			array( 'block', 'level' ),
			array( 'resource', 'level' )
		);

		$this->assertArrayNotHasKey( '_meta', $content );
		$this->assertArrayNotHasKey( '_meta', $content['resource'] );
	}

	/**
	 * Test that error_text() creates a text content block for error messages.
	 */
	public function test_error_text_creates_text_content_block(): void {
		$content = ContentBlockHelper::error_text( 'Something went wrong' );

		$this->assertIsArray( $content );
		$this->assertSame( 'text', $content['type'] );
		$this->assertSame( 'Something went wrong', $content['text'] );
	}

	/**
	 * Test that json_text() creates a text content block with JSON-encoded data.
	 */
	public function test_json_text_creates_text_content_with_json(): void {
		$data    = array(
			'key'    => 'value',
			'nested' => array( 'a' => 1 ),
		);
		$content = ContentBlockHelper::json_text( $data );

		$this->assertIsArray( $content );
		$this->assertSame( 'text', $content['type'] );
		$this->assertSame( '{"key":"value","nested":{"a":1}}', $content['text'] );
	}

	/**
	 * Test that json_text() handles encoding options.
	 */
	public function test_json_text_with_pretty_print(): void {
		$data    = array( 'key' => 'value' );
		$content = ContentBlockHelper::json_text( $data, JSON_PRETTY_PRINT );

		$this->assertIsArray( $content );
		$expected = "{\n    \"key\": \"value\"\n}";
		$this->assertSame( $expected, $content['text'] );
	}

	/**
	 * Test that to_array_list() returns the content blocks in array form.
	 */
	public function test_to_array_list_returns_content_block_arrays(): void {
		$blocks = array(
			ContentBlockHelper::text( 'First' ),
			ContentBlockHelper::text( 'Second' ),
		);

		$arrays = ContentBlockHelper::to_array_list( $blocks );

		$this->assertCount( 2, $arrays );
		$this->assertSame(
			array(
				'type' => 'text',
				'text' => 'First',
			),
			$arrays[0]
		);
		$this->assertSame(
			array(
				'type' => 'text',
				'text' => 'Second',
			),
			$arrays[1]
		);
	}

	/**
	 * Test that to_array_list() handles empty array.
	 */
	public function test_to_array_list_handles_empty_array(): void {
		$arrays = ContentBlockHelper::to_array_list( array() );

		$this->assertIsArray( $arrays );
		$this->assertEmpty( $arrays );
	}

	/**
	 * Test that to_array_list() handles mixed content types.
	 */
	public function test_to_array_list_handles_mixed_content_types(): void {
		$blocks = array(
			ContentBlockHelper::text( 'Message' ),
			ContentBlockHelper::image( 'imgdata', 'image/png' ),
		);

		$arrays = ContentBlockHelper::to_array_list( $blocks );

		$this->assertCount( 2, $arrays );
		$this->assertSame( 'text', $arrays[0]['type'] );
		$this->assertSame( 'image', $arrays[1]['type'] );
	}
}
