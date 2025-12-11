<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Handlers;

use WP\MCP\Handlers\Tools\ToolsHandler;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\TestCase;
use WP\McpSchema\Common\Content\ImageContent;
use WP\McpSchema\Common\Content\TextContent;
use WP\McpSchema\Common\JsonRpc\JSONRPCErrorResponse;
use WP\McpSchema\Server\Tools\CallToolResult;

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
}
