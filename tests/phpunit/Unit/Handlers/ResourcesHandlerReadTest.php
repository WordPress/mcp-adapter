<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Handlers;

use WP\MCP\Handlers\Resources\ResourcesHandler;
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
}
