<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Handlers;

use WP\MCP\Domain\Continuation\McpContinuationContext;
use WP\MCP\Domain\Continuation\McpExecutionResult;
use WP\MCP\Domain\Prompts\McpPrompt;
use WP\MCP\Handlers\Prompts\PromptsHandler;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\TestCase;
use WP\McpSchema\Server\Prompts\DTO\Prompt;
use WP_Error;

final class PromptsHandlerTest extends TestCase {

	public function test_continuation_context_and_result_pass_through_unchanged(): void {
		$received_context = null;
		$expected         = McpExecutionResult::input_required( array(), 'prompt-state' );
		$prompt           = McpPrompt::fromArray(
			array(
				'name'       => 'continuing-prompt',
				'handler'    => static function ( array $arguments, McpContinuationContext $context ) use ( &$received_context, $expected ): McpExecutionResult {
					$received_context = $context;

					return $expected;
				},
				'permission' => '__return_true',
			)
		);
		$this->assertInstanceOf( McpPrompt::class, $prompt );

		$context = new McpContinuationContext( array(), 'previous-prompt-state' );
		$result  = ( new PromptsHandler( $this->makeServer( array(), array(), array( $prompt ) ) ) )->get_prompt(
			array( 'name' => 'continuing-prompt' ),
			1,
			$context
		);

		$this->assertSame( $context, $received_context );
		$this->assertSame( $expected, $result );
	}

	public function test_completed_execution_result_uses_existing_prompt_normalization(): void {
		$prompt = McpPrompt::fromArray(
			array(
				'name'       => 'completed-prompt',
				'handler'    => static fn(): McpExecutionResult => McpExecutionResult::complete( array( 'text' => 'done' ) ),
				'permission' => '__return_true',
			)
		);
		$this->assertInstanceOf( McpPrompt::class, $prompt );

		$result = ( new PromptsHandler( $this->makeServer( array(), array(), array( $prompt ) ) ) )->get_prompt(
			array( 'name' => 'completed-prompt' ),
			1
		);

		$this->assertSame( 'done', $result['messages'][0]['content']['text'] );
	}

	public function test_list_prompts_returns_registered_prompts(): void {
		wp_set_current_user( 1 );
		$server  = $this->makeServer( array(), array(), array( 'test/prompt' ) );
		$handler = new PromptsHandler( $server );
		$result  = $handler->list_prompts();

		$prompts = $result['prompts'];
		$this->assertNotEmpty( $prompts );
		$this->assertContainsOnly( 'array', $prompts );
	}

	public function test_list_prompts_applies_prompts_list_filter(): void {
		$server  = $this->makeServer( array(), array(), array( 'test/always-allowed' ) );
		$handler = new PromptsHandler( $server );

		$filter = function ( array $prompts ): array {
			$this->assertInstanceOf( Prompt::class, $prompts[0] );
			$this->assertSame( 'test-always-allowed', $prompts[0]->getName() );

			return array();
		};
		add_filter( 'mcp_adapter_prompts_list', $filter );

		$result = $handler->list_prompts();
		$this->assertEmpty( $result['prompts'] );

		remove_filter( 'mcp_adapter_prompts_list', $filter );
	}

	public function test_get_prompt_missing_name_returns_error(): void {
		$server  = $this->makeServer( array(), array(), array( 'test/prompt' ) );
		$handler = new PromptsHandler( $server );
		$result  = $handler->get_prompt( array( 'params' => array() ) );

		$this->assertSame( -32602, $result['error']['code'] );
		$this->assertNotEmpty( $result['error']['message'] );
	}

	public function test_get_prompt_unknown_returns_error(): void {
		$server  = $this->makeServer( array(), array(), array( 'test/prompt' ) );
		$handler = new PromptsHandler( $server );
		$result  = $handler->get_prompt( array( 'params' => array( 'name' => 'unknown' ) ) );

		$this->assertSame( -32004, $result['error']['code'] );
		$this->assertNotEmpty( $result['error']['message'] );
	}

	public function test_get_prompt_success_runs_ability(): void {
		$server  = $this->makeServer( array(), array(), array( 'test/prompt' ) );
		$handler = new PromptsHandler( $server );
		$result  = $handler->get_prompt(
			array(
				'params' => array(
					'name'      => 'test-prompt',
					'arguments' => array( 'code' => 'x' ),
				),
			)
		);

		$messages = $result['messages'];
		$this->assertNotEmpty( $messages );
		$this->assertContainsOnly( 'array', $messages );
	}

	public function test_get_prompt_does_not_require_ability_lookup_at_runtime(): void {
		wp_set_current_user( 1 );

		// Register a prompt ability, then unregister it after the server is created.
		// Wrapper-backed execution keeps the ability instance, so runtime lookup is not required.
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
				'execute_callback'    => static function (): array {
					return array();
				},
				'permission_callback' => static function (): bool {
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

		// Now unregister the ability after the wrapper was created.
		wp_unregister_ability( 'test/prompt-to-remove' );

		$result = $handler->get_prompt(
			array(
				'params' => array(
					'name'      => 'test-prompt-to-remove',
					'arguments' => array( 'input' => 'test' ),
				),
			)
		);
		$this->assertNotEmpty( $result['messages'] );
	}

	public function test_get_prompt_with_wp_error_from_execute(): void {
		wp_set_current_user( 1 );

		// Register an ability that returns WP_Error.
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
					return new WP_Error( 'test_error', 'Test error message' );
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

		$result = $handler->get_prompt(
			array(
				'params' => array(
					'name'      => 'test-wp-error-prompt-execute',
					'arguments' => array( 'input' => 'test' ),
				),
			)
		);

		$this->assertSame( -32603, $result['error']['code'] );
		$this->assertNotEmpty( $result['error']['message'] );

		// Clean up.
		wp_unregister_ability( 'test/wp-error-prompt-execute' );
	}

	public function test_get_prompt_with_exception(): void {
		wp_set_current_user( 1 );

		// Register an ability that throws exception during execute.
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

		$result = $handler->get_prompt(
			array(
				'params' => array(
					'name'      => 'test-prompt-execute-exception',
					'arguments' => array( 'input' => 'test' ),
				),
			)
		);

		$this->assertSame( -32603, $result['error']['code'] );
		$this->assertNotEmpty( $result['error']['message'] );

		// Clean up.
		wp_unregister_ability( 'test/prompt-execute-exception' );
	}

	public function test_pre_prompt_get_filter_can_modify_arguments(): void {
		$server  = $this->makeServer( array(), array(), array( 'test/always-allowed' ) );
		$handler = new PromptsHandler( $server );

		$received_args        = null;
		$received_prompt_name = null;
		$filter               = static function ( array $arguments, string $prompt_name ) use ( &$received_args, &$received_prompt_name ): array {
			$received_args        = $arguments;
			$received_prompt_name = $prompt_name;

			return $arguments;
		};
		add_filter( 'mcp_adapter_pre_prompt_get', $filter, 10, 2 );

		$handler->get_prompt(
			array(
				'params' => array(
					'name'      => 'test-always-allowed',
					'arguments' => array( 'key' => 'value' ),
				),
			)
		);

		$this->assertIsArray( $received_args );
		$this->assertSame( 'value', $received_args['key'] );
		$this->assertSame( 'test-always-allowed', $received_prompt_name );

		remove_filter( 'mcp_adapter_pre_prompt_get', $filter );
	}

	public function test_pre_prompt_get_filter_can_short_circuit_with_wp_error(): void {
		$server  = $this->makeServer( array(), array(), array( 'test/always-allowed' ) );
		$handler = new PromptsHandler( $server );

		$filter = static function () {
			return new \WP_Error( 'blocked', 'Prompt access blocked' );
		};
		add_filter( 'mcp_adapter_pre_prompt_get', $filter );

		$result = $handler->get_prompt(
			array(
				'params' => array(
					'name'      => 'test-always-allowed',
					'arguments' => array(),
				),
			)
		);

		$this->assertSame( -32603, $result['error']['code'] );
		$this->assertStringContainsString( 'Prompt access blocked', $result['error']['message'] );

		remove_filter( 'mcp_adapter_pre_prompt_get', $filter );
	}

	public function test_prompt_get_result_filter_can_modify_result(): void {
		$server  = $this->makeServer( array(), array(), array( 'test/always-allowed' ) );
		$handler = new PromptsHandler( $server );

		$filter_was_called = false;
		$filter            = static function ( $result ) use ( &$filter_was_called ) {
			$filter_was_called = true;

			return $result;
		};
		add_filter( 'mcp_adapter_prompt_get_result', $filter );

		$handler->get_prompt(
			array(
				'params' => array(
					'name'      => 'test-always-allowed',
					'arguments' => array(),
				),
			)
		);

		$this->assertTrue( $filter_was_called );

		remove_filter( 'mcp_adapter_prompt_get_result', $filter );
	}

	// Note: Error path testing for prompts is covered by integration tests
	// and the existing basic error tests above.

	// =========================================================================
	// Result Normalization Tier Tests
	// =========================================================================

	/**
	 * Test Tier 1: Full MCP format with 'messages' array passthrough.
	 */
	public function test_get_prompt_tier1_full_mcp_format(): void {
		wp_set_current_user( 1 );

		$this->register_ability_in_hook(
			'test/tier1-prompt',
			array(
				'label'               => 'Tier 1 Prompt',
				'description'         => 'Returns full MCP format',
				'category'            => 'test',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'execute_callback'    => static function (): array {
					return array(
						'messages' => array(
							array(
								'role'    => 'user',
								'content' => array(
									'type' => 'text',
									'text' => 'User message',
								),
							),
							array(
								'role'    => 'assistant',
								'content' => array(
									'type' => 'text',
									'text' => 'Assistant response',
								),
							),
						),
					);
				},
				'permission_callback' => '__return_true',
				'meta'                => array(
					'mcp' => array(
						'public' => true,
						'type'   => 'prompt',
					),
				),
			)
		);

		$server  = $this->makeServer( array(), array(), array( 'test/tier1-prompt' ) );
		$handler = new PromptsHandler( $server );

		$result   = $handler->get_prompt(
			array(
				'params' => array(
					'name'      => 'test-tier1-prompt',
					'arguments' => array(),
				),
			)
		);
		$messages = $result['messages'];
		$this->assertCount( 2, $messages );
		$this->assertEquals( 'user', $messages[0]['role'] );
		$this->assertEquals( 'assistant', $messages[1]['role'] );

		wp_unregister_ability( 'test/tier1-prompt' );
	}

	/**
	 * Test Tier 2: Simple 'text' shorthand normalization.
	 */
	public function test_get_prompt_tier2_simple_text(): void {
		wp_set_current_user( 1 );

		$this->register_ability_in_hook(
			'test/tier2-prompt',
			array(
				'label'               => 'Tier 2 Prompt',
				'description'         => 'Returns simple text format',
				'category'            => 'test',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'execute_callback'    => static function (): array {
					return array(
						'text' => 'Simple text response',
					);
				},
				'permission_callback' => '__return_true',
				'meta'                => array(
					'mcp' => array(
						'public' => true,
						'type'   => 'prompt',
					),
				),
			)
		);

		$server  = $this->makeServer( array(), array(), array( 'test/tier2-prompt' ) );
		$handler = new PromptsHandler( $server );

		$result   = $handler->get_prompt(
			array(
				'params' => array(
					'name'      => 'test-tier2-prompt',
					'arguments' => array(),
				),
			)
		);
		$messages = $result['messages'];
		$this->assertCount( 1, $messages );
		$this->assertEquals( 'user', $messages[0]['role'] );

		$content = $messages[0]['content'];
		$this->assertEquals( 'text', $content['type'] );
		$this->assertEquals( 'Simple text response', $content['text'] );

		wp_unregister_ability( 'test/tier2-prompt' );
	}

	/**
	 * Test Tier 3: Single message with 'role' and 'content'.
	 */
	public function test_get_prompt_tier3_single_message(): void {
		wp_set_current_user( 1 );

		$this->register_ability_in_hook(
			'test/tier3-prompt',
			array(
				'label'               => 'Tier 3 Prompt',
				'description'         => 'Returns single message format',
				'category'            => 'test',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'execute_callback'    => static function (): array {
					return array(
						'role'    => 'assistant',
						'content' => array(
							'type' => 'text',
							'text' => 'Assistant message',
						),
					);
				},
				'permission_callback' => '__return_true',
				'meta'                => array(
					'mcp' => array(
						'public' => true,
						'type'   => 'prompt',
					),
				),
			)
		);

		$server  = $this->makeServer( array(), array(), array( 'test/tier3-prompt' ) );
		$handler = new PromptsHandler( $server );

		$result   = $handler->get_prompt(
			array(
				'params' => array(
					'name'      => 'test-tier3-prompt',
					'arguments' => array(),
				),
			)
		);
		$messages = $result['messages'];
		$this->assertCount( 1, $messages );
		$this->assertEquals( 'assistant', $messages[0]['role'] );

		$content = $messages[0]['content'];
		$this->assertEquals( 'text', $content['type'] );
		$this->assertEquals( 'Assistant message', $content['text'] );

		wp_unregister_ability( 'test/tier3-prompt' );
	}

	/**
	 * Test Tier 4: Multi-text with 'texts' array.
	 */
	public function test_get_prompt_tier4_multi_text(): void {
		wp_set_current_user( 1 );

		$this->register_ability_in_hook(
			'test/tier4-prompt',
			array(
				'label'               => 'Tier 4 Prompt',
				'description'         => 'Returns multi-text format',
				'category'            => 'test',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'execute_callback'    => static function (): array {
					return array(
						'texts' => array(
							'First message',
							'Second message',
							'Third message',
						),
					);
				},
				'permission_callback' => '__return_true',
				'meta'                => array(
					'mcp' => array(
						'public' => true,
						'type'   => 'prompt',
					),
				),
			)
		);

		$server  = $this->makeServer( array(), array(), array( 'test/tier4-prompt' ) );
		$handler = new PromptsHandler( $server );

		$result   = $handler->get_prompt(
			array(
				'params' => array(
					'name'      => 'test-tier4-prompt',
					'arguments' => array(),
				),
			)
		);
		$messages = $result['messages'];
		$this->assertCount( 3, $messages );

		// All messages should have 'user' role (default).
		foreach ( $messages as $message ) {
			$this->assertEquals( 'user', $message['role'] );
		}

		// Verify message content.
		$this->assertEquals( 'First message', $messages[0]['content']['text'] );
		$this->assertEquals( 'Second message', $messages[1]['content']['text'] );
		$this->assertEquals( 'Third message', $messages[2]['content']['text'] );

		wp_unregister_ability( 'test/tier4-prompt' );
	}

	/**
	 * Test Tier 4 with custom role.
	 */
	public function test_get_prompt_tier4_multi_text_with_role(): void {
		wp_set_current_user( 1 );

		$this->register_ability_in_hook(
			'test/tier4-role-prompt',
			array(
				'label'               => 'Tier 4 Role Prompt',
				'description'         => 'Returns multi-text with role',
				'category'            => 'test',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'execute_callback'    => static function (): array {
					return array(
						'role'  => 'assistant',
						'texts' => array(
							'First assistant message',
							'Second assistant message',
						),
					);
				},
				'permission_callback' => '__return_true',
				'meta'                => array(
					'mcp' => array(
						'public' => true,
						'type'   => 'prompt',
					),
				),
			)
		);

		$server  = $this->makeServer( array(), array(), array( 'test/tier4-role-prompt' ) );
		$handler = new PromptsHandler( $server );

		$result   = $handler->get_prompt(
			array(
				'params' => array(
					'name'      => 'test-tier4-role-prompt',
					'arguments' => array(),
				),
			)
		);
		$messages = $result['messages'];
		$this->assertCount( 2, $messages );

		// All messages should have 'assistant' role.
		foreach ( $messages as $message ) {
			$this->assertEquals( 'assistant', $message['role'] );
		}

		wp_unregister_ability( 'test/tier4-role-prompt' );
	}

	/**
	 * Test Tier 5: Fallback JSON encoding for arbitrary data.
	 */
	public function test_get_prompt_tier5_fallback_json(): void {
		wp_set_current_user( 1 );

		$this->register_ability_in_hook(
			'test/tier5-prompt',
			array(
				'label'               => 'Tier 5 Prompt',
				'description'         => 'Returns arbitrary data',
				'category'            => 'test',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'execute_callback'    => static function (): array {
					return array(
						'custom_key' => 'custom_value',
						'nested'     => array(
							'data' => 123,
						),
					);
				},
				'permission_callback' => '__return_true',
				'meta'                => array(
					'mcp' => array(
						'public' => true,
						'type'   => 'prompt',
					),
				),
			)
		);

		$server  = $this->makeServer( array(), array(), array( 'test/tier5-prompt' ) );
		$handler = new PromptsHandler( $server );

		$result   = $handler->get_prompt(
			array(
				'params' => array(
					'name'      => 'test-tier5-prompt',
					'arguments' => array(),
				),
			)
		);
		$messages = $result['messages'];
		$this->assertCount( 1, $messages );
		$this->assertEquals( 'user', $messages[0]['role'] );

		// Content should be JSON-encoded.
		$content = $messages[0]['content'];
		$this->assertEquals( 'text', $content['type'] );

		$decoded = json_decode( $content['text'], true );
		$this->assertEquals( 'custom_value', $decoded['custom_key'] );
		$this->assertEquals( 123, $decoded['nested']['data'] );

		wp_unregister_ability( 'test/tier5-prompt' );
	}

	/**
	 * Test description passthrough at result level.
	 */
	public function test_get_prompt_description_passthrough(): void {
		wp_set_current_user( 1 );

		$this->register_ability_in_hook(
			'test/description-prompt',
			array(
				'label'               => 'Description Prompt',
				'description'         => 'Original description',
				'category'            => 'test',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'execute_callback'    => static function (): array {
					return array(
						'description' => 'Custom runtime description',
						'text'        => 'Message content',
					);
				},
				'permission_callback' => '__return_true',
				'meta'                => array(
					'mcp' => array(
						'public' => true,
						'type'   => 'prompt',
					),
				),
			)
		);

		$server  = $this->makeServer( array(), array(), array( 'test/description-prompt' ) );
		$handler = new PromptsHandler( $server );

		$result = $handler->get_prompt(
			array(
				'params' => array(
					'name'      => 'test-description-prompt',
					'arguments' => array(),
				),
			)
		);
		$this->assertEquals( 'Custom runtime description', $result['description'] );

		wp_unregister_ability( 'test/description-prompt' );
	}

	/**
	 * Test invalid role falls back to 'user'.
	 */
	public function test_get_prompt_invalid_role_defaults_to_user(): void {
		wp_set_current_user( 1 );

		$this->register_ability_in_hook(
			'test/invalid-role-prompt',
			array(
				'label'               => 'Invalid Role Prompt',
				'description'         => 'Returns invalid role',
				'category'            => 'test',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'execute_callback'    => static function (): array {
					return array(
						'role'    => 'invalid_role',
						'content' => array(
							'type' => 'text',
							'text' => 'Message content',
						),
					);
				},
				'permission_callback' => '__return_true',
				'meta'                => array(
					'mcp' => array(
						'public' => true,
						'type'   => 'prompt',
					),
				),
			)
		);

		$server  = $this->makeServer( array(), array(), array( 'test/invalid-role-prompt' ) );
		$handler = new PromptsHandler( $server );

		$result   = $handler->get_prompt(
			array(
				'params' => array(
					'name'      => 'test-invalid-role-prompt',
					'arguments' => array(),
				),
			)
		);
		$messages = $result['messages'];
		$this->assertCount( 1, $messages );
		// Invalid role should fall back to 'user'.
		$this->assertEquals( 'user', $messages[0]['role'] );

		wp_unregister_ability( 'test/invalid-role-prompt' );
	}

	/**
	 * Test Tier 1 with content type validation.
	 */
	public function test_get_prompt_tier1_with_image_content(): void {
		wp_set_current_user( 1 );

		$this->register_ability_in_hook(
			'test/image-content-prompt',
			array(
				'label'               => 'Image Content Prompt',
				'description'         => 'Returns image content',
				'category'            => 'test',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'execute_callback'    => static function (): array {
					return array(
						'messages' => array(
							array(
								'role'    => 'user',
								'content' => array(
									'type'     => 'image',
									'data'     => 'base64encodeddata',
									'mimeType' => 'image/png',
								),
							),
						),
					);
				},
				'permission_callback' => '__return_true',
				'meta'                => array(
					'mcp' => array(
						'public' => true,
						'type'   => 'prompt',
					),
				),
			)
		);

		$server  = $this->makeServer( array(), array(), array( 'test/image-content-prompt' ) );
		$handler = new PromptsHandler( $server );

		$result   = $handler->get_prompt(
			array(
				'params' => array(
					'name'      => 'test-image-content-prompt',
					'arguments' => array(),
				),
			)
		);
		$messages = $result['messages'];
		$this->assertCount( 1, $messages );

		$content = $messages[0]['content'];
		$this->assertEquals( 'image', $content['type'] );

		wp_unregister_ability( 'test/image-content-prompt' );
	}

	/**
	 * Test invalid content type is converted to text.
	 */
	public function test_get_prompt_invalid_content_type_converts_to_text(): void {
		wp_set_current_user( 1 );

		$this->register_ability_in_hook(
			'test/invalid-content-prompt',
			array(
				'label'               => 'Invalid Content Prompt',
				'description'         => 'Returns invalid content type',
				'category'            => 'test',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'execute_callback'    => static function (): array {
					return array(
						'messages' => array(
							array(
								'role'    => 'user',
								'content' => array(
									'type'  => 'invalid_type',
									'value' => 'some value',
								),
							),
						),
					);
				},
				'permission_callback' => '__return_true',
				'meta'                => array(
					'mcp' => array(
						'public' => true,
						'type'   => 'prompt',
					),
				),
			)
		);

		$server  = $this->makeServer( array(), array(), array( 'test/invalid-content-prompt' ) );
		$handler = new PromptsHandler( $server );

		$result   = $handler->get_prompt(
			array(
				'params' => array(
					'name'      => 'test-invalid-content-prompt',
					'arguments' => array(),
				),
			)
		);
		$messages = $result['messages'];
		$this->assertCount( 1, $messages );

		// Invalid content type should be converted to text.
		$content = $messages[0]['content'];
		$this->assertEquals( 'text', $content['type'] );

		// The text should be JSON-encoded original content.
		$decoded = json_decode( $content['text'], true );
		$this->assertEquals( 'invalid_type', $decoded['type'] );
		$this->assertEquals( 'some value', $decoded['value'] );

		wp_unregister_ability( 'test/invalid-content-prompt' );
	}

	public function test_get_prompt_with_string_arguments_returns_invalid_params_error(): void {
		$server  = $this->makeServer( array(), array(), array( 'test/always-allowed' ) );
		$handler = new PromptsHandler( $server );
		$result  = $handler->get_prompt(
			array(
				'params' => array(
					'name'      => 'test-always-allowed',
					'arguments' => 'invalid',
				),
			),
			1
		);

		$this->assertSame( -32602, $result['error']['code'] );
		$this->assertStringContainsString( 'arguments must be an object', $result['error']['message'] );
	}

	public function test_get_prompt_with_integer_arguments_returns_invalid_params_error(): void {
		$server  = $this->makeServer( array(), array(), array( 'test/always-allowed' ) );
		$handler = new PromptsHandler( $server );
		$result  = $handler->get_prompt(
			array(
				'params' => array(
					'name'      => 'test-always-allowed',
					'arguments' => 42,
				),
			),
			1
		);

		$this->assertSame( -32602, $result['error']['code'] );
		$this->assertStringContainsString( 'arguments must be an object', $result['error']['message'] );
	}

	public function test_get_prompt_with_null_arguments_succeeds(): void {
		$server  = $this->makeServer( array(), array(), array( 'test/always-allowed' ) );
		$handler = new PromptsHandler( $server );
		$result  = $handler->get_prompt(
			array(
				'params' => array(
					'name'      => 'test-always-allowed',
					'arguments' => null,
				),
			)
		);

		// null arguments should default to empty array and succeed.
		$this->assertNotEmpty( $result['messages'] );
	}

	public function test_get_prompt_with_missing_arguments_succeeds(): void {
		$server  = $this->makeServer( array(), array(), array( 'test/always-allowed' ) );
		$handler = new PromptsHandler( $server );
		$result  = $handler->get_prompt(
			array(
				'params' => array(
					'name' => 'test-always-allowed',
				),
			)
		);

		// Missing arguments should default to empty array and succeed.
		$this->assertNotEmpty( $result['messages'] );
	}

	public function test_list_prompts_with_filter_returning_non_array_falls_back_to_original(): void {
		$server  = $this->makeServer( array(), array(), array( 'test/always-allowed' ) );
		$handler = new PromptsHandler( $server );

		$filter = static function (): string {
			return 'not an array';
		};
		add_filter( 'mcp_adapter_prompts_list', $filter );

		DummyErrorHandler::reset();
		$result = $handler->list_prompts();
		$this->assertNotEmpty( $result['prompts'] );

		$this->assertNotEmpty( DummyErrorHandler::$logs );
		$last_log = end( DummyErrorHandler::$logs );
		$this->assertSame( 'warning', $last_log['type'] );
		$this->assertStringContainsString( 'mcp_adapter_prompts_list', $last_log['context']['filter'] );

		remove_filter( 'mcp_adapter_prompts_list', $filter );
	}

	// =========================================================================
	// Message Content Block Normalization
	//
	// Prompt messages already accept protocol-shaped content blocks. These tests pin
	// valid `_meta` passthrough and omission of PHP lists at both metadata levels.
	// =========================================================================

	/**
	 * The nested form of a resource content block has two levels that each carry
	 * `_meta`; the handler validates each independently.
	 */
	public function test_embedded_resource_contents_meta_that_is_a_list_is_omitted(): void {
		$result = $this->get_prompt_returning(
			array(
				'messages' => array(
					array(
						'role'    => 'user',
						'content' => array(
							'type'     => 'resource',
							'_meta'    => array( 'a', 'b' ),
							'resource' => array(
								'uri'   => 'WordPress://local/prompt-embedded',
								'text'  => 'body',
								'_meta' => array( 'c', 'd' ),
							),
						),
					),
				),
			)
		);

		$block = $this->first_content_block( $result );
		$this->assertArrayNotHasKey( '_meta', $block );
		$this->assertArrayNotHasKey( '_meta', $block['resource'] );
		$this->assertSame( 'body', $block['resource']['text'] );
	}

	public function test_embedded_resource_contents_meta_object_is_preserved(): void {
		$result = $this->get_prompt_returning(
			array(
				'messages' => array(
					array(
						'role'    => 'user',
						'content' => array(
							'type'     => 'resource',
							'_meta'    => array( 'block' => 'level' ),
							'resource' => array(
								'uri'   => 'WordPress://local/prompt-embedded',
								'text'  => 'body',
								'_meta' => array( 'ui' => array( 'prefersBorder' => true ) ),
							),
						),
					),
				),
			)
		);
		$this->assertSame( array( 'block' => 'level' ), $this->first_content_block( $result )['_meta'] );
		$this->assertSame(
			array( 'ui' => array( 'prefersBorder' => true ) ),
			$this->first_content_block( $result )['resource']['_meta']
		);
	}

	/**
	 * Run a prompt whose result is replaced by $shape, so any result shape can be driven
	 * through the handler's normalization without registering a new ability per case.
	 *
	 * @param array $shape The prompt result to normalize.
	 *
	 * @return array
	 */
	private function get_prompt_returning( array $shape ) {
		$server  = $this->makeServer( array(), array(), array( 'test/prompt' ) );
		$handler = new PromptsHandler( $server );

		$filter = static function () use ( $shape ) {
			return $shape;
		};
		add_filter( 'mcp_adapter_prompt_get_result', $filter );

		$result = $handler->get_prompt(
			array(
				'params' => array(
					'name'      => 'test-prompt',
					'arguments' => array( 'code' => 'x' ),
				),
			),
			1
		);

		remove_filter( 'mcp_adapter_prompt_get_result', $filter );

		return $result;
	}

	/**
	 * The emitted array of the first message's content block.
	 *
	 * @param array $result The prompt result.
	 *
	 * @return array
	 */
	private function first_content_block( array $result ): array {
		return $result['messages'][0]['content'];
	}
}
