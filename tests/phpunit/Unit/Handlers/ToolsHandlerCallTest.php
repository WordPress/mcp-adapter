<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Handlers;

use WP\MCP\Handlers\Tools\ToolsHandler;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\TestCase;

final class ToolsHandlerCallTest extends TestCase {

	public function test_missing_name_returns_missing_parameter_error(): void {
		$server  = $this->makeServer( array( 'test/always-allowed' ) );
		$handler = new ToolsHandler( $server );
		$result  = $handler->call_tool( array( 'params' => array( 'arguments' => array() ) ) );

		// Missing name is a protocol error - returns a JSON-RPC error envelope.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'error', $result );
		$error = $result['error'];
		$this->assertNotEmpty( $error['code'] );
		$this->assertNotEmpty( $error['message'] );
	}

	public function test_unknown_tool_logs_and_returns_error(): void {
		$server  = $this->makeServer( array( 'test/always-allowed' ) );
		$handler = new ToolsHandler( $server );
		$result  = $handler->call_tool( array( 'params' => array( 'name' => 'nope' ) ) );

		// Tool not found is a protocol error - returns a JSON-RPC error envelope.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'error', $result );
		$error = $result['error'];
		$this->assertNotEmpty( $error['message'] );
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

		// Permission denied is a tool execution error - returns a tool-call result with isError.
		$this->assertIsArray( $result );
		$this->assertTrue( $result['isError'] );
		$content = $result['content'];
		$this->assertNotEmpty( $content );
		$this->assertIsArray( $content[0] );
		$this->assertSame( 'text', $content[0]['type'] );
		$this->assertStringContainsString( 'Permission denied', $content[0]['text'] );
	}

	public function test_permission_exception_logs_and_returns_error(): void {
		$server  = $this->makeServer( array( 'test/permission-exception' ) );
		$handler = new ToolsHandler( $server );
		$result  = $handler->call_tool(
			array(
				'params' => array( 'name' => 'test-permission-exception' ),
			)
		);

		// Permission check exception is a tool execution error - returns a tool-call result with isError.
		$this->assertIsArray( $result );
		$this->assertTrue( $result['isError'] );
		$content = $result['content'];
		$this->assertNotEmpty( $content );
		$this->assertIsArray( $content[0] );
		$this->assertSame( 'text', $content[0]['type'] );
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

		// Execute exceptions are tool execution errors - returns a tool-call result with isError.
		$this->assertIsArray( $result );
		$this->assertTrue( $result['isError'] );
		$content = $result['content'];
		$this->assertNotEmpty( $content );
		$this->assertIsArray( $content[0] );
		$this->assertEquals( 'text', $content[0]['type'] );
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

		// Successful image result returns a tool-call result.
		$this->assertIsArray( $result );
		$content = $result['content'];
		$this->assertNotEmpty( $content, 'Content array should not be empty' );
		$this->assertIsArray( $content[0] );
		$this->assertSame( 'image', $content[0]['type'] );
		$this->assertNotEmpty( $content[0]['data'] );
		$this->assertNotEmpty( $content[0]['mimeType'] );
	}

	public function test_embedded_text_resource_result_is_converted_to_embedded_resource_content_block(): void {
		$server  = $this->makeServer( array( 'test/embedded-text-resource' ) );
		$handler = new ToolsHandler( $server );
		$result  = $handler->call_tool(
			array(
				'params' => array( 'name' => 'test-embedded-text-resource' ),
			)
		);

		$this->assertIsArray( $result );
		$content = $result['content'];
		$this->assertNotEmpty( $content, 'Content array should not be empty' );

		$this->assertIsArray( $content[0] );
		$this->assertSame( 'resource', $content[0]['type'] );

		$resource = $content[0]['resource'];
		$this->assertIsArray( $resource );
		$this->assertSame( 'WordPress://local/tool-embedded-text', $resource['uri'] );
		$this->assertSame( 'text/plain', $resource['mimeType'] );
		$this->assertSame( 'hello from embedded resource', $resource['text'] );
	}

	public function test_embedded_blob_resource_result_is_converted_to_embedded_resource_content_block(): void {
		$server  = $this->makeServer( array( 'test/embedded-blob-resource' ) );
		$handler = new ToolsHandler( $server );
		$result  = $handler->call_tool(
			array(
				'params' => array( 'name' => 'test-embedded-blob-resource' ),
			)
		);

		$this->assertIsArray( $result );
		$content = $result['content'];
		$this->assertNotEmpty( $content, 'Content array should not be empty' );

		$this->assertIsArray( $content[0] );
		$this->assertSame( 'resource', $content[0]['type'] );

		$resource = $content[0]['resource'];
		$this->assertIsArray( $resource );
		$this->assertSame( 'WordPress://local/tool-embedded-blob', $resource['uri'] );
		$this->assertSame( 'application/octet-stream', $resource['mimeType'] );
		$this->assertSame( base64_encode( 'blob-bytes' ), $resource['blob'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
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

		// Short-circuit returns a tool-call result with isError=true.
		$this->assertIsArray( $result );
		$this->assertTrue( $result['isError'] );
		$content = $result['content'];
		$this->assertNotEmpty( $content );
		$this->assertStringContainsString( 'Rate limit exceeded', $content[0]['text'] );

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

		// The result filter modifies the raw result before response assembly.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'structuredContent', $result );
		$structured = $result['structuredContent'];
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

		$this->assertIsArray( $result );
		$this->assertFalse( (bool) $result['isError'] );

		$content = $result['content'];
		$this->assertNotEmpty( $content );
		$this->assertIsArray( $content[0] );
		$this->assertSame( 'text', $content[0]['type'] );

		$text = $content[0]['text'];
		$this->assertStringContainsString( 'mcp_adapter', $text );

		$decoded = json_decode( $text, true );
		$this->assertIsArray( $decoded );
		$this->assertArrayHasKey( '_meta', $decoded );
		$this->assertArrayHasKey( 'mcp_adapter', $decoded['_meta'] );
		$this->assertSame( 'top', $decoded['_meta']['keep'] );
		$this->assertSame( 'nested', $decoded['nested']['_meta']['keep'] );
		$this->assertArrayHasKey( 'mcp_adapter', $decoded['nested']['_meta'] );

		$this->assertArrayHasKey( 'structuredContent', $result );
		$structured = $result['structuredContent'];
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

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'error', $result );
		$error = $result['error'];
		$this->assertSame( -32602, $error['code'] );
		$this->assertStringContainsString( 'arguments must be an object', $error['message'] );
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

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'error', $result );
		$error = $result['error'];
		$this->assertSame( -32602, $error['code'] );
		$this->assertStringContainsString( 'arguments must be an object', $error['message'] );
	}

	public function test_call_tool_with_non_empty_list_arguments_returns_invalid_params_error(): void {
		$server  = $this->makeServer( array( 'test/always-allowed' ) );
		$handler = new ToolsHandler( $server );
		$result  = $handler->call_tool(
			array(
				'params' => array(
					'name'      => 'test-always-allowed',
					'arguments' => array( 1, 2 ),
				),
			),
			1
		);

		$this->assertArrayHasKey( 'error', $result );
		$this->assertSame( -32602, $result['error']['code'] );
		$this->assertStringContainsString( 'arguments must be an object', $result['error']['message'] );
	}

	public function test_call_tool_list_result_uses_text_without_structured_content(): void {
		$server  = $this->makeServer( array( 'test/always-allowed' ) );
		$handler = new ToolsHandler( $server );
		$filter  = static function (): array {
			return array(
				array( 'id' => 1 ),
				array( 'id' => 2 ),
			);
		};
		add_filter( 'mcp_adapter_tool_call_result', $filter );

		try {
			$result = $handler->call_tool(
				array(
					'params' => array(
						'name'      => 'test-always-allowed',
						'arguments' => array(),
					),
				),
				1
			);
		} finally {
			remove_filter( 'mcp_adapter_tool_call_result', $filter );
		}

		$this->assertArrayNotHasKey( 'error', $result );
		$this->assertArrayNotHasKey( 'structuredContent', $result );
		$this->assertSame( 'text', $result['content'][0]['type'] );
		$this->assertSame( '[{"id":1},{"id":2}]', $result['content'][0]['text'] );
		$this->assertFalse( $result['isError'] );
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
		$this->assertIsArray( $result );
		$this->assertFalse( (bool) $result['isError'] );
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
		$this->assertIsArray( $result );
		$this->assertFalse( (bool) $result['isError'] );
	}

	/**
	 * Runs a tool whose raw result is replaced by the given embedded-resource shape.
	 *
	 * @param array $shape The embedded resource result to substitute.
	 *
	 * @return array<string, mixed> Tool-call result or JSON-RPC error envelope, in wire shape.
	 */
	private function call_tool_returning( array $shape ): array {
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
				'_meta'       => array( 'block' => 'level' ),
				'resource'    => array(
					'uri'      => 'ui://example/app',
					'mimeType' => 'text/html;profile=mcp-app',
					'text'     => '<!doctype html>',
					'_meta'    => array( 'ui' => array( 'prefersBorder' => true ) ),
				),
			)
		);

		$this->assertIsArray( $result );

		$content = $result['content'];
		$this->assertIsArray( $content[0] );
		$this->assertSame( 'resource', $content[0]['type'] );

		// The outer `_meta` belongs to the content block.
		$this->assertSame( array( 'block' => 'level' ), $content[0]['_meta'] );

		// The nested _meta belongs to the resource contents.
		$resource = $content[0]['resource'];
		$this->assertIsArray( $resource );
		$this->assertSame( array( 'ui' => array( 'prefersBorder' => true ) ), $resource['_meta'] );
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

		$this->assertIsArray( $result );

		$content = $result['content'];
		$this->assertIsArray( $content[0] );
		$this->assertSame( 'resource', $content[0]['type'] );

		// Strip `type` and the flat shape is a ResourceContents literal, so its `_meta`
		// describes the resource. The block carries none; the nested form is how a caller
		// addresses the block level.
		$this->assertArrayNotHasKey( '_meta', $content[0] );
		$this->assertSame( array( 'ui' => array( 'prefersBorder' => true ) ), $content[0]['resource']['_meta'] );
	}

	public function test_image_result_carries_meta(): void {
		$result = $this->call_tool_returning(
			array(
				'type'     => 'image',
				'results'  => 'binary',
				'mimeType' => 'image/png',
				'_meta'    => array( 'ui' => array( 'prefersBorder' => true ) ),
			)
		);

		$this->assertIsArray( $result );

		$block = $result['content'][0];
		$this->assertSame( array( 'ui' => array( 'prefersBorder' => true ) ), $block['_meta'] );
	}

	/**
	 * MCP declares `_meta` an object, so a list is omitted rather than emitted as a JSON array.
	 */
	public function test_image_result_with_list_meta_omits_meta(): void {
		$result = $this->call_tool_returning(
			array(
				'type'     => 'image',
				'results'  => 'binary',
				'mimeType' => 'image/png',
				'_meta'    => array( 'a', 'b' ),
			)
		);

		$this->assertIsArray( $result );

		$block = $result['content'][0];
		$this->assertArrayNotHasKey( '_meta', $block );
		$this->assertNotEmpty( $block['data'] );
	}
}
