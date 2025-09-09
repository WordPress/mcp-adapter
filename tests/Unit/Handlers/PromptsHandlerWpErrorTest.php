<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Handlers;

use WP\MCP\Core\McpServer;
use WP\MCP\Handlers\Prompts\PromptsHandler;
use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;
use WP\MCP\Tests\Fixtures\DummyAbility;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\Fixtures\DummyObservabilityHandler;
use WP\MCP\Tests\TestCase;

final class PromptsHandlerWpErrorTest extends TestCase {

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		do_action( 'abilities_api_init' );
		DummyAbility::register_all();
	}

	public function test_wp_error_from_prompt_returns_internal_error(): void {
		$server  = $this->makeServer( array( 'test/wp-error-prompt' ) );
		$handler = new PromptsHandler( $server );
		
		$result = $handler->get_prompt(
			array(
				'params' => array(
					'name' => 'test-wp-error-prompt',
					'arguments' => array( 'query' => 'test' ),
				),
			),
			123
		);

		// Should return an error response
		$this->assertArrayHasKey( 'error', $result );
		$this->assertArrayNotHasKey( 'messages', $result );
		
		// Verify error structure matches MCP error format
		$error = $result['error'];
		$this->assertArrayHasKey( 'code', $error );
		$this->assertArrayHasKey( 'message', $error );
		
		// Should be an internal error
		$this->assertSame( McpErrorFactory::INTERNAL_ERROR, $error['code'] );
		$this->assertStringContainsString( 'Error executing prompt', $error['message'] );
	}

	public function test_wp_error_from_prompt_with_different_request_id(): void {
		$server  = $this->makeServer( array( 'test/wp-error-prompt' ) );
		$handler = new PromptsHandler( $server );
		
		$request_id = 789;
		$result = $handler->get_prompt(
			array(
				'params' => array(
					'name' => 'test-wp-error-prompt',
					'arguments' => array(),
				),
			),
			$request_id
		);

		$this->assertArrayHasKey( 'error', $result );
		
		// The error should contain the internal error code
		$error = $result['error'];
		$this->assertSame( McpErrorFactory::INTERNAL_ERROR, $error['code'] );
	}

	public function test_successful_prompt_execution_still_works(): void {
		$server  = $this->makeServer( array( 'test/prompt', 'test/wp-error-prompt' ) );
		$handler = new PromptsHandler( $server );
		
		// Test that normal prompts still work
		$result = $handler->get_prompt(
			array(
				'params' => array(
					'name' => 'test-prompt',
					'arguments' => array( 'code' => 'test code' ),
				),
			)
		);

		$this->assertArrayHasKey( 'messages', $result );
		$this->assertArrayNotHasKey( 'error', $result );
		
		// Verify the prompt response structure
		$this->assertIsArray( $result['messages'] );
		$this->assertNotEmpty( $result['messages'] );
		$this->assertArrayHasKey( 'role', $result['messages'][0] );
		$this->assertSame( 'assistant', $result['messages'][0]['role'] );
	}

	public function test_wp_error_prompt_mixed_with_normal_prompts(): void {
		$server  = $this->makeServer( array( 'test/prompt', 'test/wp-error-prompt' ) );
		$handler = new PromptsHandler( $server );
		
		// Test normal prompt
		$normal_result = $handler->get_prompt(
			array(
				'params' => array(
					'name' => 'test-prompt',
					'arguments' => array( 'code' => 'test' ),
				),
			)
		);
		$this->assertArrayHasKey( 'messages', $normal_result );
		
		// Test error prompt
		$error_result = $handler->get_prompt(
			array(
				'params' => array(
					'name' => 'test-wp-error-prompt',
					'arguments' => array(),
				),
			)
		);
		$this->assertArrayHasKey( 'error', $error_result );
		$this->assertSame( McpErrorFactory::INTERNAL_ERROR, $error_result['error']['code'] );
	}

	public function test_wp_error_prompt_with_arguments(): void {
		$server  = $this->makeServer( array( 'test/wp-error-prompt' ) );
		$handler = new PromptsHandler( $server );
		
		$result = $handler->get_prompt(
			array(
				'params' => array(
					'name' => 'test-wp-error-prompt',
					'arguments' => array(
						'query' => 'complex query',
						'context' => 'test context',
					),
				),
			)
		);

		// Even with arguments, WP_Error should still be handled
		$this->assertArrayHasKey( 'error', $result );
		$this->assertSame( McpErrorFactory::INTERNAL_ERROR, $result['error']['code'] );
	}

	public function test_wp_error_vs_exception_handling_in_prompts(): void {
		$server  = $this->makeServer( array( 'test/wp-error-prompt', 'test/permission-exception' ) );
		$handler = new PromptsHandler( $server );
		
		// Test WP_Error prompt
		$wp_error_result = $handler->get_prompt(
			array(
				'params' => array( 'name' => 'test-wp-error-prompt' ),
			)
		);
		
		// Test exception prompt (using permission-exception as it will throw during execution)
		$exception_result = $handler->get_prompt(
			array(
				'params' => array( 'name' => 'test-permission-exception' ),
			)
		);

		// Both should return internal errors
		$this->assertArrayHasKey( 'error', $wp_error_result );
		$this->assertArrayHasKey( 'error', $exception_result );
		
		$this->assertSame( McpErrorFactory::INTERNAL_ERROR, $wp_error_result['error']['code'] );
		
		// The exception case should return a different error (permission check failed)
		$this->assertSame( McpErrorFactory::INTERNAL_ERROR, $exception_result['error']['code'] );
		
		// Both should contain error messages
		$this->assertStringContainsString( 'Error executing prompt', $wp_error_result['error']['message'] );
		$this->assertStringContainsString( 'Prompt execution failed', $exception_result['error']['message'] );
	}

	public function test_missing_prompt_name_returns_missing_parameter_error(): void {
		$server  = $this->makeServer( array( 'test/wp-error-prompt' ) );
		$handler = new PromptsHandler( $server );
		
		$result = $handler->get_prompt(
			array(
				'params' => array(
					'arguments' => array(),
				),
			)
		);

		$this->assertArrayHasKey( 'error', $result );
		$this->assertSame( McpErrorFactory::MISSING_PARAMETER, $result['error']['code'] );
		$this->assertStringContainsString( 'name', $result['error']['message'] );
	}

	public function test_unknown_prompt_returns_prompt_not_found_error(): void {
		$server  = $this->makeServer( array( 'test/wp-error-prompt' ) );
		$handler = new PromptsHandler( $server );
		
		$result = $handler->get_prompt(
			array(
				'params' => array(
					'name' => 'nonexistent-prompt',
					'arguments' => array(),
				),
			)
		);

		$this->assertArrayHasKey( 'error', $result );
		$this->assertSame( McpErrorFactory::PROMPT_NOT_FOUND, $result['error']['code'] );
		$this->assertStringContainsString( 'nonexistent-prompt', $result['error']['message'] );
	}

	private function makeServer( array $prompts = array() ): McpServer {
		return new McpServer(
			'srv',
			'mcp/v1',
			'/mcp',
			'Srv',
			'desc',
			'0.0.1',
			array(),
			DummyErrorHandler::class,
			DummyObservabilityHandler::class,
			array(),
			array(),
			$prompts,
		);
	}
}
