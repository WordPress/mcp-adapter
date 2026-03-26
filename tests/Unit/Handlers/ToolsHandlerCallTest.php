<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Handlers;

use WP\MCP\Handlers\Tools\ToolsHandler;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\TestCase;
use WP\McpSchema\Common\Content\DTO\ImageContent;
use WP\McpSchema\Common\Content\DTO\TextContent;
use WP\McpSchema\Common\JsonRpc\DTO\JSONRPCErrorResponse;
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
			$received_args = $args;
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
}
