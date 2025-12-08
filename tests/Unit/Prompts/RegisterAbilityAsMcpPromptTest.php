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

	public function test_annotations_are_mapped_to_mcp_format(): void {
		$ability = wp_get_ability( 'test/prompt-with-annotations' );
		$this->assertNotNull( $ability, 'Ability test/prompt-with-annotations should be registered' );

		$prompt = RegisterAbilityAsMcpPrompt::make( $ability, $this->makeServer() );
		$this->assertNotWPError( $prompt );

		$arr = $prompt->to_array();

		// Verify MCP-format annotations.
		$this->assertArrayHasKey( 'annotations', $arr );
		$this->assertArrayHasKey( 'audience', $arr['annotations'] );
		$this->assertArrayHasKey( 'lastModified', $arr['annotations'] );
		$this->assertArrayHasKey( 'priority', $arr['annotations'] );

		// Verify values.
		$this->assertIsArray( $arr['annotations']['audience'] );
		$this->assertContains( 'user', $arr['annotations']['audience'] );
		$this->assertSame( '2024-01-15T10:30:00Z', $arr['annotations']['lastModified'] );
		$this->assertSame( 0.9, $arr['annotations']['priority'] );
	}

	public function test_partial_annotations_are_included(): void {
		$ability = wp_get_ability( 'test/prompt-partial-annotations' );
		$this->assertNotNull( $ability, 'Ability test/prompt-partial-annotations should be registered' );

		$prompt = RegisterAbilityAsMcpPrompt::make( $ability, $this->makeServer() );
		$this->assertNotWPError( $prompt );

		$arr = $prompt->to_array();

		// Verify only provided annotations are present.
		$this->assertArrayHasKey( 'annotations', $arr );
		$this->assertArrayHasKey( 'audience', $arr['annotations'] );
		$this->assertContains( 'assistant', $arr['annotations']['audience'] );
		$this->assertArrayNotHasKey( 'lastModified', $arr['annotations'] );
		$this->assertArrayNotHasKey( 'priority', $arr['annotations'] );
	}

	public function test_empty_annotations_are_not_included(): void {
		$ability = wp_get_ability( 'test/prompt' );
		$this->assertNotNull( $ability, 'Ability test/prompt should be registered' );

		$prompt = RegisterAbilityAsMcpPrompt::make( $ability, $this->makeServer() );
		$this->assertNotWPError( $prompt );

		$arr = $prompt->to_array();

		// Verify annotations field is not present when empty.
		$this->assertArrayNotHasKey( 'annotations', $arr );
	}
}
