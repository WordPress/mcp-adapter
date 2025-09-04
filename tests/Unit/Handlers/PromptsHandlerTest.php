<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Handlers;

use WP\MCP\Core\McpServer;
use WP\MCP\Handlers\Prompts\PromptsHandler;
use WP\MCP\Tests\Fixtures\DummyAbility;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\Fixtures\DummyObservabilityHandler;
use WP\MCP\Tests\TestCase;

final class PromptsHandlerTest extends TestCase {

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		do_action( 'abilities_api_init' );
		DummyAbility::register_all();
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

	public function test_list_prompts_returns_registered_prompts(): void {
		wp_set_current_user( 1 );
		$server  = $this->makeServer( array( 'test/prompt' ) );
		$handler = new PromptsHandler( $server );
		$res     = $handler->list_prompts();
		$this->assertArrayHasKey( 'prompts', $res );
		$this->assertNotEmpty( $res['prompts'] );
	}

	public function test_get_prompt_missing_name_returns_error(): void {
		$server  = $this->makeServer( array( 'test/prompt' ) );
		$handler = new PromptsHandler( $server );
		$res     = $handler->get_prompt( array( 'params' => array() ) );
		$this->assertArrayHasKey( 'error', $res );
	}

	public function test_get_prompt_unknown_returns_error(): void {
		$server  = $this->makeServer( array( 'test/prompt' ) );
		$handler = new PromptsHandler( $server );
		$res     = $handler->get_prompt( array( 'params' => array( 'name' => 'unknown' ) ) );
		$this->assertArrayHasKey( 'error', $res );
	}

	public function test_get_prompt_success_runs_ability(): void {
		$server  = $this->makeServer( array( 'test/prompt' ) );
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
	}
}
