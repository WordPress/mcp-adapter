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

	public function test_pre_tool_call_filter_array_modifies_arguments_and_executes_once(): void {
		$execution_count = 0;
		$received_args   = array();
		$this->register_counted_tool( 'test/pre-tool-call-array', $execution_count, $received_args );
		$handler = new ToolsHandler( $this->makeServer( array( 'test/pre-tool-call-array' ) ) );

		$filter = static function ( array $args ): array {
			$args['filtered'] = true;

			return $args;
		};
		add_filter( 'mcp_adapter_pre_tool_call', $filter );

		$handler->call_tool( array( 'params' => array( 'name' => 'test-pre-tool-call-array' ) ) );

		$this->assertSame( 1, $execution_count );
		$this->assertTrue( $received_args['filtered'] );

		remove_filter( 'mcp_adapter_pre_tool_call', $filter );
		wp_unregister_ability( 'test/pre-tool-call-array' );
	}

	public function test_pre_tool_call_filter_wp_error_prevents_execution(): void {
		$execution_count = 0;
		$received_args   = array();
		$this->register_counted_tool( 'test/pre-tool-call-error', $execution_count, $received_args );
		$handler = new ToolsHandler( $this->makeServer( array( 'test/pre-tool-call-error' ) ) );

		$filter = static function () {
			return new \WP_Error( 'blocked', 'Blocked by middleware' );
		};
		add_filter( 'mcp_adapter_pre_tool_call', $filter );

		$result = $handler->call_tool( array( 'params' => array( 'name' => 'test-pre-tool-call-error' ) ) );

		$this->assertInstanceOf( CallToolResult::class, $result );
		$this->assertTrue( $result->getIsError() );
		$this->assertSame( 0, $execution_count );

		remove_filter( 'mcp_adapter_pre_tool_call', $filter );
		wp_unregister_ability( 'test/pre-tool-call-error' );
	}

	public function test_pre_tool_call_completion_uses_filtered_arguments_without_execution(): void {
		$execution_count = 0;
		$received_args   = array();
		$this->register_counted_tool( 'test/pre-tool-call-complete', $execution_count, $received_args );
		$handler          = new ToolsHandler( $this->makeServer( array( 'test/pre-tool-call-complete' ) ) );
		$completed_result = CallToolResult::fromArray(
			array(
				'content' => array(
					array(
						'type' => 'text',
						'text' => 'Completed by middleware',
					),
				),
				'isError' => false,
			)
		);

		$argument_filter = static function ( array $args ): array {
			$args['filtered'] = true;

			return $args;
		};
		$completion_filter = static function ( array $callbacks ) use ( $completed_result ): array {
			$callbacks[] = static function ( array $args ) use ( $completed_result ): CallToolResult {
				if ( empty( $args['filtered'] ) ) {
					throw new \RuntimeException( 'Completion callback did not receive filtered arguments.' );
				}

				return $completed_result;
			};

			return $callbacks;
		};
		add_filter( 'mcp_adapter_pre_tool_call', $argument_filter );
		add_filter( 'mcp_adapter_pre_tool_call_completion_callbacks', $completion_filter );

		$result = $handler->call_tool( array( 'params' => array( 'name' => 'test-pre-tool-call-complete' ) ) );

		$this->assertSame( $completed_result, $result );
		$this->assertFalse( (bool) $result->getIsError() );
		$this->assertSame( 0, $execution_count );

		remove_filter( 'mcp_adapter_pre_tool_call', $argument_filter );
		remove_filter( 'mcp_adapter_pre_tool_call_completion_callbacks', $completion_filter );
		wp_unregister_ability( 'test/pre-tool-call-complete' );
	}

	public function test_pre_tool_call_completion_stops_remaining_callbacks(): void {
		$execution_count     = 0;
		$received_args       = array();
		$second_callback_ran = false;
		$this->register_counted_tool( 'test/pre-tool-call-terminal', $execution_count, $received_args );
		$handler          = new ToolsHandler( $this->makeServer( array( 'test/pre-tool-call-terminal' ) ) );
		$completed_result = CallToolResult::fromArray(
			array(
				'content' => array( array( 'type' => 'text', 'text' => 'Context required' ) ),
				'isError' => false,
			)
		);

		$first_registration = static function ( array $callbacks ) use ( $completed_result ): array {
			$callbacks[] = static function () use ( $completed_result ): CallToolResult {
				return $completed_result;
			};

			return $callbacks;
		};
		$second_registration = static function ( array $callbacks ) use ( &$second_callback_ran ): array {
			$callbacks[] = static function () use ( &$second_callback_ran ): ?CallToolResult {
				$second_callback_ran = true;

				return null;
			};

			return $callbacks;
		};
		add_filter( 'mcp_adapter_pre_tool_call_completion_callbacks', $first_registration, 10 );
		add_filter( 'mcp_adapter_pre_tool_call_completion_callbacks', $second_registration, 20 );

		$result = $handler->call_tool( array( 'params' => array( 'name' => 'test-pre-tool-call-terminal' ) ) );

		$this->assertSame( $completed_result, $result );
		$this->assertFalse( $second_callback_ran );
		$this->assertSame( 0, $execution_count );

		remove_filter( 'mcp_adapter_pre_tool_call_completion_callbacks', $first_registration, 10 );
		remove_filter( 'mcp_adapter_pre_tool_call_completion_callbacks', $second_registration, 20 );
		wp_unregister_ability( 'test/pre-tool-call-terminal' );
	}

	public function test_permission_denial_prevents_pre_tool_call_completion(): void {
		$execution_count = 0;
		$received_args   = array();
		$middleware_ran  = false;
		$this->register_counted_tool( 'test/pre-tool-call-denied', $execution_count, $received_args, false );
		$handler = new ToolsHandler( $this->makeServer( array( 'test/pre-tool-call-denied' ) ) );

		$filter = static function ( array $callbacks ) use ( &$middleware_ran ): array {
			$middleware_ran = true;

			return $callbacks;
		};
		add_filter( 'mcp_adapter_pre_tool_call_completion_callbacks', $filter );

		$result = $handler->call_tool( array( 'params' => array( 'name' => 'test-pre-tool-call-denied' ) ) );

		$this->assertTrue( $result->getIsError() );
		$this->assertFalse( $middleware_ran );
		$this->assertSame( 0, $execution_count );

		remove_filter( 'mcp_adapter_pre_tool_call_completion_callbacks', $filter );
		wp_unregister_ability( 'test/pre-tool-call-denied' );
	}

	public function test_invalid_pre_tool_call_filter_return_prevents_execution(): void {
		$execution_count = 0;
		$received_args   = array();
		$this->register_counted_tool( 'test/pre-tool-call-invalid', $execution_count, $received_args );
		$handler = new ToolsHandler( $this->makeServer( array( 'test/pre-tool-call-invalid' ) ) );

		$filter = static function (): string {
			return 'invalid';
		};
		add_filter( 'mcp_adapter_pre_tool_call', $filter );

		$result = $handler->call_tool( array( 'params' => array( 'name' => 'test-pre-tool-call-invalid' ) ) );

		$this->assertTrue( $result->getIsError() );
		$this->assertSame( 0, $execution_count );
		$this->assertStringContainsString( 'expected an array or WP_Error, received string', $result->getContent()[0]->getText() );

		remove_filter( 'mcp_adapter_pre_tool_call', $filter );
		wp_unregister_ability( 'test/pre-tool-call-invalid' );
	}

	public function test_invalid_pre_tool_call_completion_callback_return_prevents_execution(): void {
		$execution_count = 0;
		$received_args   = array();
		$this->register_counted_tool( 'test/pre-tool-call-invalid-completion', $execution_count, $received_args );
		$handler = new ToolsHandler( $this->makeServer( array( 'test/pre-tool-call-invalid-completion' ) ) );

		$filter = static function ( array $callbacks ): array {
			$callbacks[] = static function (): string {
				return 'invalid';
			};

			return $callbacks;
		};
		add_filter( 'mcp_adapter_pre_tool_call_completion_callbacks', $filter );

		$result = $handler->call_tool( array( 'params' => array( 'name' => 'test-pre-tool-call-invalid-completion' ) ) );

		$this->assertTrue( $result->getIsError() );
		$this->assertSame( 0, $execution_count );
		$this->assertStringContainsString( 'expected null or CallToolResult, received string', $result->getContent()[0]->getText() );

		remove_filter( 'mcp_adapter_pre_tool_call_completion_callbacks', $filter );
		wp_unregister_ability( 'test/pre-tool-call-invalid-completion' );
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
	 * Register an MCP tool that records execution side effects.
	 *
	 * @param string       $name            The ability name.
	 * @param int          $execution_count The execution count reference.
	 * @param array        $received_args   The received arguments reference.
	 * @param bool         $permitted       Whether the tool grants permission.
	 *
	 * @return void
	 */
	private function register_counted_tool( string $name, int &$execution_count, array &$received_args, bool $permitted = true ): void {
		$this->register_ability_in_hook(
			$name,
			array(
				'label'               => 'Counted Tool',
				'description'         => 'Records tool execution for pre-call tests',
				'category'            => 'test',
				'input_schema'        => array( 'type' => 'object' ),
				'execute_callback'    => static function ( array $args ) use ( &$execution_count, &$received_args ) {
					++$execution_count;
					$received_args = $args;

					return array( 'executed' => true );
				},
				'permission_callback' => static function () use ( $permitted ) {
					return $permitted;
				},
				'meta'                => array(
					'mcp' => array( 'public' => true ),
				),
			)
		);
	}
}
