<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Prompts;

use WP\MCP\Domain\Prompts\RegisterAbilityAsMcpPrompt;
use WP\MCP\Tests\TestCase;

final class RegisterAbilityAsMcpPromptTest extends TestCase {

	public function test_make_builds_prompt_from_ability(): void {
		$ability = wp_get_ability( 'test/prompt' );
		$this->assertNotNull( $ability, 'Ability test/prompt should be registered' );
		$prompt  = RegisterAbilityAsMcpPrompt::make( $ability, $this->makeServer() );
		$arr     = $prompt->to_array();
		$this->assertSame( 'test-prompt', $arr['name'] );
		$this->assertArrayHasKey( 'arguments', $arr );
		$this->assertSame( $ability, $prompt->get_ability() );
	}
}
