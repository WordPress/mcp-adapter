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
use WP\McpSchema\Common\Content\DTO\AudioContent;
use WP\McpSchema\Common\Content\DTO\ImageContent;
use WP\McpSchema\Common\Content\DTO\TextContent;
use WP\McpSchema\Common\Protocol\DTO\Annotations;
use WP\McpSchema\Common\Protocol\DTO\BlobResourceContents;
use WP\McpSchema\Common\Protocol\DTO\EmbeddedResource;
use WP\McpSchema\Common\Protocol\DTO\TextResourceContents;
use WP\McpSchema\Common\Protocol\Union\ContentBlockInterface;

/**
 * Test class for ContentBlockHelper.
 */
final class ContentBlockHelperTest extends TestCase {

	/**
	 * Test that text() creates a TextContent DTO.
	 */
	public function test_text_creates_text_content_dto(): void {
		$content = ContentBlockHelper::text( 'Hello, World!' );

		$this->assertInstanceOf( TextContent::class, $content );
		$this->assertInstanceOf( ContentBlockInterface::class, $content );
		$this->assertSame( 'text', $content->getType() );
		$this->assertSame( 'Hello, World!', $content->getText() );
		$this->assertNull( $content->getAnnotations() );
		$this->assertNull( $content->get_meta() );
	}

	/**
	 * Test that text() creates a TextContent with empty string.
	 */
	public function test_text_accepts_empty_string(): void {
		$content = ContentBlockHelper::text( '' );

		$this->assertInstanceOf( TextContent::class, $content );
		$this->assertSame( '', $content->getText() );
	}

	/**
	 * Test that text() creates a TextContent with annotations.
	 */
	public function test_text_with_annotations(): void {
		$annotations = new Annotations( array( 'user' ), 0.8 );
		$content     = ContentBlockHelper::text( 'Test message', $annotations );

		$this->assertInstanceOf( TextContent::class, $content );
		$this->assertSame( 'Test message', $content->getText() );
		$this->assertSame( $annotations, $content->getAnnotations() );
	}

	/**
	 * Test that text() creates a TextContent with _meta.
	 */
	public function test_text_with_meta(): void {
		$meta    = array( 'key' => 'value' );
		$content = ContentBlockHelper::text( 'Test message', null, $meta );

		$this->assertInstanceOf( TextContent::class, $content );
		$this->assertSame( $meta, $content->get_meta() );
	}

	/**
	 * Test that text() toArray produces valid structure.
	 */
	public function test_text_to_array_produces_valid_structure(): void {
		$content = ContentBlockHelper::text( 'Hello' );
		$array   = $content->toArray();

		$this->assertArrayHasKey( 'type', $array );
		$this->assertArrayHasKey( 'text', $array );
		$this->assertSame( 'text', $array['type'] );
		$this->assertSame( 'Hello', $array['text'] );
	}

	/**
	 * Test that image() creates an ImageContent DTO.
	 */
	public function test_image_creates_image_content_dto(): void {
		$content = ContentBlockHelper::image( 'base64data', 'image/png' );

		$this->assertInstanceOf( ImageContent::class, $content );
		$this->assertInstanceOf( ContentBlockInterface::class, $content );
		$this->assertSame( 'image', $content->getType() );
		$this->assertSame( 'base64data', $content->getData() );
		$this->assertSame( 'image/png', $content->getMimeType() );
		$this->assertNull( $content->getAnnotations() );
		$this->assertNull( $content->get_meta() );
	}

	/**
	 * Test that image() creates an ImageContent with annotations.
	 */
	public function test_image_with_annotations(): void {
		$annotations = new Annotations( array( 'user' ), 1.0 );
		$content     = ContentBlockHelper::image( 'data', 'image/jpeg', $annotations );

		$this->assertInstanceOf( ImageContent::class, $content );
		$this->assertSame( $annotations, $content->getAnnotations() );
	}

	/**
	 * Test that image() toArray produces valid structure.
	 */
	public function test_image_to_array_produces_valid_structure(): void {
		$content = ContentBlockHelper::image( 'base64data', 'image/png' );
		$array   = $content->toArray();

		$this->assertArrayHasKey( 'type', $array );
		$this->assertArrayHasKey( 'data', $array );
		$this->assertArrayHasKey( 'mimeType', $array );
		$this->assertSame( 'image', $array['type'] );
		$this->assertSame( 'base64data', $array['data'] );
		$this->assertSame( 'image/png', $array['mimeType'] );
	}

	/**
	 * Test that audio() creates an AudioContent DTO.
	 */
	public function test_audio_creates_audio_content_dto(): void {
		$content = ContentBlockHelper::audio( 'base64audiodata', 'audio/mp3' );

		$this->assertInstanceOf( AudioContent::class, $content );
		$this->assertInstanceOf( ContentBlockInterface::class, $content );
		$this->assertSame( 'audio', $content->getType() );
		$this->assertSame( 'base64audiodata', $content->getData() );
		$this->assertSame( 'audio/mp3', $content->getMimeType() );
		$this->assertNull( $content->getAnnotations() );
		$this->assertNull( $content->get_meta() );
	}

	/**
	 * Test that audio() creates an AudioContent with annotations.
	 */
	public function test_audio_with_annotations(): void {
		$annotations = new Annotations( array( 'assistant' ), 0.5 );
		$content     = ContentBlockHelper::audio( 'data', 'audio/wav', $annotations );

		$this->assertInstanceOf( AudioContent::class, $content );
		$this->assertSame( $annotations, $content->getAnnotations() );
	}

	/**
	 * Test that audio() toArray produces valid structure.
	 */
	public function test_audio_to_array_produces_valid_structure(): void {
		$content = ContentBlockHelper::audio( 'audiodata', 'audio/ogg' );
		$array   = $content->toArray();

		$this->assertArrayHasKey( 'type', $array );
		$this->assertArrayHasKey( 'data', $array );
		$this->assertArrayHasKey( 'mimeType', $array );
		$this->assertSame( 'audio', $array['type'] );
		$this->assertSame( 'audiodata', $array['data'] );
		$this->assertSame( 'audio/ogg', $array['mimeType'] );
	}

	/**
	 * Test that embeddedTextResource() creates an EmbeddedResource with TextResourceContents.
	 */
	public function test_embedded_text_resource_creates_embedded_resource_dto(): void {
		$content = ContentBlockHelper::embedded_text_resource( 'file:///test.txt', 'Hello content' );

		$this->assertInstanceOf( EmbeddedResource::class, $content );
		$this->assertInstanceOf( ContentBlockInterface::class, $content );
		$this->assertSame( 'resource', $content->getType() );

		$resource = $content->getResource();
		$this->assertInstanceOf( TextResourceContents::class, $resource );
		$this->assertSame( 'file:///test.txt', $resource->getUri() );
		$this->assertSame( 'Hello content', $resource->getText() );
	}

	/**
	 * Test that embeddedTextResource() accepts optional mimeType.
	 */
	public function test_embedded_text_resource_with_mime_type(): void {
		$content  = ContentBlockHelper::embedded_text_resource( 'file:///test.json', '{}', 'application/json' );
		$resource = $content->getResource();

		$this->assertSame( 'application/json', $resource->getMimeType() );
	}

	/**
	 * Test that embeddedTextResource() with annotations.
	 */
	public function test_embedded_text_resource_with_annotations(): void {
		$annotations = new Annotations( array( 'user' ) );
		$content     = ContentBlockHelper::embedded_text_resource( 'file:///test.txt', 'content', null, $annotations );

		$this->assertSame( $annotations, $content->getAnnotations() );
	}

	/**
	 * Test that embeddedBlobResource() creates an EmbeddedResource with BlobResourceContents.
	 */
	public function test_embedded_blob_resource_creates_embedded_resource_dto(): void {
		$content = ContentBlockHelper::embedded_blob_resource( 'file:///image.png', 'base64blob', 'image/png' );

		$this->assertInstanceOf( EmbeddedResource::class, $content );
		$this->assertInstanceOf( ContentBlockInterface::class, $content );
		$this->assertSame( 'resource', $content->getType() );

		$resource = $content->getResource();
		$this->assertInstanceOf( BlobResourceContents::class, $resource );
		$this->assertSame( 'file:///image.png', $resource->getUri() );
		$this->assertSame( 'base64blob', $resource->getBlob() );
		$this->assertSame( 'image/png', $resource->getMimeType() );
	}

	/**
	 * Test that embeddedBlobResource() with annotations.
	 */
	public function test_embedded_blob_resource_with_annotations(): void {
		$annotations = new Annotations( array( 'assistant' ), 0.9 );
		$content     = ContentBlockHelper::embedded_blob_resource( 'file:///doc.pdf', 'data', 'application/pdf', $annotations );

		$this->assertSame( $annotations, $content->getAnnotations() );
	}

	/**
	 * Test that errorText() creates a TextContent for error messages.
	 */
	public function test_error_text_creates_text_content_dto(): void {
		$content = ContentBlockHelper::error_text( 'Something went wrong' );

		$this->assertInstanceOf( TextContent::class, $content );
		$this->assertSame( 'text', $content->getType() );
		$this->assertSame( 'Something went wrong', $content->getText() );
	}

	/**
	 * Test that jsonText() creates a TextContent with JSON-encoded data.
	 */
	public function test_json_text_creates_text_content_with_json(): void {
		$data    = array(
			'key'    => 'value',
			'nested' => array( 'a' => 1 ),
		);
		$content = ContentBlockHelper::json_text( $data );

		$this->assertInstanceOf( TextContent::class, $content );
		$this->assertSame( 'text', $content->getType() );
		$this->assertSame( '{"key":"value","nested":{"a":1}}', $content->getText() );
	}

	/**
	 * Test that jsonText() handles encoding options.
	 */
	public function test_json_text_with_pretty_print(): void {
		$data    = array( 'key' => 'value' );
		$content = ContentBlockHelper::json_text( $data, JSON_PRETTY_PRINT );

		$this->assertInstanceOf( TextContent::class, $content );
		$expected = "{\n    \"key\": \"value\"\n}";
		$this->assertSame( $expected, $content->getText() );
	}

	/**
	 * Test that toArrayList() converts array of DTOs to array format.
	 */
	public function test_to_array_list_converts_dtos_to_arrays(): void {
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
	 * Test that toArrayList() handles empty array.
	 */
	public function test_to_array_list_handles_empty_array(): void {
		$arrays = ContentBlockHelper::to_array_list( array() );

		$this->assertIsArray( $arrays );
		$this->assertEmpty( $arrays );
	}

	/**
	 * Test that toArrayList() handles mixed content types.
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
