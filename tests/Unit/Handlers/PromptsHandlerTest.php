<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Handlers;

use WP\MCP\Handlers\Prompts\PromptsHandler;
use WP\MCP\Tests\TestCase;

final class PromptsHandlerTest extends TestCase {

	public function test_list_prompts_returns_registered_prompts(): void {
		wp_set_current_user( 1 );
		$server  = $this->makeServer( array(), array(), array( 'test/prompt' ) );
		$handler = new PromptsHandler( $server );
		$res     = $handler->list_prompts();
		$this->assertArrayHasKey( 'prompts', $res );
		$this->assertNotEmpty( $res['prompts'] );
	}

	public function test_get_prompt_missing_name_returns_error(): void {
		$server  = $this->makeServer( array(), array(), array( 'test/prompt' ) );
		$handler = new PromptsHandler( $server );
		$res     = $handler->get_prompt( array( 'params' => array() ) );
		$this->assertArrayHasKey( 'error', $res );
	}

	public function test_get_prompt_unknown_returns_error(): void {
		$server  = $this->makeServer( array(), array(), array( 'test/prompt' ) );
		$handler = new PromptsHandler( $server );
		$res     = $handler->get_prompt( array( 'params' => array( 'name' => 'unknown' ) ) );
		$this->assertArrayHasKey( 'error', $res );
	}

	public function test_get_prompt_success_runs_ability(): void {
		$server  = $this->makeServer( array(), array(), array( 'test/prompt' ) );
		$handler = new PromptsHandler( $server );
		$res     = $handler->get_prompt(
			array(
				'params' => array(
					'name'      => 'test-prompt',
					'arguments' => array( 'code' => 'x' ),
				),
			)
		);
		$this->assertIsArray( $res );
		$this->assertArrayHasKey( '_metadata', $res );
	}

	public function test_get_prompt_with_wp_error_from_get_ability(): void {
		wp_set_current_user( 1 );

		// Register a prompt first, then unregister the ability to simulate get_ability() returning WP_Error
		$this->register_ability_in_hook(
			'test/prompt-to-remove',
			array(
				'label'               => 'Prompt To Remove',
				'description'         => 'A prompt whose ability will be removed',
				'category'            => 'test',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'input' => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => static function () {
					return array();
				},
				'permission_callback' => static function () {
					return true;
				},
				'meta'                => array(
					'mcp' => array(
						'public' => true,
						'type'   => 'prompt',
					),
				),
			)
		);

		$server  = $this->makeServer( array(), array(), array( 'test/prompt-to-remove' ) );
		$handler = new PromptsHandler( $server );

		// Now unregister the ability to simulate get_ability() returning WP_Error
		wp_unregister_ability( 'test/prompt-to-remove' );

		$res = $handler->get_prompt(
			array(
				'params' => array(
					'name'      => 'test-prompt-to-remove',
					'arguments' => array( 'input' => 'test' ),
				),
			)
		);

		// Should return error
		$this->assertArrayHasKey( 'error', $res );
		$this->assertArrayHasKey( '_metadata', $res );
		$this->assertEquals( 'ability_retrieval_failed', $res['_metadata']['failure_reason'] );
		$this->assertEquals( 'ability_not_found', $res['_metadata']['error_code'] );
	}

	public function test_get_prompt_with_wp_error_from_execute(): void {
		wp_set_current_user( 1 );

		// Register an ability that returns WP_Error
		$this->register_ability_in_hook(
			'test/wp-error-prompt-execute',
			array(
				'label'               => 'WP Error Prompt Execute',
				'description'         => 'Returns WP_Error from execute',
				'category'            => 'test',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'input' => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => static function () {
					return new \WP_Error( 'test_error', 'Test error message' );
				},
				'permission_callback' => static function () {
					return true;
				},
				'meta'                => array(
					'mcp' => array(
						'public' => true,
						'type'   => 'prompt',
					),
				),
			)
		);

		$server  = $this->makeServer( array(), array(), array( 'test/wp-error-prompt-execute' ) );
		$handler = new PromptsHandler( $server );

		$res = $handler->get_prompt(
			array(
				'params' => array(
					'name'      => 'test-wp-error-prompt-execute',
					'arguments' => array( 'input' => 'test' ),
				),
			)
		);

		// Should return error
		$this->assertArrayHasKey( 'error', $res );
		$this->assertArrayHasKey( '_metadata', $res );
		$this->assertEquals( 'wp_error', $res['_metadata']['failure_reason'] );
		$this->assertEquals( 'test_error', $res['_metadata']['error_code'] );

		// Clean up
		wp_unregister_ability( 'test/wp-error-prompt-execute' );
	}

	public function test_get_prompt_with_exception(): void {
		wp_set_current_user( 1 );

		// Register an ability that throws exception during execute
		$this->register_ability_in_hook(
			'test/prompt-execute-exception',
			array(
				'label'               => 'Prompt Execute Exception',
				'description'         => 'Throws exception in execute',
				'category'            => 'test',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'input' => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => static function () {
					throw new \RuntimeException( 'Execute exception' );
				},
				'permission_callback' => static function () {
					return true;
				},
				'meta'                => array(
					'mcp' => array(
						'public' => true,
						'type'   => 'prompt',
					),
				),
			)
		);

		$server  = $this->makeServer( array(), array(), array( 'test/prompt-execute-exception' ) );
		$handler = new PromptsHandler( $server );

		$res = $handler->get_prompt(
			array(
				'params' => array(
					'name'      => 'test-prompt-execute-exception',
					'arguments' => array( 'input' => 'test' ),
				),
			)
		);

		// Should return error
		$this->assertArrayHasKey( 'error', $res );
		$this->assertArrayHasKey( '_metadata', $res );
		$this->assertEquals( 'execution_failed', $res['_metadata']['failure_reason'] );
		$this->assertArrayHasKey( 'error_type', $res['_metadata'] );

		// Clean up
		wp_unregister_ability( 'test/prompt-execute-exception' );
	}

	// Note: Error path testing for prompts is covered by integration tests
	// and the existing basic error tests above
}
