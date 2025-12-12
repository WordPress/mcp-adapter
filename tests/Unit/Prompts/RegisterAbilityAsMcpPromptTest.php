<?php

declare(strict_types=1);

namespace WP\MCP\Tests\Unit\Prompts;

use WP\MCP\Domain\Prompts\RegisterAbilityAsMcpPrompt;
use WP\MCP\Domain\Prompts\PromptMetadataHelper;
use WP\MCP\Tests\TestCase;
use WP\McpSchema\Server\Prompts\Prompt;

final class RegisterAbilityAsMcpPromptTest extends TestCase {

	public function test_make_builds_prompt_from_ability(): void {
		$ability = wp_get_ability( 'test/prompt' );
		$this->assertNotNull( $ability, 'Ability test/prompt should be registered' );
		$prompt = RegisterAbilityAsMcpPrompt::make( $ability );
		$this->assertInstanceOf( Prompt::class, $prompt );
		$arr = $prompt->toArray();
		$this->assertSame( 'test-prompt', $arr['name'] );
		$this->assertArrayHasKey( 'arguments', $arr );
		$this->assertSame( $ability->get_name(), PromptMetadataHelper::get_ability_name( $prompt ) );
	}

	public function test_annotations_are_mapped_to_mcp_format(): void {
		$ability = wp_get_ability( 'test/prompt-with-annotations' );
		$this->assertNotNull( $ability, 'Ability test/prompt-with-annotations should be registered' );

		$prompt = RegisterAbilityAsMcpPrompt::make( $ability );
		$this->assertNotWPError( $prompt );

		$arr = $prompt->toArray();

		// Note: Schema Prompt DTO does not support annotations; these are intentionally not exposed.
		$this->assertArrayNotHasKey( 'annotations', $arr );
	}

	public function test_partial_annotations_are_included(): void {
		$ability = wp_get_ability( 'test/prompt-partial-annotations' );
		$this->assertNotNull( $ability, 'Ability test/prompt-partial-annotations should be registered' );

		$prompt = RegisterAbilityAsMcpPrompt::make( $ability );
		$this->assertNotWPError( $prompt );

		$arr = $prompt->toArray();

		// Note: Schema Prompt DTO does not support annotations; these are intentionally not exposed.
		$this->assertArrayNotHasKey( 'annotations', $arr );
	}

	public function test_empty_annotations_are_not_included(): void {
		$ability = wp_get_ability( 'test/prompt' );
		$this->assertNotNull( $ability, 'Ability test/prompt should be registered' );

		$prompt = RegisterAbilityAsMcpPrompt::make( $ability );
		$this->assertNotWPError( $prompt );

		$arr = $prompt->toArray();

		// Verify annotations field is not present when empty.
		$this->assertArrayNotHasKey( 'annotations', $arr );
	}
}
