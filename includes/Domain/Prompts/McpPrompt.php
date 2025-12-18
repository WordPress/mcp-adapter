<?php
/**
 * Fluent MCP Prompt definition.
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
 * Fluent prompt definition - the preferred way to create MCP prompts.
 *
 * This class provides two flexible ways to define prompts without subclassing:
 *
 * 1. Fluent API:
 * ```php
 * $prompt = McpPrompt::create('code-review')
 *     ->title('Code Review')
 *     ->description('Generate a comprehensive code review')
 *     ->argument('code', 'The code to review', true)
 *     ->argument('language', 'Programming language')
 *     ->handler(function(array $args): array {
 *         return ['messages' => [...]];
 *     })
 *     ->permission(function(array $args): bool {
 *         return current_user_can('edit_posts');
 *     });
 * ```
 *
 * 2. Array configuration:
 * ```php
 * $prompt = McpPrompt::fromArray([
 *     'name'        => 'code-review',
 *     'title'       => 'Code Review',
 *     'description' => 'Generate a comprehensive code review',
 *     'arguments'   => [
 *         ['name' => 'code', 'description' => 'The code to review', 'required' => true],
 *         ['name' => 'language', 'description' => 'Programming language'],
 *     ],
 *     'handler'    => function(array $args): array { return [...]; },
 *     'permission' => function(array $args): bool { return true; },
 * ]);
 * ```
 *
 * @since n.e.x.t
 */
class McpPrompt implements McpPromptBuilderInterface {

	/**
	 * The prompt name (unique identifier).
	 *
	 * @var string
	 */
	private string $name;

	/**
	 * The prompt title (human-readable display name).
	 *
	 * @var string|null
	 */
	private ?string $title = null;

	/**
	 * The prompt description.
	 *
	 * @var string|null
	 */
	private ?string $description = null;

	/**
	 * The prompt arguments.
	 *
	 * @var array<int, array{name: string, title?: string, description?: string, required?: bool}>
	 */
	private array $arguments = array();

	/**
	 * The prompt icons for UI display.
	 *
	 * @var array<int, array{src: string, mimeType?: string, sizes?: array<string>, theme?: string}>|null
	 */
	private ?array $icons = null;

	/**
	 * Additional metadata passed through to MCP clients.
	 *
	 * @var array<string, mixed>
	 */
	private array $meta = array();

	/**
	 * The handler callable for prompt execution.
	 *
	 * @var callable(array<string, mixed>): array<string, mixed>
	 */
	private $handler;

	/**
	 * The permission check callable.
	 *
	 * @var callable(array<string, mixed>): bool|null
	 */
	private $permission_callback = null;

	/**
	 * Private constructor - use create() or fromArray() factory methods.
	 *
	 * @param string $name The prompt name.
	 */
	private function __construct( string $name ) {
		$this->name = $name;
		// Default handler returns empty messages array.
		$this->handler = static function ( array $args ): array {
			return array( 'messages' => array() );
		};
	}

	/**
	 * Create a new prompt definition with fluent API.
	 *
	 * @param string $name The unique prompt name.
	 *
	 * @return self
	 */
	public static function create( string $name ): self {
		return new self( $name );
	}

	/**
	 * Create a prompt definition from an array configuration.
	 *
	 * @param array{
	 *     name: string,
	 *     title?: string,
	 *     description?: string,
	 *     arguments?: array<int, array{name: string, title?: string, description?: string, required?: bool}>,
	 *     icons?: array<int, array{src: string, mimeType?: string, sizes?: array<string>, theme?: string}>,
	 *     meta?: array<string, mixed>,
	 *     handler: callable(array<string, mixed>): array<string, mixed>,
	 *     permission?: callable(array<string, mixed>): bool
	 * } $config The prompt configuration array.
	 *
	 * @return self
	 *
	 * @throws \InvalidArgumentException If required fields are missing.
	 */
	public static function fromArray( array $config ): self {
		if ( empty( $config['name'] ) ) {
			throw new \InvalidArgumentException( 'Prompt configuration must include a "name" field.' );
		}

		if ( ! isset( $config['handler'] ) || ! is_callable( $config['handler'] ) ) {
			throw new \InvalidArgumentException( 'Prompt configuration must include a callable "handler" field.' );
		}

		$prompt = new self( $config['name'] );

		if ( isset( $config['title'] ) ) {
			$prompt->title = $config['title'];
		}

		if ( isset( $config['description'] ) ) {
			$prompt->description = $config['description'];
		}

		if ( isset( $config['arguments'] ) && is_array( $config['arguments'] ) ) {
			$prompt->arguments = $config['arguments'];
		}

		if ( isset( $config['icons'] ) && is_array( $config['icons'] ) ) {
			$prompt->icons = $config['icons'];
		}

		if ( isset( $config['meta'] ) && is_array( $config['meta'] ) ) {
			$prompt->meta = $config['meta'];
		}

		$prompt->handler = $config['handler'];

		if ( isset( $config['permission'] ) && is_callable( $config['permission'] ) ) {
			$prompt->permission_callback = $config['permission'];
		}

		return $prompt;
	}

	/**
	 * Set the prompt title.
	 *
	 * @param string $title The human-readable title.
	 *
	 * @return self
	 */
	public function title( string $title ): self {
		$this->title = $title;
		return $this;
	}

	/**
	 * Set the prompt description.
	 *
	 * @param string $description The prompt description.
	 *
	 * @return self
	 */
	public function description( string $description ): self {
		$this->description = $description;
		return $this;
	}

	/**
	 * Add an argument to the prompt.
	 *
	 * @param string      $name        The argument name.
	 * @param string|null $description Optional argument description.
	 * @param bool        $required    Whether the argument is required.
	 *
	 * @return self
	 */
	public function argument( string $name, ?string $description = null, bool $required = false ): self {
		$arg = array( 'name' => $name );

		if ( null !== $description ) {
			$arg['description'] = $description;
		}

		if ( $required ) {
			$arg['required'] = true;
		}

		$this->arguments[] = $arg;
		return $this;
	}

	/**
	 * Add a required argument to the prompt.
	 *
	 * Convenience method equivalent to argument($name, $description, true).
	 *
	 * @param string      $name        The argument name.
	 * @param string|null $description Optional argument description.
	 *
	 * @return self
	 */
	public function requiredArgument( string $name, ?string $description = null ): self {
		return $this->argument( $name, $description, true );
	}

	/**
	 * Set multiple arguments at once.
	 *
	 * @param array<int, array{name: string, title?: string, description?: string, required?: bool}> $arguments The arguments array.
	 *
	 * @return self
	 */
	public function arguments( array $arguments ): self {
		$this->arguments = $arguments;
		return $this;
	}

	/**
	 * Set the prompt icons for UI display.
	 *
	 * @param array<int, array{src: string, mimeType?: string, sizes?: array<string>, theme?: string}> $icons Array of icon definitions.
	 *
	 * @return self
	 */
	public function icons( array $icons ): self {
		$this->icons = $icons;
		return $this;
	}

	/**
	 * Set additional metadata.
	 *
	 * @param array<string, mixed> $meta Additional metadata key-value pairs.
	 *
	 * @return self
	 */
	public function meta( array $meta ): self {
		$this->meta = $meta;
		return $this;
	}

	/**
	 * Set the handler callable for prompt execution.
	 *
	 * The handler receives the arguments array and should return either:
	 * - An array with 'messages' key containing MCP-compliant message structures
	 * - Any array (will be JSON-encoded and wrapped as a text message)
	 *
	 * @param callable(array<string, mixed>): array<string, mixed> $handler The handler callable.
	 *
	 * @return self
	 */
	public function handler( callable $handler ): self {
		$this->handler = $handler;
		return $this;
	}

	/**
	 * Set the permission check callable.
	 *
	 * The permission callback receives the arguments array and should return true
	 * if the current user has permission to execute the prompt.
	 *
	 * @param callable(array<string, mixed>): bool $callback The permission callback.
	 *
	 * @return self
	 */
	public function permission( callable $callback ): self {
		$this->permission_callback = $callback;
		return $this;
	}

	// =========================================================================
	// McpPromptBuilderInterface Implementation
	// =========================================================================

	/**
	 * Build and return the Prompt DTO instance.
	 *
	 * @return \WP\McpSchema\Server\Prompts\Prompt The built prompt DTO.
	 */
	public function build(): Prompt {
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

		// Mark as fluent-defined prompt in internal metadata.
		$_meta = array(
			'mcp_adapter' => array(
				'fluent' => true,
			),
		);

		// Merge additional _meta with internal adapter metadata.
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
	 * @return array<int, array{name: string, title?: string, description?: string, required?: bool}> The prompt arguments.
	 */
	public function get_arguments(): array {
		return $this->arguments;
	}

	/**
	 * Get the prompt icons.
	 *
	 * @return array<int, array{src: string, mimeType?: string, sizes?: array<string>, theme?: string}> The prompt icons.
	 */
	public function get_icons(): array {
		return $this->icons ?? array();
	}

	/**
	 * Get the additional metadata.
	 *
	 * @return array<string, mixed> The additional metadata.
	 */
	public function get_meta(): array {
		return $this->meta;
	}

	/**
	 * Handle the prompt execution when called.
	 *
	 * @param array<string, mixed> $arguments The arguments passed to the prompt.
	 *
	 * @return array<string, mixed> The prompt response.
	 */
	public function handle( array $arguments ): array {
		return ( $this->handler )( $arguments );
	}

	/**
	 * Check if the current user has permission to execute this prompt.
	 *
	 * @param array<string, mixed> $arguments The arguments passed to the prompt.
	 *
	 * @return bool True if execution is allowed, false otherwise.
	 */
	public function has_permission( array $arguments ): bool {
		if ( null === $this->permission_callback ) {
			// Default: allow all executions.
			return true;
		}

		return ( $this->permission_callback )( $arguments );
	}
}
