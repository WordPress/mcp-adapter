<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Handlers;

use WP\MCP\Handlers\Tools\ToolsHandler;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\TestCase;
use WP\McpSchema\Common\Content\DTO\ImageContent;
use WP\McpSchema\Common\Content\DTO\TextContent;
use WP\McpSchema\Common\JsonRpc\DTO\JSONRPCErrorResponse;
use WP\McpSchema\Common\Protocol\DTO\Annotations;
use WP\McpSchema\Common\Protocol\DTO\BlobResourceContents;
use WP\McpSchema\Common\Protocol\DTO\EmbeddedResource;
use WP\McpSchema\Common\Protocol\DTO\TextResourceContents;
use WP\McpSchema\Server\Tools\DTO\CallToolResult;

final class ToolsHandlerCallTest extends TestCase {

	public function test_missing_name_returns_missing_parameter_error(): void {
		$server  = $this->makeServer( array( 'test/always-allowed' ) );
		$handler = new ToolsHandler( $server );
		$result  = $handler->call_tool( array( 'params' => array( 'arguments' => array() ) ) );

		// Missing name is a protocol error - returns JSONRPCErrorResponse
		$this->assertInstanceOf( JSONRPCErrorResponse::class, $result );
		// Use DTO getter methods instead of toArray()
		$error = $result->getError();
		$this->assertNotNull( $error );
		$this->assertNotEmpty( $error->getCode() );
		$this->assertNotEmpty( $error->getMessage() );
	}

	public function test_unknown_tool_logs_and_returns_error(): void {
		$server  = $this->makeServer( array( 'test/always-allowed' ) );
		$handler = new ToolsHandler( $server );
		$result  = $handler->call_tool( array( 'params' => array( 'name' => 'nope' ) ) );

		// Tool not found is a protocol error - returns JSONRPCErrorResponse
		$this->assertInstanceOf( JSONRPCErrorResponse::class, $result );
		// Use DTO getter methods instead of toArray()
		$error = $result->getError();
		$this->assertNotNull( $error );
		$this->assertNotEmpty( $error->getMessage() );
		$this->assertNotEmpty( DummyErrorHandler::$logs );
	}

	public function test_permission_denied_returns_error(): void {
		$server  = $this->makeServer( array( 'test/permission-denied' ) );
		$handler = new ToolsHandler( $server );
		$result  = $handler->call_tool(
			array(
				'params' => array( 'name' => 'test-permission-denied' ),
			)
		);

		// Permission denied is a tool execution error - returns CallToolResult with isError
		$this->assertInstanceOf( CallToolResult::class, $result );
		// Use DTO getter methods instead of toArray()
		$this->assertTrue( $result->getIsError() );
		$content = $result->getContent();
		$this->assertNotEmpty( $content );
		$this->assertInstanceOf( TextContent::class, $content[0] );
		$this->assertStringContainsString( 'Permission denied', $content[0]->getText() );
	}

	public function test_permission_exception_logs_and_returns_error(): void {
		$server  = $this->makeServer( array( 'test/permission-exception' ) );
		$handler = new ToolsHandler( $server );
		$result  = $handler->call_tool(
			array(
				'params' => array( 'name' => 'test-permission-exception' ),
			)
		);

		// Permission check exception is a tool execution error - returns CallToolResult with isError
		$this->assertInstanceOf( CallToolResult::class, $result );
		// Use DTO getter methods instead of toArray()
		$this->assertTrue( $result->getIsError() );
		$content = $result->getContent();
		$this->assertNotEmpty( $content );
		$this->assertInstanceOf( TextContent::class, $content[0] );
		$this->assertNotEmpty( DummyErrorHandler::$logs );
	}

	public function test_execute_exception_logs_and_returns_internal_error_envelope(): void {
		$server  = $this->makeServer( array( 'test/execute-exception' ) );
		$handler = new ToolsHandler( $server );
		$result  = $handler->call_tool(
			array(
				'params' => array( 'name' => 'test-execute-exception' ),
			)
		);

		// Execute exceptions are tool execution errors - returns CallToolResult with isError
		$this->assertInstanceOf( CallToolResult::class, $result );
		// Use DTO getter methods instead of toArray()
		$this->assertTrue( $result->getIsError() );
		$content = $result->getContent();
		$this->assertNotEmpty( $content );
		$this->assertInstanceOf( TextContent::class, $content[0] );
		$this->assertEquals( 'text', $content[0]->getType() );
		$this->assertNotEmpty( DummyErrorHandler::$logs );
	}

	public function test_image_result_is_converted_to_base64_with_mime_type(): void {
		$server  = $this->makeServer( array( 'test/image' ) );
		$handler = new ToolsHandler( $server );
		$result  = $handler->call_tool(
			array(
				'params' => array( 'name' => 'test-image' ),
			)
		);

		// Successful image result returns CallToolResult
		$this->assertInstanceOf( CallToolResult::class, $result );
		// Use DTO getter methods instead of toArray()
		$content = $result->getContent();
		$this->assertNotEmpty( $content, 'Content array should not be empty' );
		$this->assertInstanceOf( ImageContent::class, $content[0] );
		$this->assertSame( 'image', $content[0]->getType() );
		$this->assertNotEmpty( $content[0]->getData() );
		$this->assertNotEmpty( $content[0]->getMimeType() );
	}

	public function test_embedded_text_resource_result_is_converted_to_embedded_resource_content_block(): void {
		$server  = $this->makeServer( array( 'test/embedded-text-resource' ) );
		$handler = new ToolsHandler( $server );
		$result  = $handler->call_tool(
			array(
				'params' => array( 'name' => 'test-embedded-text-resource' ),
			)
		);

		$this->assertInstanceOf( CallToolResult::class, $result );
		$content = $result->getContent();
		$this->assertNotEmpty( $content, 'Content array should not be empty' );

		$this->assertInstanceOf( EmbeddedResource::class, $content[0] );
		$this->assertSame( 'resource', $content[0]->getType() );

		$resource = $content[0]->getResource();
		$this->assertInstanceOf( TextResourceContents::class, $resource );
		$this->assertSame( 'WordPress://local/tool-embedded-text', $resource->getUri() );
		$this->assertSame( 'text/plain', $resource->getMimeType() );
		$this->assertSame( 'hello from embedded resource', $resource->getText() );
	}

	public function test_embedded_blob_resource_result_is_converted_to_embedded_resource_content_block(): void {
		$server  = $this->makeServer( array( 'test/embedded-blob-resource' ) );
		$handler = new ToolsHandler( $server );
		$result  = $handler->call_tool(
			array(
				'params' => array( 'name' => 'test-embedded-blob-resource' ),
			)
		);

		$this->assertInstanceOf( CallToolResult::class, $result );
		$content = $result->getContent();
		$this->assertNotEmpty( $content, 'Content array should not be empty' );

		$this->assertInstanceOf( EmbeddedResource::class, $content[0] );
		$this->assertSame( 'resource', $content[0]->getType() );

		$resource = $content[0]->getResource();
		$this->assertInstanceOf( BlobResourceContents::class, $resource );
		$this->assertSame( 'WordPress://local/tool-embedded-blob', $resource->getUri() );
		$this->assertSame( 'application/octet-stream', $resource->getMimeType() );
		$this->assertSame( base64_encode( 'blob-bytes' ), $resource->getBlob() ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	public function test_pre_tool_call_filter_can_modify_arguments(): void {
		$server  = $this->makeServer( array( 'test/always-allowed' ) );
		$handler = new ToolsHandler( $server );

		$received_args = null;
		$filter        = static function ( array $args, string $tool_name ) use ( &$received_args ): array {
			$received_args              = $args;
			$args['injected_by_filter'] = true;

			return $args;
		};
		add_filter( 'mcp_adapter_pre_tool_call', $filter, 10, 2 );

		$handler->call_tool(
			array(
				'params' => array(
					'name'      => 'test-always-allowed',
					'arguments' => array( 'key' => 'value' ),
				),
			)
		);

		$this->assertIsArray( $received_args );
		$this->assertSame( 'value', $received_args['key'] );

		remove_filter( 'mcp_adapter_pre_tool_call', $filter );
	}

	public function test_pre_tool_call_filter_can_short_circuit_with_wp_error(): void {
		$server  = $this->makeServer( array( 'test/always-allowed' ) );
		$handler = new ToolsHandler( $server );

		$filter = static function () {
			return new \WP_Error( 'blocked', 'Rate limit exceeded' );
		};
		add_filter( 'mcp_adapter_pre_tool_call', $filter );

		$result = $handler->call_tool(
			array(
				'params' => array(
					'name'      => 'test-always-allowed',
					'arguments' => array(),
				),
			)
		);

		// Short-circuit returns CallToolResult with isError=true.
		$this->assertInstanceOf( CallToolResult::class, $result );
		$this->assertTrue( $result->getIsError() );
		$content = $result->getContent();
		$this->assertNotEmpty( $content );
		$this->assertStringContainsString( 'Rate limit exceeded', $content[0]->getText() );

		remove_filter( 'mcp_adapter_pre_tool_call', $filter );
	}

	public function test_tool_call_result_filter_can_modify_result(): void {
		$server  = $this->makeServer( array( 'test/always-allowed' ) );
		$handler = new ToolsHandler( $server );

		$filter = static function ( $result ) {
			if ( is_array( $result ) ) {
				$result['filtered'] = true;
			}

			return $result;
		};
		add_filter( 'mcp_adapter_tool_call_result', $filter );

		$result = $handler->call_tool(
			array(
				'params' => array(
					'name'      => 'test-always-allowed',
					'arguments' => array(),
				),
			)
		);

		// The result filter modifies the raw result before DTO assembly.
		$this->assertInstanceOf( CallToolResult::class, $result );
		$structured = $result->getStructuredContent();
		$this->assertNotNull( $structured );
		$this->assertTrue( $structured['filtered'] );

		remove_filter( 'mcp_adapter_tool_call_result', $filter );
	}

	public function test_tool_call_preserves_meta_in_text_and_structured_content(): void {
		$server  = $this->makeServer( array( 'test/meta-leak' ) );
		$handler = new ToolsHandler( $server );
		$result  = $handler->call_tool(
			array(
				'params' => array(
					'name'      => 'test-meta-leak',
					'arguments' => array(),
				),
			),
			1
		);

		$this->assertInstanceOf( CallToolResult::class, $result );
		$this->assertFalse( (bool) $result->getIsError() );

		$content = $result->getContent();
		$this->assertNotEmpty( $content );
		$this->assertInstanceOf( TextContent::class, $content[0] );

			$text = $content[0]->getText();
			$this->assertStringContainsString( 'mcp_adapter', $text );

			$decoded = json_decode( $text, true );
			$this->assertIsArray( $decoded );
			$this->assertArrayHasKey( '_meta', $decoded );
			$this->assertArrayHasKey( 'mcp_adapter', $decoded['_meta'] );
			$this->assertSame( 'top', $decoded['_meta']['keep'] );
			$this->assertSame( 'nested', $decoded['nested']['_meta']['keep'] );
			$this->assertArrayHasKey( 'mcp_adapter', $decoded['nested']['_meta'] );

			$structured = $result->getStructuredContent();
			$this->assertIsArray( $structured );
			$this->assertArrayHasKey( '_meta', $structured );
			$this->assertArrayHasKey( 'mcp_adapter', $structured['_meta'] );
			$this->assertArrayHasKey( 'mcp_adapter', $structured['nested']['_meta'] );
	}

	public function test_call_tool_with_string_arguments_returns_invalid_params_error(): void {
		$server  = $this->makeServer( array( 'test/always-allowed' ) );
		$handler = new ToolsHandler( $server );
		$result  = $handler->call_tool(
			array(
				'params' => array(
					'name'      => 'test-always-allowed',
					'arguments' => 'invalid',
				),
			),
			1
		);

		$this->assertInstanceOf( JSONRPCErrorResponse::class, $result );
		$error = $result->getError();
		$this->assertNotNull( $error );
		$this->assertSame( -32602, $error->getCode() );
		$this->assertStringContainsString( 'arguments must be an object', $error->getMessage() );
	}

	public function test_call_tool_with_integer_arguments_returns_invalid_params_error(): void {
		$server  = $this->makeServer( array( 'test/always-allowed' ) );
		$handler = new ToolsHandler( $server );
		$result  = $handler->call_tool(
			array(
				'params' => array(
					'name'      => 'test-always-allowed',
					'arguments' => 42,
				),
			),
			1
		);

		$this->assertInstanceOf( JSONRPCErrorResponse::class, $result );
		$error = $result->getError();
		$this->assertNotNull( $error );
		$this->assertSame( -32602, $error->getCode() );
		$this->assertStringContainsString( 'arguments must be an object', $error->getMessage() );
	}

	public function test_call_tool_with_null_arguments_succeeds(): void {
		$server  = $this->makeServer( array( 'test/always-allowed' ) );
		$handler = new ToolsHandler( $server );
		$result  = $handler->call_tool(
			array(
				'params' => array(
					'name'      => 'test-always-allowed',
					'arguments' => null,
				),
			),
			1
		);

		// null arguments should default to empty array and succeed.
		$this->assertInstanceOf( CallToolResult::class, $result );
		$this->assertFalse( (bool) $result->getIsError() );
	}

	public function test_call_tool_with_missing_arguments_succeeds(): void {
		$server  = $this->makeServer( array( 'test/always-allowed' ) );
		$handler = new ToolsHandler( $server );
		$result  = $handler->call_tool(
			array(
				'params' => array(
					'name' => 'test-always-allowed',
				),
			),
			1
		);

		// Missing arguments should default to empty array and succeed.
		$this->assertInstanceOf( CallToolResult::class, $result );
		$this->assertFalse( (bool) $result->getIsError() );
	}

	/**
	 * Runs a tool whose raw result is replaced by the given embedded-resource shape.
	 *
	 * @param array $shape The embedded resource result to substitute.
	 *
	 * @return \WP\McpSchema\Server\Tools\DTO\CallToolResult|\WP\McpSchema\Common\JsonRpc\DTO\JSONRPCErrorResponse
	 */
	private function call_tool_returning( array $shape ) {
		$server  = $this->makeServer( array( 'test/always-allowed' ) );
		$handler = new ToolsHandler( $server );

		$filter = static function () use ( $shape ) {
			return $shape;
		};
		add_filter( 'mcp_adapter_tool_call_result', $filter );

		$result = $handler->call_tool(
			array(
				'params' => array(
					'name'      => 'test-always-allowed',
					'arguments' => array(),
				),
			),
			1
		);

		remove_filter( 'mcp_adapter_tool_call_result', $filter );

		return $result;
	}

	public function test_embedded_resource_nested_shape_preserves_meta_on_both_levels(): void {
		$result = $this->call_tool_returning(
			array(
				'type'        => 'resource',
				'annotations' => array( 'audience' => array( 'user' ) ),
				'_meta'       => array( 'block' => 'level' ),
				'resource'    => array(
					'uri'      => 'ui://example/app',
					'mimeType' => 'text/html;profile=mcp-app',
					'text'     => '<!doctype html>',
					'_meta'    => array( 'ui' => array( 'prefersBorder' => true ) ),
				),
			)
		);

		$this->assertInstanceOf( CallToolResult::class, $result );

		$content = $result->getContent();
		$this->assertInstanceOf( EmbeddedResource::class, $content[0] );

		// Outer keys belong to the content block.
		$this->assertSame( array( 'block' => 'level' ), $content[0]->get_meta() );
		$this->assertNotNull( $content[0]->getAnnotations() );
		$this->assertSame( array( 'user' ), $content[0]->getAnnotations()->getAudience() );

		// The nested _meta belongs to the resource contents.
		$resource = $content[0]->getResource();
		$this->assertInstanceOf( TextResourceContents::class, $resource );
		$this->assertSame( array( 'ui' => array( 'prefersBorder' => true ) ), $resource->get_meta() );
	}

	public function test_embedded_resource_nested_blob_shape_preserves_meta_on_both_levels(): void {
		$result = $this->call_tool_returning(
			array(
				'type'     => 'resource',
				'_meta'    => array( 'block' => 'level' ),
				'resource' => array(
					'uri'      => 'WordPress://local/tool-embedded-blob',
					'mimeType' => 'application/pdf',
					'blob'     => 'ZGF0YQ==',
					'_meta'    => array( 'pages' => 3 ),
				),
			)
		);

		$this->assertInstanceOf( CallToolResult::class, $result );

		$content = $result->getContent();
		$this->assertInstanceOf( EmbeddedResource::class, $content[0] );
		$this->assertSame( array( 'block' => 'level' ), $content[0]->get_meta() );

		$resource = $content[0]->getResource();
		$this->assertInstanceOf( BlobResourceContents::class, $resource );
		$this->assertSame( array( 'pages' => 3 ), $resource->get_meta() );
	}

	public function test_embedded_resource_flat_shape_assigns_meta_to_the_resource_contents(): void {
		$result = $this->call_tool_returning(
			array(
				'type'     => 'resource',
				'uri'      => 'ui://example/app',
				'mimeType' => 'text/html;profile=mcp-app',
				'text'     => '<!doctype html>',
				'_meta'    => array( 'ui' => array( 'prefersBorder' => true ) ),
			)
		);

		$this->assertInstanceOf( CallToolResult::class, $result );

		$content = $result->getContent();
		$this->assertInstanceOf( EmbeddedResource::class, $content[0] );

		// Strip `type` and the flat shape is a ResourceContents literal, so its `_meta`
		// describes the resource. The block carries none; the nested form is how a caller
		// addresses the block level.
		$this->assertNull( $content[0]->get_meta() );
		$this->assertSame( array( 'ui' => array( 'prefersBorder' => true ) ), $content[0]->getResource()->get_meta() );
	}

	public function test_embedded_resource_flat_blob_shape_assigns_meta_to_the_resource_contents(): void {
		$result = $this->call_tool_returning(
			array(
				'type'     => 'resource',
				'uri'      => 'WordPress://local/tool-embedded-blob',
				'mimeType' => 'application/pdf',
				'blob'     => 'ZGF0YQ==',
				'_meta'    => array( 'pages' => 3 ),
			)
		);

		$this->assertInstanceOf( CallToolResult::class, $result );

		$content = $result->getContent();
		$this->assertInstanceOf( EmbeddedResource::class, $content[0] );

		$this->assertNull( $content[0]->get_meta() );
		$this->assertSame( array( 'pages' => 3 ), $content[0]->getResource()->get_meta() );
	}

	/**
	 * `annotations` has no ResourceContents field to descend into, so it stays on the
	 * block while `_meta` moves. Pins that only `_meta` follows the flat form's siblings.
	 */
	public function test_embedded_resource_flat_shape_keeps_annotations_on_the_content_block(): void {
		$result = $this->call_tool_returning(
			array(
				'type'        => 'resource',
				'uri'         => 'ui://example/app',
				'text'        => '<!doctype html>',
				'annotations' => array( 'audience' => array( 'user' ) ),
				'_meta'       => array( 'ui' => array( 'prefersBorder' => true ) ),
			)
		);

		$this->assertInstanceOf( CallToolResult::class, $result );

		$content = $result->getContent();
		$this->assertInstanceOf( EmbeddedResource::class, $content[0] );

		$this->assertNotNull( $content[0]->getAnnotations() );
		$this->assertSame( array( 'user' ), $content[0]->getAnnotations()->getAudience() );
		$this->assertNull( $content[0]->get_meta() );
		$this->assertSame( array( 'ui' => array( 'prefersBorder' => true ) ), $content[0]->getResource()->get_meta() );
	}

	public function test_embedded_resource_with_invalid_annotations_still_returns_result(): void {
		$result = $this->call_tool_returning(
			array(
				'type'        => 'resource',
				'uri'         => 'WordPress://local/tool-embedded-text',
				'text'        => 'body',
				'annotations' => array( 'audience' => 'not-an-array' ),
			)
		);

		// Malformed annotations are dropped, not raised: the resource still reaches the client.
		$this->assertInstanceOf( CallToolResult::class, $result );

		$content = $result->getContent();
		$this->assertInstanceOf( EmbeddedResource::class, $content[0] );
		$this->assertNull( $content[0]->getAnnotations() );
		$this->assertSame( 'body', $content[0]->getResource()->getText() );
	}

	public function test_embedded_resource_without_meta_leaves_both_levels_null(): void {
		$result = $this->call_tool_returning(
			array(
				'type' => 'resource',
				'uri'  => 'WordPress://local/tool-embedded-text',
				'text' => 'body',
			)
		);

		$this->assertInstanceOf( CallToolResult::class, $result );

		$content = $result->getContent();
		$this->assertInstanceOf( EmbeddedResource::class, $content[0] );
		$this->assertNull( $content[0]->get_meta() );
		$this->assertNull( $content[0]->getResource()->get_meta() );
	}

	/**
	 * Tool-result annotations are content annotations (audience, priority, lastModified),
	 * not the ToolAnnotations vocabulary the guide documents for tool descriptors. A tool
	 * reusing the descriptor vocabulary here must not put an empty `annotations` on the
	 * wire: PHP serializes an empty array as `[]`, and MCP declares annotations an object.
	 *
	 * Asserted on the emitted array rather than getAnnotations(), because the DTO getter
	 * returns a perfectly good all-null object and cannot see the defect.
	 */
	public function test_embedded_resource_with_tool_annotation_vocabulary_omits_annotations(): void {
		$result = $this->call_tool_returning(
			array(
				'type'        => 'resource',
				'uri'         => 'WordPress://local/tool-embedded-text',
				'text'        => 'body',
				'annotations' => array(
					'readOnlyHint'  => true,
					'openWorldHint' => false,
				),
			)
		);

		$this->assertInstanceOf( CallToolResult::class, $result );

		$block = $result->getContent()[0]->toArray();
		$this->assertArrayNotHasKey( 'annotations', $block );
		$this->assertStringNotContainsString( '"annotations":[]', (string) wp_json_encode( $block ) );
	}

	/**
	 * MCP constrains priority to 0.0-1.0. An out-of-range value is rejected by conforming
	 * clients along with the whole content block, so it must not reach the wire.
	 */
	public function test_embedded_resource_with_out_of_range_priority_omits_annotations(): void {
		$result = $this->call_tool_returning(
			array(
				'type'        => 'resource',
				'uri'         => 'WordPress://local/tool-embedded-text',
				'text'        => 'body',
				'annotations' => array( 'priority' => 5 ),
			)
		);

		$this->assertInstanceOf( CallToolResult::class, $result );

		$block = $result->getContent()[0]->toArray();
		$this->assertArrayNotHasKey( 'annotations', $block );
		$this->assertSame( 'body', $result->getContent()[0]->getResource()->getText() );
	}

	/**
	 * MCP declares audience as a list of "user" or "assistant". Anything else is rejected
	 * by conforming clients along with the whole content block.
	 */
	public function test_embedded_resource_with_unknown_audience_role_omits_annotations(): void {
		$result = $this->call_tool_returning(
			array(
				'type'        => 'resource',
				'uri'         => 'WordPress://local/tool-embedded-text',
				'text'        => 'body',
				'annotations' => array( 'audience' => array( 'robot' ) ),
			)
		);

		$this->assertInstanceOf( CallToolResult::class, $result );
		$this->assertArrayNotHasKey( 'annotations', $result->getContent()[0]->toArray() );
	}

	/**
	 * A non-string audience entry must be rejected by validation, before it reaches the
	 * schema DTO. The DTO casts entries to string, which raises a PHP warning rather than
	 * throwing, so in production execution continues and the literal "Array" goes on the
	 * wire.
	 *
	 * The emitted shape alone cannot prove this: phpunit.xml.dist sets
	 * convertWarningsToExceptions, so under test the cast throws and the catch below drops
	 * the annotations anyway. Both the fixed and the unfixed code emit no annotations here.
	 * What distinguishes them is which path dropped it, so this asserts the log context
	 * carries validation errors and not a downstream exception.
	 */
	public function test_embedded_resource_with_non_string_audience_entry_is_rejected_by_validation(): void {
		$result = $this->call_tool_returning(
			array(
				'type'        => 'resource',
				'uri'         => 'WordPress://local/tool-embedded-text',
				'text'        => 'body',
				'annotations' => array( 'audience' => array( array( 'nested' ) ) ),
			)
		);

		$this->assertInstanceOf( CallToolResult::class, $result );

		$block = $result->getContent()[0]->toArray();
		$this->assertArrayNotHasKey( 'annotations', $block );
		$this->assertStringNotContainsString( 'Array', (string) wp_json_encode( $block ) );

		$dropped = array_values(
			array_filter(
				DummyErrorHandler::$logs,
				static function ( array $entry ): bool {
					return 'Invalid annotations in tool result, dropping them' === $entry['message'];
				}
			)
		);

		$this->assertCount( 1, $dropped );
		$this->assertArrayHasKey( 'errors', $dropped[0]['context'] );
		$this->assertArrayNotHasKey( 'exception', $dropped[0]['context'] );
	}

	/**
	 * The guard above must not over-filter: every field MCP's content Annotations models
	 * still reaches the wire, as a JSON object.
	 */
	public function test_embedded_resource_with_valid_annotations_emits_them_as_an_object(): void {
		$result = $this->call_tool_returning(
			array(
				'type'        => 'resource',
				'uri'         => 'WordPress://local/tool-embedded-text',
				'text'        => 'body',
				'annotations' => array(
					'audience'     => array( 'user', 'assistant' ),
					'priority'     => 0.8,
					'lastModified' => '2025-01-12T15:00:58Z',
				),
			)
		);

		$this->assertInstanceOf( CallToolResult::class, $result );

		$block = $result->getContent()[0]->toArray();
		$this->assertSame(
			array(
				'audience'     => array( 'user', 'assistant' ),
				'priority'     => 0.8,
				'lastModified' => '2025-01-12T15:00:58Z',
			),
			$block['annotations']
		);
		$this->assertStringContainsString( '"annotations":{"audience":', (string) wp_json_encode( $block ) );
	}

	/**
	 * WordPress hands back numeric values as strings all over the place - get_post_meta()
	 * and get_option() both do - so a tool computing priority from stored data commonly
	 * returns "0.5" rather than 0.5. That is a valid priority, and it must reach the wire
	 * as a JSON number rather than costing the tool its annotations.
	 */
	public function test_embedded_resource_with_numeric_string_priority_emits_it_as_a_number(): void {
		$result = $this->call_tool_returning(
			array(
				'type'        => 'resource',
				'uri'         => 'WordPress://local/tool-embedded-text',
				'text'        => 'body',
				'annotations' => array( 'priority' => '0.5' ),
			)
		);

		$this->assertInstanceOf( CallToolResult::class, $result );

		$block = $result->getContent()[0]->toArray();
		$this->assertSame( array( 'priority' => 0.5 ), $block['annotations'] );
		$this->assertStringContainsString( '"priority":0.5', (string) wp_json_encode( $block ) );

		$messages = array_column( DummyErrorHandler::$logs, 'message' );
		$this->assertNotContains( 'Invalid annotations in tool result, dropping them', $messages );
	}

	/**
	 * Without a URI the result is not an embedded resource at all, so it falls through to
	 * the generic JSON path where no annotations were ever going to be attached. Warning
	 * that annotations were "dropped" there sends the reader after the wrong problem.
	 */
	public function test_resource_result_without_uri_does_not_warn_about_annotations(): void {
		$result = $this->call_tool_returning(
			array(
				'type'        => 'resource',
				'text'        => 'body',
				'annotations' => array( 'priority' => 5 ),
			)
		);

		$this->assertInstanceOf( CallToolResult::class, $result );
		$this->assertInstanceOf( TextContent::class, $result->getContent()[0] );

		$messages = array_column( DummyErrorHandler::$logs, 'message' );
		$this->assertNotContains( 'Invalid annotations in tool result, dropping them', $messages );
	}

	/**
	 * A result filter may hand back an already-built DTO, which is passed through as-is
	 * rather than re-validated.
	 */
	public function test_embedded_resource_accepts_an_already_built_annotations_dto(): void {
		$result = $this->call_tool_returning(
			array(
				'type'        => 'resource',
				'uri'         => 'WordPress://local/tool-embedded-text',
				'text'        => 'body',
				'annotations' => new Annotations( array( 'assistant' ), 0.4 ),
			)
		);

		$this->assertInstanceOf( CallToolResult::class, $result );

		$block = $result->getContent()[0]->toArray();
		$this->assertSame(
			array(
				'audience' => array( 'assistant' ),
				'priority' => 0.4,
			),
			$block['annotations']
		);
	}
}
