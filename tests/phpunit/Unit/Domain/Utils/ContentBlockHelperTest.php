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

	public function test_text_returns_revision_neutral_content_data(): void {
		$this->assertSame(
			array(
				'type' => 'text',
				'text' => 'Hello, World!',
			),
			ContentBlockHelper::text( 'Hello, World!' )
		);
	}

	public function test_text_accepts_empty_string_and_optional_fields(): void {
		$annotations = array(
			'audience' => array( 'user' ),
			'priority' => 0.8,
		);
		$content     = ContentBlockHelper::text( '', $annotations, array( 'key' => 'value' ) );

		$this->assertSame( '', $content['text'] );
		$this->assertSame( $annotations, $content['annotations'] );
		$this->assertSame( array( 'key' => 'value' ), $content['_meta'] );
	}

	public function test_image_returns_revision_neutral_content_data(): void {
		$this->assertSame(
			array(
				'type'     => 'image',
				'data'     => 'base64data',
				'mimeType' => 'image/png',
			),
			ContentBlockHelper::image( 'base64data', 'image/png' )
		);
	}

	public function test_audio_returns_revision_neutral_content_data(): void {
		$this->assertSame(
			array(
				'type'     => 'audio',
				'data'     => 'base64audiodata',
				'mimeType' => 'audio/mp3',
			),
			ContentBlockHelper::audio( 'base64audiodata', 'audio/mp3' )
		);
	}

	public function test_embedded_text_resource_preserves_nested_shape(): void {
		$content = ContentBlockHelper::embedded_text_resource(
			'ui://example/app',
			'<!doctype html>',
			'text/html;profile=mcp-app',
			array( 'audience' => array( 'user' ) ),
			array( 'block' => 'level' ),
			array( 'ui' => array( 'prefersBorder' => true ) )
		);

		$this->assertSame( 'resource', $content['type'] );
		$this->assertSame( 'ui://example/app', $content['resource']['uri'] );
		$this->assertSame( '<!doctype html>', $content['resource']['text'] );
		$this->assertSame( 'text/html;profile=mcp-app', $content['resource']['mimeType'] );
		$this->assertSame( array( 'block' => 'level' ), $content['_meta'] );
		$this->assertSame( array( 'ui' => array( 'prefersBorder' => true ) ), $content['resource']['_meta'] );
	}

	public function test_embedded_blob_resource_preserves_nested_shape(): void {
		$content = ContentBlockHelper::embedded_blob_resource(
			'file:///doc.pdf',
			'base64blob',
			'application/pdf',
			null,
			array( 'block' => 'level' ),
			array( 'pages' => 3 )
		);

		$this->assertSame( 'resource', $content['type'] );
		$this->assertSame( 'file:///doc.pdf', $content['resource']['uri'] );
		$this->assertSame( 'base64blob', $content['resource']['blob'] );
		$this->assertSame( 'application/pdf', $content['resource']['mimeType'] );
		$this->assertSame( array( 'block' => 'level' ), $content['_meta'] );
		$this->assertSame( array( 'pages' => 3 ), $content['resource']['_meta'] );
	}

	public function test_list_shaped_meta_is_omitted_on_both_levels(): void {
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

	public function test_error_text_delegates_to_text_shape(): void {
		$this->assertSame(
			array(
				'type' => 'text',
				'text' => 'Something went wrong',
			),
			ContentBlockHelper::error_text( 'Something went wrong' )
		);
	}

	public function test_json_text_encodes_data_and_honors_flags(): void {
		$content = ContentBlockHelper::json_text( array( 'key' => 'value' ), JSON_PRETTY_PRINT );

		$this->assertSame( 'text', $content['type'] );
		$this->assertSame( "{\n    \"key\": \"value\"\n}", $content['text'] );
	}

	public function test_to_array_list_returns_a_reindexed_list(): void {
		$blocks = array(
			5 => ContentBlockHelper::text( 'First' ),
			9 => ContentBlockHelper::image( 'imgdata', 'image/png' ),
		);

		$arrays = ContentBlockHelper::to_array_list( $blocks );

		$this->assertSame( array( 0, 1 ), array_keys( $arrays ) );
		$this->assertSame( 'text', $arrays[0]['type'] );
		$this->assertSame( 'image', $arrays[1]['type'] );
	}
}
