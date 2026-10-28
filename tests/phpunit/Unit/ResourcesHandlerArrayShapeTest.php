<?php
/**
 * Resource contents must retain JSON array shape regardless of callback keys.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit;

use WP\MCP\Domain\Resources\McpResource;
use WP\MCP\Handlers\Resources\ResourcesHandler;
use WP\MCP\Tests\TestCase;
use WP\McpSchema\Record\ReadResourceRequest;
use WP\McpSchema\Record\ReadResourceResult;

/** Protects the resources/read contents list boundary. */
final class ResourcesHandlerArrayShapeTest extends TestCase {

	/**
	 * Callback keys must not turn serialized contents into a JSON object.
	 *
	 * @dataProvider resource_contents_key_provider
	 * @param array<int, int|string> $keys Callback keys in insertion order.
	 * @param bool                  $reverse Whether to return the blob first.
	 */
	public function test_resource_contents_are_a_json_array( array $keys, bool $reverse ): void {
		$uri  = 'fixture://issue113/content';
		$text = array(
			'text'     => 'resource text',
			'mimeType' => 'text/plain',
			'_meta'    => array( 'ui' => array( 'border' => true ) ),
		);
		$blob = array(
			'uri'      => 'fixture://issue113/blob',
			'blob'     => 'YmxvYg==',
			'mimeType' => 'application/octet-stream',
			'_meta'    => array( 'enabled' => false ),
		);

		$items    = $reverse ? array( $blob, $text ) : array( $text, $blob );
		$contents = array(
			$keys[0] => $items[0],
			$keys[1] => $items[1],
		);
		$resource = McpResource::fromArray(
			array(
				'name'       => 'Array shape fixture',
				'uri'        => $uri,
				'handler'    => static fn(): array => $contents,
				'permission' => '__return_true',
			)
		);
		$this->assertInstanceOf( McpResource::class, $resource );
		$server  = $this->makeServer( array(), array( $resource ) );
		$schema  = $this->schema();
		$request = $schema->fromArray(
			ReadResourceRequest::class,
			array(
				'jsonrpc' => '2.0',
				'id'      => 113,
				'method'  => 'resources/read',
				'params'  => array( 'uri' => $uri ),
			)
		);
		$result = ( new ResourcesHandler( $server ) )->read_resource(
			$request,
			$this->request_context( $server )
		);

		$expected_text        = $text;
		$expected_text['uri'] = $uri;
		$expected             = $reverse ? array( $blob, $expected_text ) : array( $expected_text, $blob );

		$this->assertSame( array( 0, 1 ), array_keys( $result['contents'] ) );
		foreach ( $expected as $index => $item ) {
			$this->assertCount( count( $item ), $result['contents'][ $index ] );
			foreach ( $item as $field => $value ) {
				$this->assertArrayHasKey( $field, $result['contents'][ $index ] );
				$this->assertSame( $value, $result['contents'][ $index ][ $field ] );
			}
		}

		$json = wp_json_encode( $result );
		$this->assertIsString( $json );
		$decoded = json_decode( $json, false, 512, JSON_THROW_ON_ERROR );
		$this->assertIsArray( $decoded->contents );
		$this->assertCount( 2, $decoded->contents );
		$this->assertInstanceOf(
			ReadResourceResult::class,
			$schema->fromJson( ReadResourceResult::class, $json )
		);
	}

	/**
	 * Callback collection shapes, including insertion order unlike key order.
	 *
	 * @return array<string, array{array<int, int|string>, bool}>
	 */
	public static function resource_contents_key_provider(): array {
		return array(
			'sequential'           => array( array( 0, 1 ), false ),
			'sparse'               => array( array( 3, 8 ), false ),
			'associative'          => array( array( 'alpha', 'beta' ), false ),
			'out-of-order indexes' => array( array( 8, 3 ), true ),
		);
	}
}
