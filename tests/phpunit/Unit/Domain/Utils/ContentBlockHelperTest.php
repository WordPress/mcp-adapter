<?php
/**
 * Revision-neutral content block helper contracts.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Domain\Utils;

use WP\MCP\Domain\Utils\ContentBlockHelper;
use WP\MCP\Tests\TestCase;

/** Protects content variants and the two independent embedded-resource metadata levels. */
final class ContentBlockHelperTest extends TestCase {

	/** Image and audio blocks retain media data, annotations, and object metadata. */
	public function test_media_blocks_preserve_protocol_fields(): void {
		$annotations = array( 'audience' => array( 'user' ) );
		$meta        = array( 'vendor' => true );
		$image       = ContentBlockHelper::image( 'aW1hZ2U=', 'image/png', $annotations, $meta );
		$audio       = ContentBlockHelper::audio( 'YXVkaW8=', 'audio/mpeg', $annotations, $meta );

		$this->assertSame( 'image', $image['type'] );
		$this->assertSame( 'aW1hZ2U=', $image['data'] );
		$this->assertSame( 'image/png', $image['mimeType'] );
		$this->assertSame( $annotations, $image['annotations'] );
		$this->assertSame( $meta, $image['_meta'] );

		$this->assertSame( 'audio', $audio['type'] );
		$this->assertSame( 'YXVkaW8=', $audio['data'] );
		$this->assertSame( 'audio/mpeg', $audio['mimeType'] );
		$this->assertSame( $annotations, $audio['annotations'] );
		$this->assertSame( $meta, $audio['_meta'] );
	}

	/** Embedded text and blob resources keep block and resource metadata separate. */
	public function test_embedded_resources_preserve_independent_metadata(): void {
		$text = ContentBlockHelper::embedded_text_resource(
			'fixture://text',
			'hello',
			'text/plain',
			array( 'priority' => 1 ),
			array( 'block' => true ),
			array( 'resource' => true )
		);
		$blob = ContentBlockHelper::embedded_blob_resource(
			'fixture://blob',
			'YmxvYg==',
			'application/octet-stream',
			null,
			array( 'block' => true ),
			array( 'resource' => true )
		);

		$this->assertSame( 'resource', $text['type'] );
		$this->assertSame( 'hello', $text['resource']['text'] );
		$this->assertSame( 'text/plain', $text['resource']['mimeType'] );
		$this->assertTrue( $text['_meta']['block'] );
		$this->assertTrue( $text['resource']['_meta']['resource'] );
		$this->assertSame( array( 'priority' => 1 ), $text['annotations'] );

		$this->assertSame( 'YmxvYg==', $blob['resource']['blob'] );
		$this->assertSame( 'application/octet-stream', $blob['resource']['mimeType'] );
		$this->assertTrue( $blob['_meta']['block'] );
		$this->assertTrue( $blob['resource']['_meta']['resource'] );
	}

	/** List-shaped metadata is omitted at every optional metadata boundary. */
	public function test_list_shaped_metadata_is_omitted(): void {
		$image = ContentBlockHelper::image( 'data', 'image/png', null, array( 'list' ) );
		$text  = ContentBlockHelper::embedded_text_resource(
			'fixture://text',
			'hello',
			null,
			null,
			array( 'block-list' ),
			array( 'resource-list' )
		);

		$this->assertArrayNotHasKey( '_meta', $image );
		$this->assertArrayNotHasKey( '_meta', $text );
		$this->assertArrayNotHasKey( '_meta', $text['resource'] );
	}

	/** Text, error, and JSON helpers retain empty values and encoding options. */
	public function test_text_and_json_helpers_preserve_values(): void {
		$this->assertSame( '', ContentBlockHelper::text( '' )['text'] );
		$this->assertSame( 'failed', ContentBlockHelper::error_text( 'failed' )['text'] );

		$json = ContentBlockHelper::json_text( array( 'value' => 1 ), JSON_PRETTY_PRINT, null, array( 'json' => true ) );
		$this->assertStringContainsString( "\n", $json['text'] );
		$this->assertStringContainsString( '"value": 1', $json['text'] );
		$this->assertTrue( $json['_meta']['json'] );
	}

	/** List normalization preserves order while resetting numeric keys. */
	public function test_to_array_list_resets_numeric_keys(): void {
		$blocks = ContentBlockHelper::to_array_list(
			array(
				4 => ContentBlockHelper::text( 'first' ),
				9 => ContentBlockHelper::text( 'second' ),
			)
		);

		$this->assertSame( array( 0, 1 ), array_keys( $blocks ) );
		$this->assertSame( 'first', $blocks[0]['text'] );
		$this->assertSame( 'second', $blocks[1]['text'] );
	}
}
