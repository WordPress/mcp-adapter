<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Integration;

use WP\MCP\Domain\Prompts\McpPromptBuilder;
use WP\MCP\Handlers\Prompts\PromptsHandler;
use WP\MCP\Tests\TestCase;
use WP\McpSchema\Common\JsonRpc\DTO\JSONRPCErrorResponse;
use WP\McpSchema\Server\Prompts\DTO\GetPromptResult;
use WP\McpSchema\Server\Prompts\DTO\PromptMessage;

// Test prompt that requires admin permissions
class AdminOnlyPrompt extends McpPromptBuilder {

	protected function configure(): void {
		$this->name        = 'admin-only-test';
		$this->title       = 'Admin Only Test';
		$this->description = 'A test prompt that requires admin permissions';
		$this->arguments   = array(
			$this->create_argument( 'action', 'Action to perform', true ),
		);
	}

	public function has_permission( array $arguments ): bool {
		// Always deny for testing purposes - regardless of WordPress user permissions
		return false;
	}

	public function handle( array $arguments ): array {
		return array(
			'success'         => true,
			'action'          => $arguments['action'] ?? 'none',
			'user_can_manage' => current_user_can( 'manage_options' ),
		);
	}
}

// Test prompt that always allows execution
// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
class OpenPrompt extends McpPromptBuilder {

	protected function configure(): void {
		$this->name        = 'open-test';
		$this->title       = 'Open Test';
		$this->description = 'A test prompt that allows all users';
		$this->arguments   = array();
	}

	public function has_permission( array $arguments ): bool {
		return true; // Always allow
	}

	public function handle( array $arguments ): array {
		return array(
			'message'   => 'Hello from open prompt!',
			'timestamp' => current_time( 'c' ),
		);
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
final class BuilderPromptExecutionTest extends TestCase {

	public function test_builder_prompt_execution_through_handler(): void {
		$server = $this->makeServer( array(), array(), array( OpenPrompt::class ) );

		$handler = new PromptsHandler( $server );

		// Test successful execution.
		$result = $handler->get_prompt(
			array(
				'name'      => 'open-test',
				'arguments' => array(),
			)
		);

		// Builder prompts return GetPromptResult DTO.
		// The arbitrary result is wrapped as JSON in a text message.
		$this->assertInstanceOf( GetPromptResult::class, $result );
		$messages = $result->getMessages();
		$this->assertNotEmpty( $messages );
		$this->assertContainsOnlyInstancesOf( PromptMessage::class, $messages );

		// The wrapped content contains the original data as JSON.
		$content = $messages[0]->getContent();
		$text    = $content->toArray()['text'] ?? '';
		$this->assertStringContainsString( 'Hello from open prompt!', $text );
	}

	public function test_builder_prompt_permission_denied(): void {
		$server = $this->makeServer( array(), array(), array( AdminOnlyPrompt::class ) );

		$handler = new PromptsHandler( $server );

		// Test permission denied (always denies in test).
		$result = $handler->get_prompt(
			array(
				'name'      => 'admin-only-test',
				'arguments' => array( 'action' => 'delete_everything' ),
			)
		);

		// Should return permission denied error as JSONRPCErrorResponse.
		$this->assertInstanceOf( JSONRPCErrorResponse::class, $result );
		$error = $result->getError();
		$this->assertNotNull( $error );
		$this->assertStringContainsString( 'Access denied', $error->getMessage() );
	}

	public function test_mixed_ability_and_builder_prompts(): void {
		// Register both builder and ability-based prompts
		$server = $this->makeServer(
			array(),
			array(),
			array(
				OpenPrompt::class,           // Builder-based
				'fake/ability-prompt',       // Ability-based (will fail to register)
			)
		);

		$prompts = $server->get_prompts();

		// Should have the builder prompt even if ability registration failed
		$this->assertArrayHasKey( 'open-test', $prompts );
		$this->assertNotNull( $server->get_prompt_builder( 'open-test' ) );
	}

	public function test_builder_prompt_bypasses_abilities_completely(): void {
		$server = $this->makeServer( array(), array(), array( OpenPrompt::class ) );

		$prompt = $server->get_prompt( 'open-test' );
		$this->assertNotNull( $prompt );

		$builder = $server->get_prompt_builder( 'open-test' );
		$this->assertNotNull( $builder );
		$this->assertTrue( $builder->has_permission( array() ) );

		$result = $builder->handle( array() );
		$this->assertSame( 'Hello from open prompt!', $result['message'] );
	}
}
