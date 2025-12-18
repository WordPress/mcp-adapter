<?php
/**
 * Abstract base class for building MCP prompts.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Domain\Prompts;

use WP\MCP\Domain\Prompts\Contracts\McpPromptBuilderInterface;
use WP\MCP\Domain\Utils\McpValidator;
use WP\McpSchema\Server\Prompts\Prompt;
use WP\McpSchema\Server\Prompts\PromptArgument;

/**
 * Abstract base class for building MCP prompts.
 *
 * Extend this class to create custom prompts that can be registered
 * directly with McpServer without requiring WordPress abilities.
 */
abstract class McpPromptBuilder implements McpPromptBuilderInterface {

	/**
	 * The prompt name.
	 *
	 * @var string
	 */
	protected string $name;

	/**
	 * The prompt title.
	 *
	 * @var string|null
	 */
	protected ?string $title = null;

	/**
	 * The prompt description.
	 *
	 * @var string|null
	 */
	protected ?string $description = null;

	/**
	 * The prompt arguments.
	 *
	 * @var array
	 */
	protected array $arguments = array();

	/**
	 * The prompt icons for UI display.
	 *
	 * @since n.e.x.t
	 *
	 * @var array<int, array{src: string, mimeType?: string, sizes?: array<string>, theme?: string}>|null
	 */
	protected ?array $icons = null;

	/**
	 * Additional metadata passed through to MCP clients.
	 *
	 * Use this to attach purpose-specific metadata that MCP clients can consume.
	 * The internal 'mcp_adapter' key is stripped by MetaStripper before responding
	 * to clients, but your keys are preserved.
	 *
	 * @since n.e.x.t
	 *
	 * @var array<string, mixed>
	 */
	protected array $meta = array();

	/**
	 * Build and return the Prompt DTO instance.
	 *
	 * @return \WP\McpSchema\Server\Prompts\Prompt The built prompt DTO.
	 */
	public function build(): Prompt {
		$this->configure();

		$argument_dtos = null;
		if ( ! empty( $this->arguments ) ) {
			$argument_dtos = array_map(
				static function ( array $arg ): PromptArgument {
					return PromptArgument::fromArray(
						array(
							'name'        => $arg['name'],
							'title'       => $arg['title'] ?? null,
							'description' => $arg['description'] ?? null,
							'required'    => $arg['required'] ?? null,
						)
					);
				},
				$this->arguments
			);
		}

		// Validate and prepare icons if set.
		$valid_icons = null;
		if ( ! empty( $this->icons ) ) {
			$icons_result = McpValidator::validate_icons_array( $this->icons );
			if ( ! empty( $icons_result['valid'] ) ) {
				$valid_icons = $icons_result['valid'];
			}
		}

		// Builder prompts intentionally have no ability; they are executed via registry builder mapping.
		$_meta = array(
			'mcp_adapter' => array(
				'builder' => true,
			),
		);

		// Merge additional _meta with internal adapter metadata.
		// User keys are preserved alongside 'mcp_adapter'; MetaStripper strips 'mcp_adapter' for clients.
		if ( ! empty( $this->meta ) ) {
			$_meta = array_merge( $this->meta, $_meta );
		}

		$prompt_data = array(
			'name'        => $this->name,
			'title'       => $this->title,
			'description' => $this->description,
			'arguments'   => $argument_dtos,
			'_meta'       => $_meta,
		);

		// Only include icons if valid ones exist.
		if ( null !== $valid_icons ) {
			$prompt_data['icons'] = $valid_icons;
		}

		return Prompt::fromArray( $prompt_data );
	}

	/**
	 * Get the unique name for this prompt.
	 *
	 * @return string The prompt name.
	 */
	public function get_name(): string {
		if ( empty( $this->name ) ) {
			$this->configure();
		}

		return $this->name;
	}

	/**
	 * Get the prompt title.
	 *
	 * @return string|null The prompt title.
	 */
	public function get_title(): ?string {
		if ( empty( $this->name ) ) {
			$this->configure();
		}

		return $this->title;
	}

	/**
	 * Get the prompt description.
	 *
	 * @return string|null The prompt description.
	 */
	public function get_description(): ?string {
		if ( empty( $this->name ) ) {
			$this->configure();
		}

		return $this->description;
	}

	/**
	 * Get the prompt arguments.
	 *
	 * @return array The prompt arguments.
	 */
	public function get_arguments(): array {
		if ( empty( $this->name ) ) {
			$this->configure();
		}

		return $this->arguments;
	}

	/**
	 * Get the prompt icons.
	 *
	 * @since n.e.x.t
	 *
	 * @return array<int, array{src: string, mimeType?: string, sizes?: array<string>, theme?: string}> The prompt icons.
	 */
	public function get_icons(): array {
		if ( empty( $this->name ) ) {
			$this->configure();
		}

		return $this->icons ?? array();
	}

	/**
	 * Get the additional metadata.
	 *
	 * @since n.e.x.t
	 *
	 * @return array<string, mixed> The additional metadata.
	 */
	public function get_meta(): array {
		if ( empty( $this->name ) ) {
			$this->configure();
		}

		return $this->meta;
	}

	/**
	 * Configure the prompt properties.
	 *
	 * Subclasses must implement this method to set the name, title,
	 * description, and arguments for the prompt.
	 *
	 * @return void
	 */
	abstract protected function configure(): void;

	/**
	 * Handle the prompt execution when called.
	 *
	 * Subclasses must implement this method to handle the prompt logic.
	 *
	 * @param array $arguments The arguments passed to the prompt.
	 *
	 * @return array The prompt response.
	 */
	abstract public function handle( array $arguments ): array;

	/**
	 * Check if the current user has permission to execute this prompt.
	 *
	 * Default implementation allows all executions. Override this method
	 * to implement custom permission logic.
	 *
	 * @param array $arguments The arguments passed to the prompt.
	 *
	 * @return bool True if execution is allowed, false otherwise.
	 */
	public function has_permission( array $arguments ): bool {
		// Default: allow all executions
		// Override this method to implement custom permission logic
		return true;
	}

	/**
	 * Helper method to create an argument definition.
	 *
	 * @param string      $name The argument name.
	 * @param string|null $description Optional argument description.
	 * @param bool        $required Whether the argument is required.
	 *
	 * @return array The argument definition.
	 */
	protected function create_argument( string $name, ?string $description = null, bool $required = false ): array {
		$argument = array(
			'name' => $name,
		);

		if ( ! is_null( $description ) ) {
			$argument['description'] = $description;
		}

		if ( $required ) {
			$argument['required'] = true;
		}

		return $argument;
	}

	/**
	 * Helper method to add an argument to the prompt.
	 *
	 * @param string      $name The argument name.
	 * @param string|null $description Optional argument description.
	 * @param bool        $required Whether the argument is required.
	 *
	 * @return self
	 */
	protected function add_argument( string $name, ?string $description = null, bool $required = false ): self {
		$this->arguments[] = $this->create_argument( $name, $description, $required );

		return $this;
	}

	/**
	 * Set the prompt icons for UI display.
	 *
	 * Icons are validated during build() using McpValidator::validate_icons_array().
	 * Invalid icons are filtered out with warnings (graceful degradation).
	 *
	 * Per MCP 2025-11-25:
	 * - MUST support: image/png, image/jpeg, image/jpg
	 * - SHOULD support: image/svg+xml, image/webp
	 *
	 * @since n.e.x.t
	 *
	 * @param array<int, array{src: string, mimeType?: string, sizes?: array<string>, theme?: string}> $icons Array of icon definitions.
	 *
	 * @return self
	 */
	protected function set_icons( array $icons ): self {
		$this->icons = $icons;

		return $this;
	}

	/**
	 * Set additional metadata.
	 *
	 * This metadata is passed through to MCP clients alongside the adapter's internal metadata.
	 * The internal 'mcp_adapter' key is stripped by MetaStripper before responding to clients,
	 * but your keys are preserved.
	 *
	 * @since n.e.x.t
	 *
	 * @param array<string, mixed> $meta Additional metadata key-value pairs.
	 *
	 * @return self
	 */
	protected function set_meta( array $meta ): self {
		$this->meta = $meta;

		return $this;
	}
}
