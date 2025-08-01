<?php //phpcs:ignore
/**
 * Register an MCP prompt.
 *
 * @package WpcomMcp
 */

declare( strict_types=1 );

namespace WP\MCP\Prompts;

use WP\MCP\Utils\ErrorHandler;
use WP\MCP\Prompts\Interfaces\PromptsInterface;
use InvalidArgumentException;

/**
 * Register a prompt.
 */
class RegisterPrompt {

	/**
	 * The arguments.
	 *
	 * @var array
	 */
	private array $args;

	/**
	 * The prompt messages.
	 *
	 * @var array
	 */
	private array $messages;

	/**
	 * Server context for validation.
	 *
	 * @var array
	 */
	private array $server_context;

	/**
	 * Constructor.
	 *
	 * @param array $args The arguments to register the MCP prompt.
	 * @param array $messages The prompt messages.
	 * @param array $server_context Optional server context for validation (server_id, existing_prompts).
	 *
	 * @throws InvalidArgumentException When the arguments are invalid.
	 */
	public function __construct( array $args, array $messages, array $server_context = array() ) {
		$this->args           = $args;
		$this->messages       = $messages;
		$this->server_context = $server_context;

		$this->validate_arguments();
		$this->validate_messages();
	}

	/**
	 * Static factory method to handle both class and array inputs.
	 *
	 * @param array|string $prompt_args_or_class Prompt arguments array or class name implementing WpcomMcpPromptsInterface.
	 * @param array|null   $messages Prompt messages (required when using array input).
	 * @param array        $server_context Server context for validation (server_id, existing_prompts).
	 *
	 * @return array Array of processed prompts.
	 */
	public static function create_prompts( $prompt_args_or_class, ?array $messages = null, array $server_context = array() ): array {
		$server_id        = $server_context['server_id'] ?? 'unknown';
		$existing_prompts = $server_context['existing_prompts'] ?? array();

		// Handle class name input.
		if ( is_string( $prompt_args_or_class ) && class_exists( $prompt_args_or_class ) ) {
			if ( ! is_a( $prompt_args_or_class, PromptsInterface::class, true ) ) {
				ErrorHandler::log(
					"Class '{$prompt_args_or_class}' must implement WpcomMcpPromptsInterface.",
					array(
						'class'     => $prompt_args_or_class,
						'server_id' => $server_id,
						'method'    => __METHOD__,
					)
				);

				return array();
			}

			// Instantiate the class and get all prompts.
			$class_prompts      = ( new $prompt_args_or_class() )->get_prompts();
			$processed_prompts = array();

			foreach ( $class_prompts as $prompt ) {
				try {
					$prompt_instance  = new self( $prompt['args'], $prompt['messages'], $server_context );
					$processed_prompt = $prompt_instance->register_prompt();
					if ( ! empty( $processed_prompt ) ) {
						$processed_prompts[ $processed_prompt['args']['name'] ] = $processed_prompt;
					}
				} catch ( InvalidArgumentException $e ) {
					// Log the error but continue processing other prompts.
					ErrorHandler::log(
						"Failed to register prompt from class '{$prompt_args_or_class}': " . $e->getMessage(),
						array(
							'class'     => $prompt_args_or_class,
							'prompt'    => $prompt,
							'server_id' => $server_id,
							'error'     => $e->getMessage(),
							'method'    => __METHOD__,
						)
					);
					continue;
				}
			}

			return $processed_prompts;
		}

		// Handle array input.
		if ( ! is_array( $prompt_args_or_class ) ) {
			ErrorHandler::log(
				'Prompt must be an array or a class name implementing WpcomMcpPromptsInterface.',
				array(
					'provided_type' => gettype( $prompt_args_or_class ),
					'server_id'     => $server_id,
					'method'        => __METHOD__,
				)
			);

			return array();
		}

		if ( null === $messages ) {
			ErrorHandler::log(
				'Prompt messages are required when using array input.',
				array(
					'prompt_args' => $prompt_args_or_class,
					'server_id'   => $server_id,
					'method'      => __METHOD__,
				)
			);

			return array();
		}

		// Process single prompt.
		try {
			$prompt_instance  = new self( $prompt_args_or_class, $messages, $server_context );
			$processed_prompt = $prompt_instance->register_prompt();

			return ! empty( $processed_prompt ) ? array( $processed_prompt['args']['name'] => $processed_prompt ) : array();
		} catch ( InvalidArgumentException $e ) {
			// Log the error and return empty array.
			ErrorHandler::log(
				'Failed to register prompt: ' . $e->getMessage(),
				array(
					'prompt_args' => $prompt_args_or_class,
					'server_id'   => $server_id,
					'error'       => $e->getMessage(),
					'method'      => __METHOD__,
				)
			);

			return array();
		}
	}

	/**
	 * Register the prompt and return the processed prompt data.
	 *
	 * @return array The processed prompt data.
	 */
	public function register_prompt(): array {
		return array(
			'args'     => $this->args,
			'messages' => $this->messages,
		);
	}

	/**
	 * Validate the arguments.
	 *
	 * @return void
	 * @throws InvalidArgumentException When the arguments are invalid.
	 */
	private function validate_arguments(): void {
		$server_id        = $this->server_context['server_id'] ?? 'unknown';
		$existing_prompts = $this->server_context['existing_prompts'] ?? array();

		// name is required.
		if ( ! isset( $this->args['name'] ) ) {
			ErrorHandler::log(
				'Prompt name is required.',
				array(
					'prompt_args' => $this->args,
					'server_id'   => $server_id,
					'method'      => __METHOD__,
				)
			);
			throw new InvalidArgumentException( 'The name is required.' );
		}

		// validate the name: must be a string and between 1 and 64 characters.
		if ( ! preg_match( '/^[a-zA-Z0-9_-]{1,64}$/', $this->args['name'] ) ) {
			ErrorHandler::log(
				'Prompt name must be a string between 1 and 64 characters.',
				array(
					'prompt_name' => $this->args['name'] ?? 'unknown',
					'server_id'   => $server_id,
					'method'      => __METHOD__,
				)
			);
			throw new InvalidArgumentException( 'The name must be a string between 1 and 64 characters.' );
		}

		// description is required.
		if ( ! isset( $this->args['description'] ) ) {
			ErrorHandler::log(
				'Prompt description is required.',
				array(
					'prompt_name' => $this->args['name'] ?? 'unknown',
					'server_id'   => $server_id,
					'method'      => __METHOD__,
				)
			);
			throw new InvalidArgumentException( 'The description is required.' );
		}

		// Check for duplicate prompt names within this server.
		if ( isset( $existing_prompts[ $this->args['name'] ] ) ) {
			ErrorHandler::log(
				"Prompt '{$this->args['name']}' already exists in server '{$server_id}'.",
				array(
					'prompt_name' => $this->args['name'],
					'server_id'   => $server_id,
					'method'      => __METHOD__,
				)
			);
			throw new InvalidArgumentException( esc_html( "Prompt '{$this->args['name']}' already exists in server '{$server_id}'." ) );
		}

		// Validate arguments field if present.
		if ( isset( $this->args['arguments'] ) ) {
			$this->validate_prompt_arguments();
		}

		// Ensure no trailing whitespace in strings.
		foreach ( array( 'name', 'description' ) as $field ) {
			if ( isset( $this->args[ $field ] ) ) {
				$this->args[ $field ] = trim( $this->args[ $field ] );
			}
		}
	}

	/**
	 * Validate the prompt arguments.
	 *
	 * @return void
	 * @throws InvalidArgumentException When the arguments are invalid.
	 */
	private function validate_prompt_arguments(): void {
		if ( ! is_array( $this->args['arguments'] ) ) {
			throw new InvalidArgumentException( 'The prompt arguments must be an array.' );
		}

		// Validate each argument.
		foreach ( $this->args['arguments'] as $index => $argument ) {
			if ( ! is_array( $argument ) ) {
				throw new InvalidArgumentException( sprintf( 'Argument at index %d must be an array.', intval( $index ) ) );
			}

			// name is required for each argument.
			if ( empty( $argument['name'] ) || ! is_string( $argument['name'] ) ) {
				throw new InvalidArgumentException( sprintf( 'Argument at index %d must have a non-empty name.', intval( $index ) ) );
			}

			// Validate argument name format.
			if ( ! preg_match( '/^[a-zA-Z0-9_-]{1,64}$/', $argument['name'] ) ) {
				throw new InvalidArgumentException( sprintf( 'Argument name "%s" must be a string between 1 and 64 characters.', esc_html( $argument['name'] ) ) );
			}

			// description should be a string if present.
			if ( isset( $argument['description'] ) && ! is_string( $argument['description'] ) ) {
				throw new InvalidArgumentException( sprintf( 'Argument "%s" description must be a string.', esc_html( $argument['name'] ) ) );
			}

			// required should be a boolean if present.
			if ( isset( $argument['required'] ) && ! is_bool( $argument['required'] ) ) {
				throw new InvalidArgumentException( sprintf( 'Argument "%s" required flag must be a boolean.', esc_html( $argument['name'] ) ) );
			}

			// type should be a valid type if present.
			if ( isset( $argument['type'] ) ) {
				$valid_types = array( 'string', 'number', 'integer', 'boolean', 'array', 'object' );
				if ( ! in_array( $argument['type'], $valid_types, true ) ) {
					throw new InvalidArgumentException( sprintf( 'Argument "%s" has invalid type "%s". Valid types are: %s', esc_html( $argument['name'] ), esc_html( $argument['type'] ), esc_html( implode( ', ', $valid_types ) ) ) );
				}
			}
		}
	}

	/**
	 * Validate the messages.
	 *
	 * @return void
	 * @throws InvalidArgumentException When the messages are invalid.
	 */
	private function validate_messages(): void {
		if ( empty( $this->messages ) ) {
			throw new InvalidArgumentException( 'Prompt messages are required.' );
		}

		if ( ! is_array( $this->messages ) ) {
			throw new InvalidArgumentException( 'Prompt messages must be an array.' );
		}

		// Validate each message.
		foreach ( $this->messages as $index => $message ) {
			if ( ! is_array( $message ) ) {
				throw new InvalidArgumentException( sprintf( 'Message at index %d must be an array.', intval( $index ) ) );
			}

			// role is required.
			if ( ! isset( $message['role'] ) || ! is_string( $message['role'] ) ) {
				throw new InvalidArgumentException( sprintf( 'Message at index %d must have a role field that is a string.', intval( $index ) ) );
			}

			// Validate role value.
			$valid_roles = array( 'user', 'assistant', 'system' );
			if ( ! in_array( $message['role'], $valid_roles, true ) ) {
				throw new InvalidArgumentException( sprintf( 'Message at index %d has invalid role "%s". Valid roles are: %s', intval( $index ), esc_html( $message['role'] ), esc_html( implode( ', ', $valid_roles ) ) ) );
			}

			// content is required.
			if ( ! isset( $message['content'] ) ) {
				throw new InvalidArgumentException( sprintf( 'Message at index %d must have a content field.', intval( $index ) ) );
			}

			// Validate content structure.
			$this->validate_message_content( $message['content'], intval( $index ) );
		}
	}

	/**
	 * Validate message content structure.
	 *
	 * @param mixed $content The content to validate.
	 * @param int   $message_index The message index for error reporting.
	 *
	 * @return void
	 * @throws InvalidArgumentException When the content is invalid.
	 */
	private function validate_message_content( $content, int $message_index ): void {
		if ( ! is_array( $content ) ) {
			throw new InvalidArgumentException( sprintf( 'Message at index %d content must be an array.', intval( $message_index ) ) );
		}

		// type is required.
		if ( ! isset( $content['type'] ) || ! is_string( $content['type'] ) ) {
			throw new InvalidArgumentException( sprintf( 'Message at index %d content must have a type field that is a string.', intval( $message_index ) ) );
		}

		// Validate content type.
		$valid_types = array( 'text', 'image' );
		if ( ! in_array( $content['type'], $valid_types, true ) ) {
			throw new InvalidArgumentException( sprintf( 'Message at index %d content has invalid type "%s". Valid types are: %s', intval( $message_index ), esc_html( $content['type'] ), esc_html( implode( ', ', $valid_types ) ) ) );
		}

		// For text type, text field is required.
		if ( 'text' === $content['type'] ) {
			if ( ! isset( $content['text'] ) || ! is_string( $content['text'] ) ) {
				throw new InvalidArgumentException( sprintf( 'Message at index %d with text type must have a text field that is a string.', intval( $message_index ) ) );
			}
		}

		// For image type, validate image structure.
		if ( 'image' === $content['type'] ) {
			if ( ! isset( $content['data'] ) || ! is_string( $content['data'] ) ) {
				throw new InvalidArgumentException( sprintf( 'Message at index %d with image type must have a data field that is a string.', intval( $message_index ) ) );
			}

			if ( ! isset( $content['mimeType'] ) || ! is_string( $content['mimeType'] ) ) {
				throw new InvalidArgumentException( sprintf( 'Message at index %d with image type must have a mimeType field that is a string.', intval( $message_index ) ) );
			}
		}
	}
}
