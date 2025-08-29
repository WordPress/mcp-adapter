<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Prompts;

use WP\MCP\Core\McpServer;
use WP\MCP\Domain\Prompts\RegisterAbilityAsMcpPrompt;
use WP\MCP\Tests\Fixtures\DummyAbility;
use WP\MCP\Tests\Fixtures\DummyErrorHandler;
use WP\MCP\Tests\Fixtures\DummyObservabilityHandler;
use WP\MCP\Tests\TestCase;

final class RegisterAbilityAsMcpPromptTest extends TestCase {

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		do_action( 'abilities_api_init' );
		DummyAbility::register_all();
	}

	private function makeServer(): McpServer {
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
		);
	}

	public function test_make_builds_prompt_from_ability(): void {
		$ability = wp_get_ability( 'test/prompt' );
		$prompt  = RegisterAbilityAsMcpPrompt::make( $ability, $this->makeServer() );
		$arr     = $prompt->to_array();
		$this->assertSame( 'test-prompt', $arr['name'] );
		$this->assertArrayHasKey( 'arguments', $arr );
		$this->assertSame( $ability, $prompt->get_ability() );
	}
}
