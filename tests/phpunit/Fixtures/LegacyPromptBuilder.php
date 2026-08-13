<?php
/**
 * Baseline-compatible third-party prompt builder fixture.
 *
 * @package WP\MCP\Tests
 */

declare( strict_types=1 );

namespace WP\MCP\Tests\Fixtures;

use WP\MCP\Domain\Prompts\Contracts\McpPromptBuilderInterface;
use WP\McpSchema\Server\Prompts\DTO\Prompt;

final class LegacyPromptBuilder implements McpPromptBuilderInterface {

	public function build(): Prompt {
		return Prompt::fromArray( array( 'name' => 'legacy-builder-prompt' ) );
	}

	public function get_name(): string {
		return 'legacy-builder-prompt';
	}

	public function get_title(): ?string {
		return null;
	}

	public function get_description(): ?string {
		return null;
	}

	public function get_arguments(): array {
		return array();
	}

	public function handle( array $arguments ): array {
		return array( 'messages' => array() );
	}

	public function has_permission( array $arguments ): bool {
		return true;
	}
}
