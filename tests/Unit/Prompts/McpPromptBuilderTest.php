<?php

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Prompts;

use WP\MCP\Domain\Prompts\McpPromptBuilder;
use WP\MCP\Tests\TestCase;

// Test prompt class
class TestPrompt extends McpPromptBuilder {

	protected function configure(): void {
		$this->name        = 'test-prompt';
		$this->title       = 'Test Prompt';
		$this->description = 'A test prompt for unit testing';
		$this->arguments   = array(
			$this->create_argument( 'input', 'Test input', true ),
			$this->create_argument( 'optional', 'Optional parameter', false ),
		);
	}

	public function handle( array $arguments ): array {
		return array(
			'result'   => 'success',
			'input'    => $arguments['input'] ?? 'no input',
			'optional' => $arguments['optional'] ?? 'default',
		);
	}

	public function has_permission( array $arguments ): bool {
		// Test permission logic - always allow for testing
		return true;
	}
}

final class McpPromptBuilderTest extends TestCase {

	public function test_builder_creates_prompt(): void {
		$builder = new TestPrompt();
		$prompt  = $builder->build();

		$this->assertSame( 'test-prompt', $prompt->get_name() );
		$this->assertSame( 'Test Prompt', $prompt->get_title() );
		$this->assertSame( 'A test prompt for unit testing', $prompt->get_description() );

		$arguments = $prompt->get_arguments();
		$this->assertCount( 2, $arguments );
		$this->assertSame( 'input', $arguments[0]['name'] );
		$this->assertTrue( $arguments[0]['required'] );
		$this->assertSame( 'optional', $arguments[1]['name'] );
		$this->assertArrayNotHasKey( 'required', $arguments[1] );
	}

	public function test_prompt_can_be_registered_with_server(): void {
		$server = $this->makeServer( array(), array(), array( TestPrompt::class ) );

		$prompts = $server->get_prompts();
		$this->assertCount( 1, $prompts );
		$this->assertArrayHasKey( 'test-prompt', $prompts );

		$prompt = $server->get_prompt( 'test-prompt' );
		$this->assertNotNull( $prompt );
		$this->assertSame( 'test-prompt', $prompt->get_name() );
	}

	public function test_prompt_execution_bypasses_abilities(): void {
		$server = $this->makeServer( array(), array(), array( TestPrompt::class ) );

		$prompt = $server->get_prompt( 'test-prompt' );

		// Verify this is a builder-based prompt
		$this->assertTrue( $prompt->is_builder_based() );

		// Verify abilities are bypassed (get_ability returns WP_Error)
		$ability = $prompt->get_ability();
		$this->assertWPError( $ability );
		$this->assertEquals( 'builder_has_no_ability', $ability->get_error_code() );

		// Test direct permission checking
		$this->assertTrue( $prompt->check_permission_direct( array() ) );

		// Test direct execution
		$result = $prompt->execute_direct(
			array(
				'input'    => 'test value',
				'optional' => 'custom',
			)
		);
		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( 'test value', $result['input'] );
		$this->assertSame( 'custom', $result['optional'] );
	}

	public function test_mixed_registration_abilities_and_builders(): void {
		// This should work with mixed registration (though abilities won't exist in test)
		$server = $this->makeServer(
			array(),
			array(),
			array(
				TestPrompt::class,
				'some/fake-ability', // This will fail but shouldn't break the builder registration
			)
		);

		$prompts = $server->get_prompts();
		// Should have at least the builder prompt even if ability fails
		$this->assertArrayHasKey( 'test-prompt', $prompts );
	}
}
