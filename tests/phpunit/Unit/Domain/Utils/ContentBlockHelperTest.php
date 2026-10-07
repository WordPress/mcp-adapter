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

	/** Image blocks retain media data, annotations, and metadata. */
	public function test_image_block_preserves_protocol_fields(): void {
		$annotations = array( 'audience' => array( 'user' ) );
		$meta        = array( 'vendor' => true );
		$image       = ContentBlockHelper::image( 'aW1hZ2U=', 'image/png', $annotations, $meta );

		$this->assertSame( 'image', $image['type'] );
		$this->assertSame( 'aW1hZ2U=', $image['data'] );
		$this->assertSame( 'image/png', $image['mimeType'] );
		$this->assertSame( $annotations, $image['annotations'] );
		$this->assertSame( $meta, $image['_meta'] );
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

	/** Metadata is carried as given at every level; the schema decides whether it fits. */
	public function test_metadata_is_carried_as_given(): void {
		$image = ContentBlockHelper::image( 'data', 'image/png', null, array( 'list' ) );
		$text  = ContentBlockHelper::embedded_text_resource(
			'fixture://text',
			'hello',
			null,
			null,
			array( 'block-list' ),
			array( 'resource-list' )
		);

		$this->assertSame( array( 'list' ), $image['_meta'] );
		$this->assertSame( array( 'block-list' ), $text['_meta'] );
		$this->assertSame( array( 'resource-list' ), $text['resource']['_meta'] );
	}

	/** Decoded JSON objects and untyped MIME values reach the schema unchanged. */
	public function test_object_metadata_and_mime_type_are_carried_as_given(): void {
		$block_meta    = (object) array( '0' => 'numeric-key' );
		$resource_meta = (object) array( 'owner' => 'resource' );
		$text          = ContentBlockHelper::embedded_text_resource( 'fixture://text', 'hello', 42, null, $block_meta, $resource_meta );
		$image         = ContentBlockHelper::image( 'data', 42, null, $block_meta );

		$this->assertSame( $block_meta, $text['_meta'] );
		$this->assertSame( $resource_meta, $text['resource']['_meta'] );
		$this->assertSame( 42, $text['resource']['mimeType'] );
		$this->assertSame( $block_meta, $image['_meta'] );
		$this->assertSame( 42, $image['mimeType'] );
	}

	/** Absent optional fields are omitted while empty text is kept. */
	public function test_text_helper_preserves_empty_text_and_omits_absent_fields(): void {
		$text = ContentBlockHelper::text( '' );

		$this->assertSame( '', $text['text'] );
		$this->assertArrayNotHasKey( 'annotations', $text );
		$this->assertArrayNotHasKey( '_meta', $text );
	}
}
