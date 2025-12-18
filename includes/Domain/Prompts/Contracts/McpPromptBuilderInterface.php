<?php
/**
 * Interface for MCP Prompt Builders.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Domain\Prompts\Contracts;

use WP\McpSchema\Server\Prompts\Prompt;

/**
 * Interface for building MCP prompts.
 *
 * Classes implementing this interface can be passed directly to McpServer::register_prompts()
 * instead of requiring WordPress abilities.
 */
interface McpPromptBuilderInterface {

	/**
	 * Build and return the Prompt DTO instance.
	 *
	 * @return \WP\McpSchema\Server\Prompts\Prompt The built prompt DTO.
	 */
	public function build(): Prompt;

	/**
	 * Get the unique name for this prompt.
	 *
	 * @return string The prompt name.
	 */
	public function get_name(): string;

	/**
	 * Get the prompt title.
	 *
	 * @return string|null The prompt title.
	 */
	public function get_title(): ?string;

	/**
	 * Get the prompt description.
	 *
	 * @return string|null The prompt description.
	 */
	public function get_description(): ?string;

	/**
	 * Get the prompt arguments.
	 *
	 * @return array The prompt arguments.
	 */
	public function get_arguments(): array;

	/**
	 * Get the prompt icons for UI display.
	 *
	 * @since n.e.x.t
	 *
	 * @return array<int, array{src: string, mimeType?: string, sizes?: array<string>, theme?: string}> The prompt icons.
	 */
	public function get_icons(): array;

	/**
	 * Get the additional metadata.
	 *
	 * @since n.e.x.t
	 *
	 * @return array<string, mixed> The additional metadata.
	 */
	public function get_meta(): array;

	/**
	 * Handle the prompt execution when called.
	 *
	 * @param array $arguments The arguments passed to the prompt.
	 *
	 * @return array The prompt response.
	 */
	public function handle( array $arguments ): array;

	/**
	 * Check if the current user has permission to execute this prompt.
	 *
	 * @param array $arguments The arguments passed to the prompt.
	 *
	 * @return bool True if execution is allowed, false otherwise.
	 */
	public function has_permission( array $arguments ): bool;
}
