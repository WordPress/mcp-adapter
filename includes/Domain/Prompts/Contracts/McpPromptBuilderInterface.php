<?php
/**
 * Interface for MCP Prompt Builders.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Domain\Prompts\Contracts;

/**
 * Interface for building MCP prompts.
 *
 * Classes implementing this interface can be passed directly to McpServer::register_prompts()
 * instead of requiring WordPress abilities.
 */
interface McpPromptBuilderInterface {

	/**
	 * Build and return the prompt data in wire shape.
	 *
	 * The returned array uses the MCP Prompt keys:
	 * - 'name' (string, required): Unique prompt identifier.
	 * - 'title' (string, optional): Human-readable display name.
	 * - 'description' (string, optional): Human-readable description.
	 * - 'arguments' (list<array{name: string, title?: string, description?: string, required?: bool}>, optional).
	 * - 'icons' (list<array<string, mixed>>, optional): Icon definitions for UI display.
	 * - '_meta' (array<string, mixed>, optional): Additional metadata for MCP clients.
	 *
	 * @return array<string, mixed> The built prompt data.
	 * @since n.e.x.t Returns a revision-neutral array instead of a DTO.
	 */
	public function build(): array;

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
