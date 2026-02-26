<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\ErrorHandling;

use WP\MCP\Core\McpServer;
use WP\MCP\Handlers\Prompts\PromptsHandler;
use WP\MCP\Handlers\Resources\ResourcesHandler;
use WP\MCP\Handlers\Tools\ToolsHandler;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\Fixtures\DummyObservabilityHandler;
use WP\MCP\Tests\TestCase;

final class ErrorResponseConsistencyTest extends TestCase {

	private McpServer $server;

	public function setUp(): void {
		parent::setUp();
		$this->server = new McpServer(
			'test-server',
			'mcp/v1',
			'/mcp',
			'Test Server',
			'Test Description',
			'1.0.0',
			array(),
			DummyErrorHandler::class,
			DummyObservabilityHandler::class
		);
	}

	public function test_all_handlers_use_consistent_error_structure(): void {
		$tools_handler   = new ToolsHandler( $this->server );
		$prompts_handler = new PromptsHandler( $this->server );

		$resources_handler = new ResourcesHandler( $this->server );

		// Test parameter validation errors (INVALID_PARAMS) from all handlers.
		// All handlers now return DTOs, convert to array for consistent testing.
		$tools_error     = $tools_handler->call_tool( array( 'params' => array() ) )->toArray(); // Missing 'name'
		$prompts_error   = $prompts_handler->get_prompt( array( 'params' => array() ) )->toArray(); // Missing 'name'
		$resources_error = $resources_handler->read_resource( array( 'params' => array() ) )->toArray(); // Missing 'uri'

		$errors = array( $tools_error, $prompts_error, $resources_error );

		foreach ( $errors as $error ) {
			$this->assertArrayHasKey( 'error', $error );
			$this->assertArrayHasKey( 'code', $error['error'] );
			$this->assertArrayHasKey( 'message', $error['error'] );
			// Note: Error codes are now float (from DTOs) for JSON number compatibility.
			$this->assertIsNumeric( $error['error']['code'] );
			$this->assertIsString( $error['error']['message'] );
		}
	}

	public function test_all_handlers_return_errors_in_same_format_for_not_found(): void {
		$tools_handler     = new ToolsHandler( $this->server );
		$prompts_handler   = new PromptsHandler( $this->server );
		$resources_handler = new ResourcesHandler( $this->server );

		// Test "not found" errors from all handlers.
		// All handlers now return DTOs, convert to array for consistent testing.
		$tool_not_found     = $tools_handler->call_tool( array( 'params' => array( 'name' => 'nonexistent_tool' ) ) )->toArray();
		$prompt_not_found   = $prompts_handler->get_prompt( array( 'params' => array( 'name' => 'nonexistent_prompt' ) ) )->toArray();
		$resource_not_found = $resources_handler->read_resource( array( 'params' => array( 'uri' => 'nonexistent://resource' ) ) )->toArray();

		$errors = array( $tool_not_found, $prompt_not_found, $resource_not_found );

		foreach ( $errors as $error ) {
			$this->assertArrayHasKey( 'error', $error );
			$this->assertArrayHasKey( 'code', $error['error'] );
			$this->assertArrayHasKey( 'message', $error['error'] );
			// Note: Error codes are now float (from DTOs) for JSON number compatibility.
			$this->assertIsNumeric( $error['error']['code'] );
			$this->assertIsString( $error['error']['message'] );

			// All "not found" errors should have negative codes (MCP convention).
			$this->assertLessThan( 0, $error['error']['code'] );
		}
	}

	public function test_parameter_extraction_consistency_across_handlers(): void {
		$tools_handler     = new ToolsHandler( $this->server );
		$prompts_handler   = new PromptsHandler( $this->server );
		$resources_handler = new ResourcesHandler( $this->server );

		// Use reflection to access extract_params methods
		$tools_reflection     = new \ReflectionClass( $tools_handler );
		$prompts_reflection   = new \ReflectionClass( $prompts_handler );
		$resources_reflection = new \ReflectionClass( $resources_handler );

		$tools_extract = $tools_reflection->getMethod( 'extract_params' );
		$tools_extract->setAccessible( true );

		$prompts_extract = $prompts_reflection->getMethod( 'extract_params' );
		$prompts_extract->setAccessible( true );

		$resources_extract = $resources_reflection->getMethod( 'extract_params' );
		$resources_extract->setAccessible( true );

		// Test both nested and direct parameter formats
		$nested_params = array(
			'params' => array(
				'name'  => 'test',
				'value' => 123,
			),
		);
		$direct_params = array(
			'name'  => 'test',
			'value' => 123,
		);

		// All handlers should extract parameters the same way
		$tools_nested     = $tools_extract->invoke( $tools_handler, $nested_params );
		$prompts_nested   = $prompts_extract->invoke( $prompts_handler, $nested_params );
		$resources_nested = $resources_extract->invoke( $resources_handler, $nested_params );

		$tools_direct     = $tools_extract->invoke( $tools_handler, $direct_params );
		$prompts_direct   = $prompts_extract->invoke( $prompts_handler, $direct_params );
		$resources_direct = $resources_extract->invoke( $resources_handler, $direct_params );

		// All should extract to the same result
		$expected = array(
			'name'  => 'test',
			'value' => 123,
		);

		$this->assertSame( $expected, $tools_nested );
		$this->assertSame( $expected, $prompts_nested );
		$this->assertSame( $expected, $resources_nested );

		$this->assertSame( $expected, $tools_direct );
		$this->assertSame( $expected, $prompts_direct );
		$this->assertSame( $expected, $resources_direct );
	}

	public function test_error_message_quality_across_handlers(): void {
		$tools_handler     = new ToolsHandler( $this->server );
		$prompts_handler   = new PromptsHandler( $this->server );
		$resources_handler = new ResourcesHandler( $this->server );

		// Test parameter validation error messages (INVALID_PARAMS error code).
		// All handlers now return DTOs, convert to array for consistent testing.
		$errors = array(
			$tools_handler->call_tool( array( 'params' => array() ) )->toArray(), // Missing name
			$prompts_handler->get_prompt( array( 'params' => array() ) )->toArray(), // Missing name
			$resources_handler->read_resource( array( 'params' => array() ) )->toArray(), // Missing uri
		);

		foreach ( $errors as $error ) {
			$message = $error['error']['message'];

			// Error messages should be informative.
			$this->assertNotEmpty( $message );
			$this->assertGreaterThan( 10, strlen( $message ) ); // Not too short
			$this->assertLessThan( 200, strlen( $message ) ); // Not too long

			// Should mention what's missing or invalid.
			$this->assertTrue(
				strpos( $message, 'missing' ) !== false ||
				strpos( $message, 'required' ) !== false ||
				strpos( $message, 'parameter' ) !== false
			);
		}
	}
}
