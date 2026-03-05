<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Handlers;

use WP\MCP\Handlers\Resources\ResourcesHandler;
use WP\MCP\Tests\TestCase;
use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;
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

	public function test_non_string_uri_returns_invalid_params_error(): void {
		wp_set_current_user( 1 );
		$server  = $this->makeServer( array(), array( 'test/resource' ) );
		$handler = new ResourcesHandler( $server );

		$result = $handler->read_resource( array( 'params' => array( 'uri' => 123 ) ), 'req-1' );

		$this->assertInstanceOf( JSONRPCErrorResponse::class, $result );
		$error = $result->getError();
		$this->assertNotNull( $error );
		$this->assertSame( McpErrorFactory::INVALID_PARAMS, $error->getCode() );
	}

	public function test_array_uri_returns_invalid_params_error(): void {
		wp_set_current_user( 1 );
		$server  = $this->makeServer( array(), array( 'test/resource' ) );
		$handler = new ResourcesHandler( $server );

		$result = $handler->read_resource( array( 'params' => array( 'uri' => array( 'not', 'a', 'string' ) ) ), 'req-2' );

		$this->assertInstanceOf( JSONRPCErrorResponse::class, $result );
		$error = $result->getError();
		$this->assertNotNull( $error );
		$this->assertSame( McpErrorFactory::INVALID_PARAMS, $error->getCode() );
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

	public function test_read_resource_handles_mixed_content_array_without_error(): void {
		wp_set_current_user( 1 );

		// Register an ability that returns a mixed array (first item is array, second is string).
		$this->register_ability_in_hook(
			'test/resource-mixed-contents',
			array(
				'label'               => 'Resource Mixed Contents',
				'description'         => 'Returns mixed array contents',
				'category'            => 'test',
				'execute_callback'    => static function () {
					return array(
						array( 'uri' => 'WordPress://local/part1', 'text' => 'First part' ),
						'not-an-array',
					);
				},
				'permission_callback' => static function () {
					return true;
				},
				'meta'                => array(
					'mcp' => array(
						'public' => true,
						'type'   => 'resource',
						'uri'    => 'WordPress://local/resource-mixed-contents',
					),
				),
			)
		);

		$server  = $this->makeServer( array(), array( 'test/resource-mixed-contents' ) );
		$handler = new ResourcesHandler( $server );

		$result = $handler->read_resource(
			array( 'params' => array( 'uri' => 'WordPress://local/resource-mixed-contents' ) )
		);

		// Should not throw TypeError - non-array items are filtered out.
		$this->assertInstanceOf( ReadResourceResult::class, $result );

		$contents = $result->getContents();
		$this->assertCount( 1, $contents, 'Should only have 1 content item (non-array filtered out)' );
		$this->assertInstanceOf( TextResourceContents::class, $contents[0] );
		$this->assertSame( 'First part', $contents[0]->getText() );

		// Clean up.
		wp_unregister_ability( 'test/resource-mixed-contents' );
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
					return array( 'status' => 'ok', 'count' => 42 );
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
}
