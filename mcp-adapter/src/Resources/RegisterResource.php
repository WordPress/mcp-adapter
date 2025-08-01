<?php //phpcs:ignore
/**
 * Register a resource.
 *
 * @package mcp-adapter
 */
declare(strict_types=1);


namespace WP\MCP\Resources;

use WP\MCP\Resources\Interfaces\ResourcesInterface;
use WP\MCP\Utils\ErrorHandler;
use InvalidArgumentException;

/**
 * Register Resource.
 *
 * @package mcp-adapter
 */
class RegisterResource {

	/**
	 * The arguments.
	 *
	 * @var array
	 */
	private array $args;

	/**
	 * The resource content callback.
	 *
	 * @var callable
	 */
	private $resource_content_callback;

	/**
	 * Server context for validation.
	 *
	 * @var array
	 */
	private array $server_context;

	/**
	 * The accepted mime types.
	 *
	 * @var array
	 */
	private array $accepted_mime_types = array( 'application/json', 'text/plain' );

	/**
	 * Constructor.
	 *
	 * @param array $args The arguments.
	 * @param callable $resource_content_callback The resource content callback.
	 * @param array $server_context Optional server context for validation (server_id, existing_resources).
	 * @throws InvalidArgumentException When validation fails.
	 */
	public function __construct( array $args, callable $resource_content_callback, array $server_context = array() ) {
		$this->args                      = $args;
		$this->resource_content_callback = $resource_content_callback;
		$this->server_context           = $server_context;
		$this->validate_arguments();
		$this->validate_resource_content_callback();
	}

	/**
	 * Static factory method to handle both class and array inputs.
	 *
	 * @param array|string $resource_args_or_class Resource arguments array or class name implementing WpcomMcpResourcesInterface.
	 * @param callable|null $callback Resource callback function (required when using array input).
	 * @param array $server_context Server context for validation (server_id, existing_resources).
	 *
	 * @return array Array of processed resources.
	 */
	public static function create_resources( $resource_args_or_class, ?callable $callback = null, array $server_context = array() ): array {
		$server_id           = $server_context['server_id'] ?? 'unknown';
		$existing_resources = $server_context['existing_resources'] ?? array();

		// Handle class name input.
		if ( is_string( $resource_args_or_class ) && class_exists( $resource_args_or_class ) ) {
			if ( ! is_a( $resource_args_or_class, ResourcesInterface::class, true ) ) {
				ErrorHandler::log(
					"Class '{$resource_args_or_class}' must implement WpcomMcpResourcesInterface.",
					array(
						'class'     => $resource_args_or_class,
						'server_id' => $server_id,
						'method'    => __METHOD__,
					)
				);

				return array();
			}

			// Instantiate the class and get all resources.
			$class_resources     = ( new $resource_args_or_class() )->get_resources();
			$processed_resources = array();

			foreach ( $class_resources as $resource ) {
				try {
					$resource_instance  = new self( $resource['args'], $resource['callback'], $server_context );
					$processed_resource = $resource_instance->register_resource();
					if ( ! empty( $processed_resource ) ) {
						$processed_resources[ $processed_resource['args']['uri'] ] = $processed_resource;
					}
				} catch ( InvalidArgumentException $e ) {
					// Log the error but continue processing other resources.
					ErrorHandler::log(
						"Failed to register resource from class '{$resource_args_or_class}': " . $e->getMessage(),
						array(
							'class'     => $resource_args_or_class,
							'resource'  => $resource,
							'server_id' => $server_id,
							'error'     => $e->getMessage(),
							'method'    => __METHOD__,
						)
					);
					continue;
				}
			}

			return $processed_resources;
		}

		// Handle array input.
		if ( ! is_array( $resource_args_or_class ) ) {
			ErrorHandler::log(
				'Resource must be an array or a class name implementing WpcomMcpResourcesInterface.',
				array(
					'provided_type' => gettype( $resource_args_or_class ),
					'server_id'     => $server_id,
					'method'        => __METHOD__,
				)
			);

			return array();
		}

		if ( null === $callback ) {
			ErrorHandler::log(
				'Resource callback is required when using array input.',
				array(
					'resource_args' => $resource_args_or_class,
					'server_id'     => $server_id,
					'method'        => __METHOD__,
				)
			);

			return array();
		}

		// Process single resource.
		try {
			$resource_instance  = new self( $resource_args_or_class, $callback, $server_context );
			$processed_resource = $resource_instance->register_resource();

			return ! empty( $processed_resource ) ? array( $processed_resource['args']['uri'] => $processed_resource ) : array();
		} catch ( InvalidArgumentException $e ) {
			// Log the error and return empty array.
			ErrorHandler::log(
				'Failed to register resource: ' . $e->getMessage(),
				array(
					'resource_args' => $resource_args_or_class,
					'server_id'     => $server_id,
					'error'         => $e->getMessage(),
					'method'        => __METHOD__,
				)
			);

			return array();
		}
	}

	/**
	 * Register the resource and return the processed resource data.
	 *
	 * @return array The processed resource data.
	 */
	public function register_resource(): array {
		return array(
			'args'     => $this->args,
			'callback' => $this->resource_content_callback,
		);
	}

	/**
	 * Validate the arguments.
	 *
	 * @throws InvalidArgumentException When validation fails.
	 * @return void
	 */
	private function validate_arguments(): void {
		$server_id           = $this->server_context['server_id'] ?? 'unknown';
		$existing_resources = $this->server_context['existing_resources'] ?? array();

		if ( ! isset( $this->args['uri'] ) || empty( $this->args['uri'] ) ) {
			throw new InvalidArgumentException( 'Resource URI is required' );
		}

		if ( ! isset( $this->args['name'] ) || empty( $this->args['name'] ) ) {
			throw new InvalidArgumentException( 'Resource name is required' );
		}

		// Validate URI format.
		if ( ! filter_var( $this->args['uri'], FILTER_VALIDATE_URL ) && ! preg_match( '/^WordPress:\/\//', $this->args['uri'] ) ) {
			throw new InvalidArgumentException( 'Invalid resource URI format. Must follow WordPress://[host]/[path] format' );
		}

		// Check for duplicate resource URIs within this server.
		if ( isset( $existing_resources[ $this->args['uri'] ] ) ) {
			ErrorHandler::log(
				"Resource with URI '{$this->args['uri']}' already exists in server '{$server_id}'.",
				array(
					'resource_uri' => $this->args['uri'],
					'server_id'    => $server_id,
					'method'       => __METHOD__,
				)
			);
			throw new InvalidArgumentException( esc_html( "Resource with URI '{$this->args['uri']}' already exists in server '{$server_id}'." ) );
		}

		// Validate the MIME type if provided.
		if ( isset( $this->args['mimeType'] ) && ! empty( $this->args['mimeType'] ) ) {
			if ( ! in_array( $this->args['mimeType'], $this->accepted_mime_types, true ) ) {
				throw new InvalidArgumentException( 'Invalid MIME type format. Accepted mime types are: ' . esc_html( implode( ', ', $this->accepted_mime_types ) ) );
			}
		}

		// Ensure no trailing whitespace in strings.
		foreach ( array( 'uri', 'name', 'description', 'mimeType' ) as $field ) {
			if ( isset( $this->args[ $field ] ) ) {
				$this->args[ $field ] = trim( $this->args[ $field ] );
			}
		}
	}

	/**
	 * Validate the resource content callback.
	 *
	 * @throws InvalidArgumentException When validation fails.
	 * @return void
	 */
	private function validate_resource_content_callback(): void {
		if ( ! is_callable( $this->resource_content_callback ) ) {
			throw new InvalidArgumentException( 'Resource content callback must be a callable' );
		}
	}
}
