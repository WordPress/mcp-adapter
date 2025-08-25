<?php
/**
 * Abstract base class for building MCP prompts.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Domain\Prompts;

use WP\MCP\Domain\Prompts\Contracts\McpPromptBuilderInterface;
use WP_Ability;

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
	 * Build and return the MCP prompt instance.
	 *
	 * @return \WP\MCP\Domain\Prompts\McpPrompt The built prompt.
	 * @throws \InvalidArgumentException If validation fails.
	 */
	public function build(): McpPrompt {
		$this->configure();

		if ( empty( $this->name ) ) {
			throw new \InvalidArgumentException( 'Prompt name is required' );
		}

		// Create a synthetic ability name for the prompt
		$synthetic_ability = 'synthetic/' . $this->name;

		// Create a builder-based prompt that completely bypasses abilities
		$builder = $this;
		$prompt  = new class(
			$synthetic_ability,
			$this->name,
			$this->title,
			$this->description,
			$this->arguments,
			$builder
		) extends McpPrompt {
			private McpPromptBuilderInterface $builder;

			public function __construct(
				string $ability,
				string $name,
				?string $title,
				?string $description,
				array $arguments,
				McpPromptBuilderInterface $builder
			) {
				parent::__construct( $ability, $name, $title, $description, $arguments );
				$this->builder = $builder;
			}

			// This prompt is builder-based and doesn't need abilities
			public function is_builder_based(): bool {
				return true;
			}

			// Direct execution through the builder
			public function execute_direct( array $arguments ): array {
				return $this->builder->handle( $arguments );
			}

			// Direct permission checking through the builder
			public function check_permission_direct( array $arguments ): bool {
				return $this->builder->has_permission( $arguments );
			}

			// Fallback for ability-based execution (should not be used)
			public function get_ability(): ?WP_Ability {
				// This should not be called for builder-based prompts
				return null;
			}
		};

		return $prompt;
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
}
