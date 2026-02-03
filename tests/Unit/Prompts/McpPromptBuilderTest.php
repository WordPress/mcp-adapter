<?php

declare( strict_types=1 );

namespace WP\MCP\Tests\Unit\Prompts;

use WP\MCP\Domain\Prompts\McpPromptBuilder;
use WP\MCP\Tests\TestCase;
use WP\McpSchema\Server\Prompts\DTO\Prompt as PromptDto;

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

// Test prompt with icons
class TestPromptWithIcons extends McpPromptBuilder {

	protected function configure(): void {
		$this->name        = 'test-prompt-with-icons';
		$this->title       = 'Test Prompt With Icons';
		$this->description = 'A test prompt with MCP icons';
		$this->set_icons(
			array(
				array(
					'src'      => 'https://example.com/icon.png',
					'mimeType' => 'image/png',
					'sizes'    => array( '32x32' ),
					'theme'    => 'light',
				),
				array(
					'src'      => 'https://example.com/icon-dark.svg',
					'mimeType' => 'image/svg+xml',
					'theme'    => 'dark',
				),
			)
		);
	}

	public function handle( array $arguments ): array {
		return array( 'result' => 'success' );
	}
}

// Test prompt with user _meta
class TestPromptWithMeta extends McpPromptBuilder {

	protected function configure(): void {
		$this->name        = 'test-prompt-with-meta';
		$this->title       = 'Test Prompt With Meta';
		$this->description = 'A test prompt with custom _meta';
		$this->set_meta(
			array(
				'custom_vendor' => array(
					'feature_flag' => true,
					'version'      => '2.0',
				),
				'another_key'   => 'some-value',
			)
		);
	}

	public function handle( array $arguments ): array {
		return array( 'result' => 'success' );
	}
}

// Test prompt with both icons and _meta
class TestPromptWithIconsAndMeta extends McpPromptBuilder {

	protected function configure(): void {
		$this->name        = 'test-prompt-icons-meta';
		$this->title       = 'Test Prompt Icons and Meta';
		$this->description = 'A test prompt with both icons and custom _meta';
		$this->set_icons(
			array(
				array(
					'src'      => 'https://example.com/combined-icon.png',
					'mimeType' => 'image/png',
					'sizes'    => array( '48x48' ),
				),
			)
		);
		$this->set_meta(
			array(
				'vendor_data' => array( 'key' => 'value' ),
			)
		);
	}

	public function handle( array $arguments ): array {
		return array( 'result' => 'success' );
	}
}

// Test prompt with invalid icons (missing src)
class TestPromptWithMixedIcons extends McpPromptBuilder {

	protected function configure(): void {
		$this->name        = 'test-prompt-mixed-icons';
		$this->title       = 'Test Prompt Mixed Icons';
		$this->description = 'A test prompt with some valid and invalid icons';
		$this->set_icons(
			array(
				array(
					'src'      => 'https://example.com/valid.png',
					'mimeType' => 'image/png',
				),
				array(
					// Missing src - invalid.
					'mimeType' => 'image/png',
				),
				array(
					'src'      => 'https://example.com/also-valid.svg',
					'mimeType' => 'image/svg+xml',
				),
			)
		);
	}

	public function handle( array $arguments ): array {
		return array( 'result' => 'success' );
	}
}

// Test prompt using add_argument() in configure() - simulates real-world usage
// like CodeReviewPrompt. This is the pattern that was vulnerable to argument
// accumulation when configure() was called multiple times.
// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
class TestPromptWithAddArgument extends McpPromptBuilder {

	protected function configure(): void {
		$this->name        = 'add-argument-test';
		$this->title       = 'Add Argument Test';
		$this->description = 'Tests add_argument() idempotence';

		// Using add_argument() - this pattern was the original source of the bug.
		$this->add_argument( 'code', 'The code to review', true );
		$this->add_argument( 'language', 'Programming language', false );
		$this->add_argument( 'focus', 'Review focus area', false );
	}

	public function handle( array $arguments ): array {
		return array( 'result' => 'success' );
	}
}

final class McpPromptBuilderTest extends TestCase {

	public function test_builder_creates_prompt(): void {
		$builder = new TestPrompt();
		$prompt  = $builder->build();

		$this->assertInstanceOf( PromptDto::class, $prompt );
		$this->assertSame( 'test-prompt', $prompt->getName() );
		$this->assertSame( 'Test Prompt', $prompt->getTitle() );
		$this->assertSame( 'A test prompt for unit testing', $prompt->getDescription() );

		$arguments = $prompt->getArguments();
		$this->assertCount( 2, $arguments );
		$this->assertSame( 'input', $arguments[0]->getName() );
		$this->assertTrue( $arguments[0]->getRequired() );
		$this->assertSame( 'optional', $arguments[1]->getName() );
		$this->assertNull( $arguments[1]->getRequired() );
	}

	public function test_prompt_can_be_registered_with_server(): void {
		$server = $this->makeServer( array(), array(), array( TestPrompt::class ) );

		$prompts = $server->get_prompts();
		$this->assertCount( 1, $prompts );
		$this->assertArrayHasKey( 'test-prompt', $prompts );

		$prompt = $server->get_prompt( 'test-prompt' );
		$this->assertNotNull( $prompt );
		$this->assertSame( 'test-prompt', $prompt->getName() );
	}

	public function test_prompt_execution_bypasses_abilities(): void {
		$server = $this->makeServer( array(), array(), array( TestPrompt::class ) );

		$prompt = $server->get_prompt( 'test-prompt' );
		$this->assertNotNull( $prompt );

		$builder = $server->get_prompt_builder( 'test-prompt' );
		$this->assertNotNull( $builder );
		$this->assertTrue( $builder->has_permission( array() ) );

		$result = $builder->handle(
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

	// =========================================================================
	// Icons Tests (MCP 2025-11-25)
	// =========================================================================

	public function test_builder_with_icons_includes_icons_in_prompt(): void {
		$builder = new TestPromptWithIcons();
		$prompt  = $builder->build();

		$this->assertInstanceOf( PromptDto::class, $prompt );

		$arr = $prompt->toArray();

		// Verify icons array is present.
		$this->assertArrayHasKey( 'icons', $arr );
		$this->assertIsArray( $arr['icons'] );
		$this->assertCount( 2, $arr['icons'] );

		// Verify first icon.
		$this->assertSame( 'https://example.com/icon.png', $arr['icons'][0]['src'] );
		$this->assertSame( 'image/png', $arr['icons'][0]['mimeType'] );
		$this->assertSame( array( '32x32' ), $arr['icons'][0]['sizes'] );
		$this->assertSame( 'light', $arr['icons'][0]['theme'] );

		// Verify second icon.
		$this->assertSame( 'https://example.com/icon-dark.svg', $arr['icons'][1]['src'] );
		$this->assertSame( 'image/svg+xml', $arr['icons'][1]['mimeType'] );
		$this->assertSame( 'dark', $arr['icons'][1]['theme'] );
	}

	public function test_builder_invalid_icons_are_filtered_out(): void {
		$builder = new TestPromptWithMixedIcons();
		$prompt  = $builder->build();

		$arr = $prompt->toArray();

		// Should have only 2 valid icons (one without src was filtered out).
		$this->assertArrayHasKey( 'icons', $arr );
		$this->assertCount( 2, $arr['icons'] );

		// Verify valid icons are present.
		$this->assertSame( 'https://example.com/valid.png', $arr['icons'][0]['src'] );
		$this->assertSame( 'https://example.com/also-valid.svg', $arr['icons'][1]['src'] );
	}

	public function test_builder_without_icons_has_no_icons_key(): void {
		$builder = new TestPrompt();
		$prompt  = $builder->build();

		$arr = $prompt->toArray();

		// Verify icons key is not present when builder has no icons.
		$this->assertArrayNotHasKey( 'icons', $arr );
	}

	public function test_get_icons_returns_configured_icons(): void {
		$builder = new TestPromptWithIcons();

		$icons = $builder->get_icons();

		$this->assertIsArray( $icons );
		$this->assertCount( 2, $icons );
		$this->assertSame( 'https://example.com/icon.png', $icons[0]['src'] );
	}

	public function test_get_icons_returns_empty_array_when_not_set(): void {
		$builder = new TestPrompt();

		$icons = $builder->get_icons();

		$this->assertIsArray( $icons );
		$this->assertEmpty( $icons );
	}

	// =========================================================================
	// User _meta Tests (MCP 2025-11-25)
	// =========================================================================

		public function test_builder_with_meta_includes_meta_in_prompt(): void {
			$builder = new TestPromptWithMeta();
			$prompt  = $builder->build();

		$arr = $prompt->toArray();

		// Verify _meta is present.
		$this->assertArrayHasKey( '_meta', $arr );

		// Verify custom_vendor key is preserved.
		$this->assertArrayHasKey( 'custom_vendor', $arr['_meta'] );
		$this->assertIsArray( $arr['_meta']['custom_vendor'] );
		$this->assertTrue( $arr['_meta']['custom_vendor']['feature_flag'] );
		$this->assertSame( '2.0', $arr['_meta']['custom_vendor']['version'] );

			// Verify another_key is preserved.
			$this->assertArrayHasKey( 'another_key', $arr['_meta'] );
			$this->assertSame( 'some-value', $arr['_meta']['another_key'] );
		}

		public function test_builder_without_user_meta_has_no_meta_field(): void {
			$builder = new TestPrompt();
			$prompt  = $builder->build();

			$arr = $prompt->toArray();

			$this->assertArrayNotHasKey( '_meta', $arr );
		}

	public function test_get_meta_returns_configured_meta(): void {
		$builder = new TestPromptWithMeta();

		$meta = $builder->get_meta();

		$this->assertIsArray( $meta );
		$this->assertArrayHasKey( 'custom_vendor', $meta );
		$this->assertArrayHasKey( 'another_key', $meta );
	}

	public function test_get_meta_returns_empty_array_when_not_set(): void {
		$builder = new TestPrompt();

		$meta = $builder->get_meta();

		$this->assertIsArray( $meta );
		$this->assertEmpty( $meta );
	}

	// =========================================================================
	// Combined Icons and _meta Tests
	// =========================================================================

	public function test_builder_with_both_icons_and_meta(): void {
		$builder = new TestPromptWithIconsAndMeta();
		$prompt  = $builder->build();

		$arr = $prompt->toArray();

		// Verify icons are present.
		$this->assertArrayHasKey( 'icons', $arr );
		$this->assertCount( 1, $arr['icons'] );
		$this->assertSame( 'https://example.com/combined-icon.png', $arr['icons'][0]['src'] );
		$this->assertSame( 'image/png', $arr['icons'][0]['mimeType'] );
		$this->assertSame( array( '48x48' ), $arr['icons'][0]['sizes'] );

			// Verify user _meta is present.
			$this->assertArrayHasKey( '_meta', $arr );
			$this->assertArrayHasKey( 'vendor_data', $arr['_meta'] );
			$this->assertSame( 'value', $arr['_meta']['vendor_data']['key'] );
		}

	public function test_builder_with_icons_can_be_registered_with_server(): void {
		$server = $this->makeServer( array(), array(), array( TestPromptWithIcons::class ) );

		$prompts = $server->get_prompts();
		$this->assertCount( 1, $prompts );
		$this->assertArrayHasKey( 'test-prompt-with-icons', $prompts );

		$prompt = $server->get_prompt( 'test-prompt-with-icons' );
		$this->assertNotNull( $prompt );

		$arr = $prompt->toArray();
		$this->assertArrayHasKey( 'icons', $arr );
		$this->assertCount( 2, $arr['icons'] );
	}

	public function test_builder_with_meta_can_be_registered_with_server(): void {
		$server = $this->makeServer( array(), array(), array( TestPromptWithMeta::class ) );

		$prompts = $server->get_prompts();
		$this->assertCount( 1, $prompts );
		$this->assertArrayHasKey( 'test-prompt-with-meta', $prompts );

		$prompt = $server->get_prompt( 'test-prompt-with-meta' );
		$this->assertNotNull( $prompt );

		$arr = $prompt->toArray();
		$this->assertArrayHasKey( '_meta', $arr );
		$this->assertArrayHasKey( 'custom_vendor', $arr['_meta'] );
	}

	// =========================================================================
	// Configuration Idempotence Tests
	// =========================================================================

	public function test_multiple_build_calls_do_not_accumulate_arguments(): void {
		$builder = new TestPromptWithAddArgument();

		// Call build() multiple times.
		$prompt1 = $builder->build();
		$prompt2 = $builder->build();
		$prompt3 = $builder->build();

		// All should have exactly 3 arguments (not accumulating).
		$this->assertCount( 3, $prompt1->getArguments() );
		$this->assertCount( 3, $prompt2->getArguments() );
		$this->assertCount( 3, $prompt3->getArguments() );
	}

	public function test_getters_do_not_accumulate_arguments(): void {
		$builder = new TestPromptWithAddArgument();

		// Call various getters multiple times.
		$builder->get_name();
		$builder->get_title();
		$builder->get_description();
		$builder->get_arguments();
		$builder->get_name();
		$builder->get_arguments();

		// Build should still have exactly 3 arguments.
		$prompt = $builder->build();
		$this->assertCount( 3, $prompt->getArguments() );
	}

	public function test_mixed_getter_and_build_calls_do_not_accumulate(): void {
		$builder = new TestPromptWithAddArgument();

		// Mix of getter and build calls (this was the original bug scenario).
		$builder->get_name();
		$builder->build();
		$builder->get_arguments();
		$builder->build();
		$builder->get_title();

		// Final build should still have exactly 3 arguments.
		$prompt = $builder->build();
		$this->assertCount( 3, $prompt->getArguments() );

		// Verify argument names are correct (no duplicates).
		$args      = $prompt->getArguments();
		$arg_names = array_map( fn( $arg ) => $arg->getName(), $args );
		$this->assertSame( array( 'code', 'language', 'focus' ), $arg_names );
	}

	public function test_constructor_calls_configure_exactly_once(): void {
		// This test verifies the structural guarantee: configure() is called
		// during construction, not lazily.
		$builder = new TestPromptWithAddArgument();

		// Immediately after construction, arguments should be populated.
		$this->assertCount( 3, $builder->get_arguments() );

		// Name should be set without triggering any additional configure() call.
		$this->assertSame( 'add-argument-test', $builder->get_name() );
	}
}
