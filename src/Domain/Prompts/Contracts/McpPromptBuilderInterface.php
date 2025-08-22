<?php
/**
 * Interface for MCP Prompt Builders.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Domain\Prompts\Contracts;

use WP\MCP\Domain\Prompts\McpPrompt;

/**
 * Interface for building MCP prompts.
 *
 * Classes implementing this interface can be passed directly to McpServer::register_prompts()
 * instead of requiring WordPress abilities.
 */
interface McpPromptBuilderInterface {

	/**
	 * Build and return the MCP prompt instance.
	 *
	 * @return \WP\MCP\Domain\Prompts\McpPrompt The built prompt.
	 */
	public function build(): McpPrompt;

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
