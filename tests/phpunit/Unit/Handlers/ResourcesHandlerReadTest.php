<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Handlers;

use WP\MCP\Handlers\Resources\ResourcesHandler;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\TestCase;
use WP\McpSchema\Common\JsonRpc\DTO\JSONRPCErrorResponse;
use WP\McpSchema\Common\Protocol\DTO\BlobResourceContents;
use WP\McpSchema\Common\Protocol\DTO\TextResourceContents;
use WP\McpSchema\Server\Resources\DTO\ReadResourceResult;

final class ResourcesHandlerReadTest extends TestCase {

	public function test_missing_uri_returns_error(): void {
		wp_set_current_user( 1 );
		$server  = $this->makeServer( array(), array( 'test/resource' ) );
		$handler = new ResourcesHandler( $server );
		$result  = $handler->read_resource( array( 'params' => array() ) );

		// Missing uri is a protocol error - returns JSONRPCErrorResponse
		$this->assertInstanceOf( JSONRPCErrorResponse::class, $result );
		$error = $result->getError();
		$this->assertNotNull( $error );
		$this->assertNotEmpty( $error->getMessage() );
	}

	public function test_unknown_resource_returns_error(): void {
		wp_set_current_user( 1 );
		$server  = $this->makeServer();
		$handler = new ResourcesHandler( $server );
		$result  = $handler->read_resource( array( 'params' => array( 'uri' => 'WordPress://missing' ) ) );

		// Resource not found is a protocol error - returns JSONRPCErrorResponse
		$this->assertInstanceOf( JSONRPCErrorResponse::class, $result );
		$error = $result->getError();
		$this->assertNotNull( $error );
		$this->assertNotEmpty( $error->getMessage() );
	}

	public function test_successful_read_returns_contents(): void {
		wp_set_current_user( 1 );
		$server  = $this->makeServer( array(), array( 'test/resource' ) );
		$handler = new ResourcesHandler( $server );
		$result  = $handler->read_resource( array( 'params' => array( 'uri' => 'WordPress://local/resource-1' ) ) );

		// Successful read returns ReadResourceResult DTO
		$this->assertInstanceOf( ReadResourceResult::class, $result );

		// Use DTO getter methods
		$contents = $result->getContents();
		$this->assertNotEmpty( $contents );
		$this->assertInstanceOf( TextResourceContents::class, $contents[0] );
	}

	public function test_read_resource_returns_blob_contents_for_blob_data(): void {
		wp_set_current_user( 1 );

		$server  = $this->makeServer( array(), array( 'test/resource-blob-content' ) );
		$handler = new ResourcesHandler( $server );

		$result = $handler->read_resource(
			array( 'params' => array( 'uri' => 'WordPress://local/resource-blob-content' ) )
		);

		// Successful read returns ReadResourceResult DTO.
		$this->assertInstanceOf( ReadResourceResult::class, $result );

		$contents = $result->getContents();
		$this->assertNotEmpty( $contents );

		// Should be BlobResourceContents since ability returns blob data.
		$this->assertInstanceOf( BlobResourceContents::class, $contents[0] );

		// Verify blob content.
		$blob = $contents[0]->getBlob();
		$this->assertNotEmpty( $blob );

		// Verify mimeType is preserved.
		$this->assertSame( 'application/octet-stream', $contents[0]->getMimeType() );
	}

	public function test_read_resource_handles_multiple_content_items(): void {
		wp_set_current_user( 1 );

		$server  = $this->makeServer( array(), array( 'test/resource-multiple-contents' ) );
		$handler = new ResourcesHandler( $server );

		$result = $handler->read_resource(
			array( 'params' => array( 'uri' => 'WordPress://local/resource-multiple-contents' ) )
		);

		$this->assertInstanceOf( ReadResourceResult::class, $result );

		$contents = $result->getContents();
		$this->assertCount( 2, $contents, 'Should have 2 content items' );

		// Both should be TextResourceContents.
		$this->assertInstanceOf( TextResourceContents::class, $contents[0] );
		$this->assertInstanceOf( TextResourceContents::class, $contents[1] );

		// Verify content.
		$this->assertSame( 'First content part', $contents[0]->getText() );
		$this->assertSame( 'Second content part', $contents[1]->getText() );

		// Verify URIs are preserved.
		$this->assertSame( 'WordPress://local/resource-multi/part1', $contents[0]->getUri() );
		$this->assertSame( 'WordPress://local/resource-multi/part2', $contents[1]->getUri() );
	}

	public function test_read_resource_returns_text_with_custom_mimetype(): void {
		wp_set_current_user( 1 );

		$server  = $this->makeServer( array(), array( 'test/resource-text-with-mimetype' ) );
		$handler = new ResourcesHandler( $server );

		$result = $handler->read_resource(
			array( 'params' => array( 'uri' => 'WordPress://local/resource-text-with-mimetype' ) )
		);

		$this->assertInstanceOf( ReadResourceResult::class, $result );

		$contents = $result->getContents();
		$this->assertNotEmpty( $contents );
		$this->assertInstanceOf( TextResourceContents::class, $contents[0] );

		// Verify mimeType is preserved.
		$this->assertSame( 'application/json', $contents[0]->getMimeType() );

		// Verify content.
		$this->assertSame( '{"key": "value"}', $contents[0]->getText() );
	}

	public function test_read_resource_wraps_plain_string_as_text(): void {
		wp_set_current_user( 1 );

		$server  = $this->makeServer( array(), array( 'test/resource-plain-string' ) );
		$handler = new ResourcesHandler( $server );

		$result = $handler->read_resource(
			array( 'params' => array( 'uri' => 'WordPress://local/resource-plain-string' ) )
		);

		$this->assertInstanceOf( ReadResourceResult::class, $result );

		$contents = $result->getContents();
		$this->assertNotEmpty( $contents );
		$this->assertInstanceOf( TextResourceContents::class, $contents[0] );

		// Verify content is the plain string.
		$this->assertSame( 'plain string content', $contents[0]->getText() );

		// Verify URI is the resource URI.
		$this->assertSame( 'WordPress://local/resource-plain-string', $contents[0]->getUri() );
	}

	public function test_read_resource_with_lowercased_scheme_echoes_advertised_uri(): void {
		wp_set_current_user( 1 );

		$server  = $this->makeServer( array(), array( 'test/resource-plain-string' ) );
		$handler = new ResourcesHandler( $server );

		// The resource is advertised with a mixed-case scheme (WordPress://...). A client
		// that canonicalizes the scheme to lowercase per RFC 3986 3.1 still resolves it, and
		// the response must echo the advertised URI, not the request's lowercased scheme, so
		// contents[].uri stays consistent with resources/list.
		$result = $handler->read_resource(
			array( 'params' => array( 'uri' => 'wordpress://local/resource-plain-string' ) ) // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- The lowercase scheme is the point of the test.
		);

		$this->assertInstanceOf( ReadResourceResult::class, $result );

		$contents = $result->getContents();
		$this->assertNotEmpty( $contents );
		$this->assertSame(
			'WordPress://local/resource-plain-string',
			$contents[0]->getUri(),
			'Read response must echo the advertised URI, not the client-lowercased scheme.'
		);
	}

	public function test_pre_resource_read_filter_can_modify_params(): void {
		wp_set_current_user( 1 );
		$server  = $this->makeServer( array(), array( 'test/resource' ) );
		$handler = new ResourcesHandler( $server );

		$received_params = null;
		$filter          = static function ( array $params, string $uri ) use ( &$received_params ): array {
			$received_params = $params;

			return $params;
		};
		add_filter( 'mcp_adapter_pre_resource_read', $filter, 10, 2 );

		$handler->read_resource(
			array(
				'params' => array( 'uri' => 'WordPress://local/resource-1' ),
			)
		);

		$this->assertIsArray( $received_params );

		remove_filter( 'mcp_adapter_pre_resource_read', $filter );
	}

	public function test_pre_resource_read_filter_can_short_circuit_with_wp_error(): void {
		wp_set_current_user( 1 );
		$server  = $this->makeServer( array(), array( 'test/resource' ) );
		$handler = new ResourcesHandler( $server );

		$filter = static function () {
			return new \WP_Error( 'blocked', 'Resource access blocked' );
		};
		add_filter( 'mcp_adapter_pre_resource_read', $filter );

		$result = $handler->read_resource(
			array(
				'params' => array( 'uri' => 'WordPress://local/resource-1' ),
			)
		);

		// Short-circuit returns JSONRPCErrorResponse.
		$this->assertInstanceOf( JSONRPCErrorResponse::class, $result );
		$error = $result->getError();
		$this->assertNotNull( $error );
		$this->assertStringContainsString( 'Resource access blocked', $error->getMessage() );

		remove_filter( 'mcp_adapter_pre_resource_read', $filter );
	}

	public function test_resource_read_result_filter_can_modify_contents(): void {
		wp_set_current_user( 1 );
		$server  = $this->makeServer( array(), array( 'test/resource' ) );
		$handler = new ResourcesHandler( $server );

		$filter_was_called = false;
		$filter            = static function ( $contents ) use ( &$filter_was_called ) {
			$filter_was_called = true;

			return $contents;
		};
		add_filter( 'mcp_adapter_resource_read_result', $filter );

		$handler->read_resource(
			array(
				'params' => array( 'uri' => 'WordPress://local/resource-1' ),
			)
		);

		$this->assertTrue( $filter_was_called );

		remove_filter( 'mcp_adapter_resource_read_result', $filter );
	}

	public function test_read_resource_wraps_non_array_result_as_json(): void {
		wp_set_current_user( 1 );

		// Register an ability that returns an object (associative array without uri/text keys).
		$this->register_ability_in_hook(
			'test/resource-object-result',
			array(
				'label'               => 'Resource Object Result',
				'description'         => 'Returns an object result',
				'category'            => 'test',
				'execute_callback'    => static function () {
					return array(
						'status' => 'ok',
						'count'  => 42,
					);
				},
				'permission_callback' => static function () {
					return true;
				},
				'meta'                => array(
					'mcp' => array(
						'public' => true,
						'type'   => 'resource',
						'uri'    => 'WordPress://local/resource-object-result',
					),
				),
			)
		);

		$server  = $this->makeServer( array(), array( 'test/resource-object-result' ) );
		$handler = new ResourcesHandler( $server );

		$result = $handler->read_resource(
			array( 'params' => array( 'uri' => 'WordPress://local/resource-object-result' ) )
		);

		$this->assertInstanceOf( ReadResourceResult::class, $result );

		$contents = $result->getContents();
		$this->assertNotEmpty( $contents );
		$this->assertInstanceOf( TextResourceContents::class, $contents[0] );

		// Verify content is JSON-encoded.
		$text = $contents[0]->getText();
		$this->assertJson( $text );
		$decoded = json_decode( $text, true );
		$this->assertSame( 'ok', $decoded['status'] );
		$this->assertSame( 42, $decoded['count'] );

		// Clean up.
		wp_unregister_ability( 'test/resource-object-result' );
	}

	public function test_read_resource_with_throwing_result_filter_triggers_catch_block(): void {
		wp_set_current_user( 1 );
		$server  = $this->makeServer( array(), array( 'test/resource' ) );
		$handler = new ResourcesHandler( $server );

		$filter = static function () {
			throw new \RuntimeException( 'Filter exploded' );
		};
		add_filter( 'mcp_adapter_resource_read_result', $filter );

		$result = $handler->read_resource(
			array(
				'params' => array( 'uri' => 'WordPress://local/resource-1' ),
			)
		);

		$this->assertInstanceOf( JSONRPCErrorResponse::class, $result );
		$this->assertStringContainsString( 'Failed to read resource', $result->getError()->getMessage() );

		remove_filter( 'mcp_adapter_resource_read_result', $filter );
	}

	public function test_read_resource_preserves_meta_on_text_contents(): void {
		wp_set_current_user( 1 );
		$server  = $this->makeServer( array(), array( 'test/resource' ) );
		$handler = new ResourcesHandler( $server );

		$filter = static function () {
			return array(
				array(
					'uri'      => 'ui://example/app',
					'mimeType' => 'text/html;profile=mcp-app',
					'text'     => '<!doctype html>',
					'_meta'    => array( 'ui' => array( 'prefersBorder' => true ) ),
				),
			);
		};
		add_filter( 'mcp_adapter_resource_read_result', $filter );

		$result = $handler->read_resource(
			array( 'params' => array( 'uri' => 'WordPress://local/resource-1' ) )
		);

		remove_filter( 'mcp_adapter_resource_read_result', $filter );

		$this->assertInstanceOf( ReadResourceResult::class, $result );

		$contents = $result->getContents();
		$this->assertInstanceOf( TextResourceContents::class, $contents[0] );
		$this->assertSame( array( 'ui' => array( 'prefersBorder' => true ) ), $contents[0]->get_meta() );
	}

	public function test_read_resource_preserves_meta_on_blob_contents(): void {
		wp_set_current_user( 1 );
		$server  = $this->makeServer( array(), array( 'test/resource' ) );
		$handler = new ResourcesHandler( $server );

		$filter = static function () {
			return array(
				array(
					'uri'      => 'WordPress://local/resource-1',
					'mimeType' => 'application/pdf',
					'blob'     => 'ZGF0YQ==',
					'_meta'    => array( 'pages' => 3 ),
				),
			);
		};
		add_filter( 'mcp_adapter_resource_read_result', $filter );

		$result = $handler->read_resource(
			array( 'params' => array( 'uri' => 'WordPress://local/resource-1' ) )
		);

		remove_filter( 'mcp_adapter_resource_read_result', $filter );

		$this->assertInstanceOf( ReadResourceResult::class, $result );

		$contents = $result->getContents();
		$this->assertInstanceOf( BlobResourceContents::class, $contents[0] );
		$this->assertSame( array( 'pages' => 3 ), $contents[0]->get_meta() );
	}

	public function test_read_resource_with_non_array_meta_still_returns_contents(): void {
		wp_set_current_user( 1 );
		$server  = $this->makeServer( array(), array( 'test/resource' ) );
		$handler = new ResourcesHandler( $server );

		$filter = static function () {
			return array(
				array(
					'uri'   => 'WordPress://local/resource-1',
					'text'  => 'body',
					'_meta' => 'not-an-object',
				),
			);
		};
		add_filter( 'mcp_adapter_resource_read_result', $filter );

		$result = $handler->read_resource(
			array( 'params' => array( 'uri' => 'WordPress://local/resource-1' ) )
		);

		remove_filter( 'mcp_adapter_resource_read_result', $filter );

		// A malformed _meta is dropped, not raised: the resource body still reaches the client.
		$this->assertInstanceOf( ReadResourceResult::class, $result );

		$contents = $result->getContents();
		$this->assertInstanceOf( TextResourceContents::class, $contents[0] );
		$this->assertSame( 'body', $contents[0]->getText() );
		$this->assertNull( $contents[0]->get_meta() );
	}

	public function test_read_resource_with_list_meta_omits_it_from_the_wire(): void {
		wp_set_current_user( 1 );
		$server  = $this->makeServer( array(), array( 'test/resource' ) );
		$handler = new ResourcesHandler( $server );

		$filter = static function () {
			return array(
				array(
					'uri'   => 'WordPress://local/resource-1',
					'text'  => 'body',
					'_meta' => array( 'a', 'b' ),
				),
			);
		};
		add_filter( 'mcp_adapter_resource_read_result', $filter );

		$result = $handler->read_resource(
			array( 'params' => array( 'uri' => 'WordPress://local/resource-1' ) )
		);

		remove_filter( 'mcp_adapter_resource_read_result', $filter );

		$this->assertInstanceOf( ReadResourceResult::class, $result );

		$contents = $result->getContents();
		$this->assertNull( $contents[0]->get_meta() );

		// MCP declares _meta as a JSON object; a list would serialize as `"_meta": ["a","b"]`.
		$this->assertArrayNotHasKey( '_meta', $contents[0]->toArray() );
	}

	public function test_read_resource_without_meta_leaves_contents_meta_null(): void {
		wp_set_current_user( 1 );
		$server  = $this->makeServer( array(), array( 'test/resource' ) );
		$handler = new ResourcesHandler( $server );

		$result = $handler->read_resource(
			array( 'params' => array( 'uri' => 'WordPress://local/resource-1' ) )
		);

		$this->assertInstanceOf( ReadResourceResult::class, $result );

		$contents = $result->getContents();
		$this->assertInstanceOf( TextResourceContents::class, $contents[0] );
		$this->assertNull( $contents[0]->get_meta() );
	}

	/**
	 * A client strips metadata it does not recognize, so nothing downstream reports a
	 * `_meta` that could not be emitted. The log is the only place it surfaces.
	 */
	public function test_read_resource_with_list_meta_logs_the_drop(): void {
		wp_set_current_user( 1 );
		$server  = $this->makeServer( array(), array( 'test/resource' ) );
		$handler = new ResourcesHandler( $server );

		$filter = static function () {
			return array(
				array(
					'uri'   => 'WordPress://local/resource-1',
					'text'  => 'body',
					'_meta' => array( 'a', 'b' ),
				),
			);
		};
		add_filter( 'mcp_adapter_resource_read_result', $filter );

		$handler->read_resource(
			array( 'params' => array( 'uri' => 'WordPress://local/resource-1' ) )
		);

		remove_filter( 'mcp_adapter_resource_read_result', $filter );

		$messages = array_column( DummyErrorHandler::$logs, 'message' );
		$this->assertContains( 'Invalid _meta on resource contents, dropping it', $messages );
	}

	/**
	 * An absent `_meta` is the ordinary case and must stay quiet, or the log fills with
	 * noise from every resource that never asked for metadata.
	 */
	public function test_read_resource_without_meta_does_not_log(): void {
		wp_set_current_user( 1 );
		$server  = $this->makeServer( array(), array( 'test/resource' ) );
		$handler = new ResourcesHandler( $server );

		$filter = static function () {
			return array(
				array(
					'uri'  => 'WordPress://local/resource-1',
					'text' => 'body',
				),
			);
		};
		add_filter( 'mcp_adapter_resource_read_result', $filter );

		$handler->read_resource(
			array( 'params' => array( 'uri' => 'WordPress://local/resource-1' ) )
		);

		remove_filter( 'mcp_adapter_resource_read_result', $filter );

		$messages = array_column( DummyErrorHandler::$logs, 'message' );
		$this->assertNotContains( 'Invalid _meta on resource contents, dropping it', $messages );
	}

	/**
	 * `blob` alone is enough to describe resource contents: the URI falls back to the
	 * resource's own, and binary contents carry no `text`.
	 */
	public function test_read_resource_with_blob_only_item_returns_blob_contents(): void {
		wp_set_current_user( 1 );
		$server  = $this->makeServer( array(), array( 'test/resource' ) );
		$handler = new ResourcesHandler( $server );

		$filter = static function () {
			return array(
				array(
					'mimeType' => 'application/pdf',
					'blob'     => 'ZGF0YQ==',
				),
			);
		};
		add_filter( 'mcp_adapter_resource_read_result', $filter );

		$result = $handler->read_resource(
			array( 'params' => array( 'uri' => 'WordPress://local/resource-1' ) )
		);

		remove_filter( 'mcp_adapter_resource_read_result', $filter );

		$this->assertInstanceOf( ReadResourceResult::class, $result );

		$contents = $result->getContents();
		$this->assertInstanceOf( BlobResourceContents::class, $contents[0] );
		$this->assertSame( 'ZGF0YQ==', $contents[0]->getBlob() );
		$this->assertSame( 'application/pdf', $contents[0]->getMimeType() );
		$this->assertSame( 'WordPress://local/resource-1', $contents[0]->getUri() );
	}

	public function test_read_resource_with_blob_only_item_preserves_meta(): void {
		wp_set_current_user( 1 );
		$server  = $this->makeServer( array(), array( 'test/resource' ) );
		$handler = new ResourcesHandler( $server );

		$filter = static function () {
			return array(
				array(
					'mimeType' => 'application/pdf',
					'blob'     => 'ZGF0YQ==',
					'_meta'    => array( 'pages' => 3 ),
				),
			);
		};
		add_filter( 'mcp_adapter_resource_read_result', $filter );

		$result = $handler->read_resource(
			array( 'params' => array( 'uri' => 'WordPress://local/resource-1' ) )
		);

		remove_filter( 'mcp_adapter_resource_read_result', $filter );

		$this->assertInstanceOf( ReadResourceResult::class, $result );

		$contents = $result->getContents();
		$this->assertInstanceOf( BlobResourceContents::class, $contents[0] );
		$this->assertSame( array( 'pages' => 3 ), $contents[0]->get_meta() );
	}

	/**
	 * Only the first item is inspected to decide whether the return is a list of content
	 * items, so a first item the check does not recognize costs every sibling as well.
	 */
	public function test_read_resource_with_blob_only_first_item_keeps_its_siblings(): void {
		wp_set_current_user( 1 );
		$server  = $this->makeServer( array(), array( 'test/resource' ) );
		$handler = new ResourcesHandler( $server );

		$filter = static function () {
			return array(
				array(
					'mimeType' => 'application/pdf',
					'blob'     => 'ZGF0YQ==',
				),
				array(
					'uri'  => 'WordPress://local/resource-2',
					'text' => 'sibling',
				),
			);
		};
		add_filter( 'mcp_adapter_resource_read_result', $filter );

		$result = $handler->read_resource(
			array( 'params' => array( 'uri' => 'WordPress://local/resource-1' ) )
		);

		remove_filter( 'mcp_adapter_resource_read_result', $filter );

		$this->assertInstanceOf( ReadResourceResult::class, $result );

		$contents = $result->getContents();
		$this->assertCount( 2, $contents );
		$this->assertInstanceOf( BlobResourceContents::class, $contents[0] );
		$this->assertInstanceOf( TextResourceContents::class, $contents[1] );
		$this->assertSame( 'sibling', $contents[1]->getText() );
	}

	/**
	 * Checking only that every value is an array still mistakes an associative payload
	 * for the protocol's contents list. Besides changing its meaning, preserving those
	 * string keys would serialize `contents` as an object rather than a JSON array.
	 */
	public function test_read_resource_with_associative_all_array_payload_uses_text_fallback(): void {
		$shape = array(
			'thumbnail' => array(
				'blob'     => 'ZGF0YQ==',
				'mimeType' => 'image/png',
			),
			'metadata'  => array(
				'title' => 'Photo',
			),
		);

		$result = $this->read_resource_returning( $shape );
		$this->assertInstanceOf( ReadResourceResult::class, $result );

		$contents = $result->getContents();
		$this->assertCount( 1, $contents );
		$this->assertInstanceOf( TextResourceContents::class, $contents[0] );
		$this->assertSame( $shape, json_decode( $contents[0]->getText(), true ) );
		$this->assertSame( array( 0 ), array_keys( $result->toArray()['contents'] ) );
	}

	/**
	 * A list is only a protocol contents list when every member carries a field from
	 * which a content DTO can be built. Otherwise the complete data list is preserved.
	 */
	public function test_read_resource_with_unstructured_array_sibling_uses_text_fallback(): void {
		$shape = array(
			array(
				'blob'     => 'ZGF0YQ==',
				'mimeType' => 'image/png',
			),
			array(
				'title' => 'Photo',
			),
		);

		$result = $this->read_resource_returning( $shape );
		$this->assertInstanceOf( ReadResourceResult::class, $result );

		$contents = $result->getContents();
		$this->assertCount( 1, $contents );
		$this->assertInstanceOf( TextResourceContents::class, $contents[0] );
		$this->assertSame( $shape, json_decode( $contents[0]->getText(), true ) );
	}

	/**
	 * An object-shaped `_meta` may still contain a value JSON cannot encode. Dropping it
	 * must preserve the resource body and report the lost metadata to the author.
	 */
	public function test_read_resource_with_non_encodable_meta_drops_it_and_logs(): void {
		$result = $this->read_resource_returning(
			array(
				array(
					'uri'   => 'WordPress://local/resource-1',
					'text'  => 'body',
					'_meta' => array( 'score' => NAN ),
				),
			)
		);

		$this->assertInstanceOf( ReadResourceResult::class, $result );

		$contents = $result->getContents();
		$this->assertInstanceOf( TextResourceContents::class, $contents[0] );
		$this->assertSame( 'body', $contents[0]->getText() );
		$this->assertNull( $contents[0]->get_meta() );

		$messages = array_column( DummyErrorHandler::$logs, 'message' );
		$this->assertContains( 'Invalid _meta on resource contents, dropping it', $messages );
	}

	/**
	 * Read the test resource after replacing its handler result with an arbitrary shape.
	 *
	 * @param mixed $shape Resource handler result to return.
	 *
	 * @return \WP\McpSchema\Server\Resources\DTO\ReadResourceResult|\WP\McpSchema\Common\JsonRpc\DTO\JSONRPCErrorResponse
	 */
	private function read_resource_returning( $shape ) {
		wp_set_current_user( 1 );
		$server  = $this->makeServer( array(), array( 'test/resource' ) );
		$handler = new ResourcesHandler( $server );

		$filter = static function () use ( $shape ) {
			return $shape;
		};
		add_filter( 'mcp_adapter_resource_read_result', $filter );

		try {
			return $handler->read_resource(
				array( 'params' => array( 'uri' => 'WordPress://local/resource-1' ) )
			);
		} finally {
			remove_filter( 'mcp_adapter_resource_read_result', $filter );
		}
	}

}
