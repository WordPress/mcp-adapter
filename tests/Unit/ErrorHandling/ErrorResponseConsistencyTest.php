<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\ErrorHandling;

use WP\MCP\Core\McpServer;
use WP\MCP\Handlers\Prompts\PromptsHandler;
use WP\MCP\Handlers\Resources\ResourcesHandler;
use WP\MCP\Handlers\Tools\ToolsHandler;
use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;
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

		// Test parameter validation errors (INVALID_PARAMS) from all handlers
		$tools_error     = $tools_handler->call_tool( array( 'params' => array() ) ); // Missing 'name'
		$prompts_error   = $prompts_handler->get_prompt( array( 'params' => array() ) ); // Missing 'name'
		$resources_error = $resources_handler->read_resource( array( 'params' => array() ) ); // Missing 'uri'

		$errors = array( $tools_error, $prompts_error, $resources_error );

		foreach ( $errors as $error ) {
			$this->assertArrayHasKey( 'error', $error );
			$this->assertArrayHasKey( 'code', $error['error'] );
			$this->assertArrayHasKey( 'message', $error['error'] );
			$this->assertIsInt( $error['error']['code'] );
			$this->assertIsString( $error['error']['message'] );
		}
	}

	public function test_helper_trait_error_methods_produce_consistent_format(): void {
		$tools_handler = new ToolsHandler( $this->server );

		// Use reflection to access the protected helper methods
		$reflection = new \ReflectionClass( $tools_handler );

		$invalid_param_method = $reflection->getMethod( 'missing_parameter_error' );
		$invalid_param_method->setAccessible( true );

		$permission_denied_method = $reflection->getMethod( 'permission_denied_error' );
		$permission_denied_method->setAccessible( true );

		$internal_error_method = $reflection->getMethod( 'internal_error' );
		$internal_error_method->setAccessible( true );

		// Test all helper methods - missing_parameter_error uses INVALID_PARAMS error code
		$invalid_param_error = $invalid_param_method->invoke( $tools_handler, 'test_param', 123 );
		$permission_error    = $permission_denied_method->invoke( $tools_handler, 'test_resource', 456 );
		$internal_error      = $internal_error_method->invoke( $tools_handler, 'test_message', 789 );

		$errors = array( $invalid_param_error, $permission_error, $internal_error );

		foreach ( $errors as $error ) {
			$this->assertArrayHasKey( 'error', $error );
			$this->assertArrayHasKey( 'code', $error['error'] );
			$this->assertArrayHasKey( 'message', $error['error'] );
			$this->assertIsInt( $error['error']['code'] );
			$this->assertIsString( $error['error']['message'] );
			$this->assertNotEmpty( $error['error']['message'] );
		}
	}

	public function test_error_factory_consistency_with_handler_helpers(): void {
		$tools_handler = new ToolsHandler( $this->server );

		// Use reflection to access helper method
		$reflection           = new \ReflectionClass( $tools_handler );
		$invalid_param_method = $reflection->getMethod( 'missing_parameter_error' );
		$invalid_param_method->setAccessible( true );

		// Test parameter validation error from both factory and helper
		// Note: missing_parameter() is a convenience wrapper that returns INVALID_PARAMS error code
		$factory_error = McpErrorFactory::missing_parameter( 100, 'test_param' );
		$helper_error  = $invalid_param_method->invoke( $tools_handler, 'test_param', 100 );

		// Both should have the same structure
		$this->assertArrayHasKey( 'error', $factory_error );
		$this->assertArrayHasKey( 'error', $helper_error );

		// Error codes should match (both use INVALID_PARAMS)
		$this->assertSame( $factory_error['error']['code'], $helper_error['error']['code'] );
		$this->assertSame( McpErrorFactory::INVALID_PARAMS, $factory_error['error']['code'] );

		// Both should contain the parameter name
		$this->assertStringContainsString( 'test_param', $factory_error['error']['message'] );
		$this->assertStringContainsString( 'test_param', $helper_error['error']['message'] );
	}

	public function test_extract_error_helper_works_with_factory_responses(): void {
		$tools_handler = new ToolsHandler( $this->server );

		// Use reflection to access helper method
		$reflection           = new \ReflectionClass( $tools_handler );
		$extract_error_method = $reflection->getMethod( 'extract_error' );
		$extract_error_method->setAccessible( true );

		// Test with McpErrorFactory response
		$factory_response = McpErrorFactory::tool_not_found( 200, 'test_tool' );
		$extracted_error  = $extract_error_method->invoke( $tools_handler, $factory_response );

		$this->assertArrayHasKey( 'code', $extracted_error );
		$this->assertArrayHasKey( 'message', $extracted_error );
		$this->assertSame( $factory_response['error'], $extracted_error );

		// Test with plain error array
		$plain_error     = array(
			'code'    => 300,
			'message' => 'Plain error',
		);
		$extracted_plain = $extract_error_method->invoke( $tools_handler, $plain_error );

		$this->assertSame( $plain_error, $extracted_plain );
	}

	public function test_all_handlers_return_errors_in_same_format_for_not_found(): void {
		$tools_handler     = new ToolsHandler( $this->server );
		$prompts_handler   = new PromptsHandler( $this->server );
		$resources_handler = new ResourcesHandler( $this->server );

		// Test "not found" errors from all handlers
		$tool_not_found     = $tools_handler->call_tool( array( 'params' => array( 'name' => 'nonexistent_tool' ) ) );
		$prompt_not_found   = $prompts_handler->get_prompt( array( 'params' => array( 'name' => 'nonexistent_prompt' ) ) );
		$resource_not_found = $resources_handler->read_resource( array( 'params' => array( 'uri' => 'nonexistent://resource' ) ) );

		$errors = array( $tool_not_found, $prompt_not_found, $resource_not_found );

		foreach ( $errors as $error ) {
			$this->assertArrayHasKey( 'error', $error );
			$this->assertArrayHasKey( 'code', $error['error'] );
			$this->assertArrayHasKey( 'message', $error['error'] );
			$this->assertIsInt( $error['error']['code'] );
			$this->assertIsString( $error['error']['message'] );

			// All "not found" errors should have negative codes (MCP convention)
			$this->assertLessThan( 0, $error['error']['code'] );
		}
	}

	public function test_success_responses_are_consistent(): void {
		$tools_handler = new ToolsHandler( $this->server );

		// Use reflection to access helper method
		$reflection     = new \ReflectionClass( $tools_handler );
		$success_method = $reflection->getMethod( 'create_success_response' );
		$success_method->setAccessible( true );

		// Test success response formats
		$array_data   = array(
			'result' => 'success',
			'data'   => array( 'id' => 123 ),
		);
		$string_data  = 'simple success message';
		$numeric_data = 42;

		$array_response   = $success_method->invoke( $tools_handler, $array_data );
		$string_response  = $success_method->invoke( $tools_handler, $string_data );
		$numeric_response = $success_method->invoke( $tools_handler, $numeric_data );

		$responses = array( $array_response, $string_response, $numeric_response );

		foreach ( $responses as $response ) {
			$this->assertArrayHasKey( 'result', $response );
		}

		$this->assertSame( $array_data, $array_response['result'] );
		$this->assertSame( $string_data, $string_response['result'] );
		$this->assertSame( $numeric_data, $numeric_response['result'] );
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

		// Test parameter validation error messages (INVALID_PARAMS error code)
		$errors = array(
			$tools_handler->call_tool( array( 'params' => array() ) ), // Missing name
			$prompts_handler->get_prompt( array( 'params' => array() ) ), // Missing name
			$resources_handler->read_resource( array( 'params' => array() ) ), // Missing uri
		);

		foreach ( $errors as $error ) {
			$message = $error['error']['message'];

			// Error messages should be informative
			$this->assertNotEmpty( $message );
			$this->assertGreaterThan( 10, strlen( $message ) ); // Not too short
			$this->assertLessThan( 200, strlen( $message ) ); // Not too long

			// Should mention what's missing or invalid
			$this->assertTrue(
				strpos( $message, 'missing' ) !== false ||
				strpos( $message, 'required' ) !== false ||
				strpos( $message, 'parameter' ) !== false
			);
		}
	}
}
