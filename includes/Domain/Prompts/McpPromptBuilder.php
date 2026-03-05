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
use WP\McpSchema\Server\Prompts\DTO\Prompt as PromptDto;
use WP\McpSchema\Server\Prompts\DTO\PromptArgument;

/**
 * Abstract base class for building MCP prompts.
 *
 * @deprecated 0.5.0 Use {@see \WP\MCP\Domain\Prompts\McpPrompt} instead for creating prompts.
 *
 * The fluent API or array configuration provided by McpPrompt is simpler
 * and more flexible than subclassing this abstract class:
 *
 * ```php
 * // Fluent API (preferred)
 * $prompt = McpPrompt::create('my-prompt')
 *     ->title('My Prompt')
 *     ->argument('input', 'Input text', true)
 *     ->handler(function(array $args): array {
 *         return ['messages' => [...]];
 *     });
 *
 * // Array configuration (WordPress-style)
 * $prompt = McpPrompt::fromArray([
 *     'name'      => 'my-prompt',
 *     'title'     => 'My Prompt',
 *     'arguments' => [['name' => 'input', 'description' => 'Input text', 'required' => true]],
 *     'handler'   => function(array $args): array { return [...]; },
 * ]);
 * ```
 *
 * This class remains functional for backward compatibility but will be
 * removed in a future major version.
 *
 * @see McpPrompt For the preferred prompt creation API.
 */
abstract class McpPromptBuilder implements McpPromptBuilderInterface {

	/**
	 * The prompt name (unique identifier).
	 *
	 * @var string
	 */
	protected string $name = '';

	/**
	 * The prompt title (human-readable display name).
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
	 * @var list<array{name: string, title?: string, description?: string, required?: bool}>
	 */
	protected array $arguments = array();

	/**
	 * The prompt icons for UI display.
	 *
	 * @since n.e.x.t
	 *
	 * @var list<array{src: string, mimeType?: string, sizes?: array<string>, theme?: string}>|null
	 */
	protected ?array $icons = null;

	/**
	 * Additional metadata passed through to MCP clients.
	 *
	 * Use this to attach purpose-specific metadata that MCP clients can consume.
	 * Keys are passed through unchanged.
	 *
	 * @since n.e.x.t
	 *
	 * @var array<string, mixed>
	 */
	protected array $meta = array();

	/**
	 * Constructor - automatically configures the prompt.
	 *
	 * Configuration happens exactly once during construction, ensuring
	 * idempotent behavior. Subclasses should NOT override this constructor;
	 * instead, implement the configure() method.
	 *
	 * @since n.e.x.t
	 */
	final public function __construct() {
		$this->configure();
	}

	/**
	 * Configure the prompt properties.
	 *
	 * Subclasses must implement this method to set the name, title,
	 * description, and arguments for the prompt. This method is called
	 * exactly once during construction.
	 *
	 * @return void
	 */
	abstract protected function configure(): void;

	/**
	 * Build and return the Prompt DTO instance.
	 *
	 * This method converts the configured state into an MCP Prompt DTO.
	 * Safe to call multiple times - always returns a fresh DTO based on
	 * the current (immutable after construction) state.
	 *
	 * @return \WP\McpSchema\Server\Prompts\DTO\Prompt The built prompt DTO.
	 */
	public function build(): PromptDto {
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

		$prompt_data = array(
			'name'        => $this->name,
			'title'       => $this->title,
			'description' => $this->description,
			'arguments'   => $argument_dtos,
		);

		if ( ! empty( $this->meta ) ) {
			$prompt_data['_meta'] = $this->meta;
		}

		// Only include icons if valid ones exist.
		if ( null !== $valid_icons ) {
			$prompt_data['icons'] = $valid_icons;
		}

		return PromptDto::fromArray( $prompt_data );
	}

	/**
	 * Get the unique name for this prompt.
	 *
	 * @return string The prompt name.
	 */
	public function get_name(): string {
		return $this->name;
	}

	/**
	 * Get the prompt title.
	 *
	 * @return string|null The prompt title.
	 */
	public function get_title(): ?string {
		return $this->title;
	}

	/**
	 * Get the prompt description.
	 *
	 * @return string|null The prompt description.
	 */
	public function get_description(): ?string {
		return $this->description;
	}

	/**
	 * Get the prompt arguments.
	 *
	 * @return list<array{name: string, title?: string, description?: string, required?: bool}> The prompt arguments.
	 */
	public function get_arguments(): array {
		return $this->arguments;
	}

	/**
	 * Get the prompt icons.
	 *
	 * @return list<array{src: string, mimeType?: string, sizes?: array<string>, theme?: string}> The prompt icons.
	 * @since n.e.x.t
	 *
	 */
	public function get_icons(): array {
		return $this->icons ?? array();
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
	 * @param list<array{src: string, mimeType?: string, sizes?: array<string>, theme?: string}> $icons Array of icon definitions.
	 *
	 * @return self
	 * @since n.e.x.t
	 *
	 */
	protected function set_icons( array $icons ): self {
		$this->icons = $icons;

		return $this;
	}

	/**
	 * Get the additional metadata.
	 *
	 * @return array<string, mixed> The additional metadata.
	 * @since n.e.x.t
	 *
	 */
	public function get_meta(): array {
		return $this->meta;
	}

	/**
	 * Set additional metadata.
	 *
	 * This metadata is passed through to MCP clients unchanged.
	 *
	 * @param array<string, mixed> $meta Additional metadata key-value pairs.
	 *
	 * @return self
	 * @since n.e.x.t
	 *
	 */
	protected function set_meta( array $meta ): self {
		$this->meta = $meta;

		return $this;
	}

	/**
	 * Handle the prompt execution when called.
	 *
	 * Subclasses must implement this method to handle the prompt logic.
	 *
	 * @param array<string, mixed> $arguments The arguments passed to the prompt.
	 *
	 * @return array<string, mixed> The prompt response.
	 */
	abstract public function handle( array $arguments ): array;

	/**
	 * Check if the current user has permission to execute this prompt.
	 *
	 * Default implementation allows all executions. Override this method
	 * to implement custom permission logic.
	 *
	 * @param array<string, mixed> $arguments The arguments passed to the prompt.
	 *
	 * @return bool True if execution is allowed, false otherwise.
	 */
	public function has_permission( array $arguments ): bool {
		// Default: allow all executions
		// Override this method to implement custom permission logic
		return true;
	}

	/**
	 * Helper method to add an argument to the prompt.
	 *
	 * @param string $name The argument name.
	 * @param string|null $description Optional argument description.
	 * @param bool $required Whether the argument is required.
	 *
	 * @return self
	 */
	protected function add_argument( string $name, ?string $description = null, bool $required = false ): self {
		$this->arguments[] = $this->create_argument( $name, $description, $required );

		return $this;
	}

	/**
	 * Helper method to create an argument definition.
	 *
	 * @param string $name The argument name.
	 * @param string|null $description Optional argument description.
	 * @param bool $required Whether the argument is required.
	 *
	 * @return array{name: string, description?: string, required?: true} The argument definition.
	 */
	protected function create_argument( string $name, ?string $description = null, bool $required = false ): array {
		$argument = array(
			'name' => $name,
		);

		if ( null !== $description ) {
			$argument['description'] = $description;
		}

		if ( $required ) {
			$argument['required'] = true;
		}

		return $argument;
	}
}
