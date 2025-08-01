<?php //phpcs:ignore
/**
 * Register an MCP tool.
 *
 * @package WP\MCP
 */

declare( strict_types=1 );

namespace WP\MCP\Tools;

use WP\MCP\Tools\Interfaces\ToolsInterface;
use WP\MCP\Utils\ErrorHandler;
use InvalidArgumentException;

/**
 * Register an MCP tool.
 */
class RegisterTool {

	/**
	 * The arguments.
	 *
	 * @var array
	 */
	private array $args;

	/**
	 * Server context for validation.
	 *
	 * @var array
	 */
	private array $server_context;

	/**
	 * Cached REST routes to avoid repeated API calls.
	 *
	 * @var array|null
	 */
	private static ?array $cached_rest_routes = null;

	/**
	 * Flag to track if REST routes have been attempted to load.
	 *
	 * @var bool
	 */
	private static bool $rest_routes_load_attempted = false;

	/**
	 * Constructor.
	 *
	 * @param array $args The arguments to register the MCP tool.
	 * @param array $server_context Optional server context for validation (server_id, existing_tools).
	 *
	 * @throws InvalidArgumentException When the arguments are invalid.
	 */
	public function __construct( array $args, array $server_context = array() ) {
		$this->args           = $args;
		$this->server_context = $server_context;

		// Backward compatibility for permissions_callback.
		if ( isset( $this->args['permissions_callback'] ) ) {
			$this->args['permission_callback'] = $this->args['permissions_callback'];
			unset( $this->args['permissions_callback'] );
		}
		$this->validate_arguments();
	}

	/**
	 * Static factory method to handle both class and array inputs.
	 *
	 * @param array|string $tool_args_or_class Tool arguments array or class name implementing ToolsInterface.
	 * @param array        $server_context Server context for validation (server_id, existing_tools).
	 *
	 * @return array Array of processed tools.
	 */
	public static function create_tools( $tool_args_or_class, array $server_context = array() ): array {
		$server_id      = $server_context['server_id'] ?? 'unknown';
		$existing_tools = $server_context['existing_tools'] ?? array();

		// Handle class name input.
		if ( is_string( $tool_args_or_class ) && class_exists( $tool_args_or_class ) ) {
			if ( ! is_a( $tool_args_or_class, ToolsInterface::class, true ) ) {
				ErrorHandler::log(
					"Class '{$tool_args_or_class}' must implement ToolsInterface.",
					array(
						'class'     => $tool_args_or_class,
						'server_id' => $server_id,
						'method'    => __METHOD__,
					)
				);

				return array();
			}

			// Instantiate the class and get all tools.
			$class_tools     = ( new $tool_args_or_class() )->get_tools();
			$processed_tools = array();

			foreach ( $class_tools as $tool ) {
				try {
					$tool_instance  = new self( $tool, $server_context );
					$processed_tool = $tool_instance->register_tool();
					if ( ! empty( $processed_tool ) ) {
						$processed_tools[ $processed_tool['name'] ] = $processed_tool;
					}
				} catch ( InvalidArgumentException $e ) {
					// Log the error but continue processing other tools.
					ErrorHandler::log(
						"Failed to register tool from class '{$tool_args_or_class}': " . $e->getMessage(),
						array(
							'class'     => $tool_args_or_class,
							'tool'      => $tool,
							'server_id' => $server_id,
							'error'     => $e->getMessage(),
							'method'    => __METHOD__,
						)
					);
					continue;
				}
			}

			return $processed_tools;
		}

		// Handle array input.
		if ( ! is_array( $tool_args_or_class ) ) {
			ErrorHandler::log(
				'Tool must be an array or a class name implementing ToolsInterface.',
				array(
					'provided_type' => gettype( $tool_args_or_class ),
					'server_id'     => $server_id,
					'method'        => __METHOD__,
				)
			);

			return array();
		}

		// Process single tool.
		try {
			$tool_instance  = new self( $tool_args_or_class, $server_context );
			$processed_tool = $tool_instance->register_tool();

			return ! empty( $processed_tool ) ? array( $processed_tool['name'] => $processed_tool ) : array();
		} catch ( InvalidArgumentException $e ) {
			// Log the error and return empty array.
			ErrorHandler::log(
				'Failed to register tool: ' . $e->getMessage(),
				array(
					'tool_args' => $tool_args_or_class,
					'server_id' => $server_id,
					'error'     => $e->getMessage(),
					'method'    => __METHOD__,
				)
			);

			return array();
		}
	}

	/**
	 * Register the tool and return the processed tool data.
	 *
	 * @return array The processed tool data.
	 */
	public function register_tool(): array {
		if ( ! empty( $this->args['rest_alias'] ) ) {
			$this->get_args_from_rest_api();
		}

		return $this->args;
	}

	/**
	 * Get the arguments from the rest api.
	 *
	 * @return void
	 * @throws InvalidArgumentException When the REST API route or method is invalid.
	 */
	private function get_args_from_rest_api(): void {
		$method = $this->args['rest_alias']['method'];
		$route  = $this->args['rest_alias']['route'];

		// Get cached routes or load them.
		$routes = self::get_cached_rest_routes();

		// error_log( print_r( array_keys( $routes ), true ) );

		if ( null === $routes ) {
			ErrorHandler::log(
				'Failed to load REST routes. REST API may not be initialized yet.',
				array(
					'route'       => $route,
					'http_method' => $method,
					'server_id'   => $this->server_context['server_id'] ?? 'unknown',
					'tool_name'   => $this->args['name'] ?? 'unknown',
					'method'      => __METHOD__,
				)
			);

			return;
		}

		$rest_route = $routes[ $route ] ?? null;

		if ( ! $rest_route ) {
			ErrorHandler::log(
				"The route does not exist: {$route} {$method}. Skipping registration.",
				array(
					'route'       => $route,
					'http_method' => $method,
					'server_id'   => $this->server_context['server_id'] ?? 'unknown',
					'tool_name'   => $this->args['name'] ?? 'unknown',
					'method'      => __METHOD__,
				)
			);

			return;
		}

		$rest_api = null;

		// Find the endpoint that supports the specified method.
		foreach ( $rest_route as $endpoint ) {
			if ( isset( $endpoint['methods'][ $method ] ) && true === $endpoint['methods'][ $method ] ) {
				$rest_api = $endpoint;
				break;
			}
		}

		if ( ! $rest_api ) {
			ErrorHandler::log(
				"The method {$method} does not exist in route {$route}. Skipping registration.",
				array(
					'route'             => $route,
					'http_method'       => $method,
					'available_methods' => $this->get_available_methods( $rest_route ),
					'server_id'         => $this->server_context['server_id'] ?? 'unknown',
					'tool_name'         => $this->args['name'] ?? 'unknown',
					'method'            => __METHOD__,
				)
			);

			return;
		}

		// Convert REST API args to MCP input schema.
		$input_schema = $this->convert_rest_args_to_input_schema( $rest_api['args'] ?? array(), $route, $method );

		// Apply modifications if provided in rest_alias['inputSchemaReplacements'].
		if ( isset( $this->args['rest_alias']['inputSchemaReplacements'] ) ) {
			$modifications = $this->args['rest_alias']['inputSchemaReplacements'];
			$input_schema  = $this->apply_modifications( $input_schema, $modifications );

			// Ensure required field is always an array if it exists.
			if ( isset( $input_schema['required'] ) && ! is_array( $input_schema['required'] ) ) {
				// Convert to array if it's not already.
				if ( is_object( $input_schema['required'] ) ) {
					$input_schema['required'] = array_values( (array) $input_schema['required'] );
				} else {
					$input_schema['required'] = array();
				}
			}
		}

		// Update the args with the converted schema.
		$this->args['inputSchema']         = $input_schema;
		$this->args['callback']            = $rest_api['callback'];
		$this->args['permission_callback'] = $rest_api['permission_callback'];
	}

	/**
	 * Get cached REST routes or load them if not cached.
	 *
	 * @return array|null REST routes array or null if not available.
	 */
	private static function get_cached_rest_routes(): ?array {
		// Return cached routes if available.
		if ( null !== self::$cached_rest_routes ) {
			return self::$cached_rest_routes;
		}

		// Don't attempt to load routes multiple times if they failed before.
		if ( self::$rest_routes_load_attempted ) {
			return null;
		}

		self::$rest_routes_load_attempted = true;

		// Check if REST server is available.
		if ( ! function_exists( 'rest_get_server' ) ) {
			return null;
		}

		try {
			$rest_server = rest_get_server();
			if ( ! $rest_server ) {
				return null;
			}

			self::$cached_rest_routes = $rest_server->get_routes();

			return self::$cached_rest_routes;
		} catch ( \Exception $e ) {
			ErrorHandler::log(
				'Failed to load REST routes: ' . $e->getMessage(),
				array(
					'error'  => $e->getMessage(),
					'method' => __METHOD__,
				)
			);

			return null;
		}
	}

	/**
	 * Convert REST API arguments to MCP input schema.
	 *
	 * @param array $rest_args REST API arguments.
	 * @param string $route The REST route for error reporting.
	 * @param string $method The REST method for error reporting.
	 *
	 * @return array Input schema array.
	 */
	private function convert_rest_args_to_input_schema( array $rest_args, string $route, string $method ): array {
		$input_schema = array(
			'type'       => 'object',
			'properties' => array(),
			'required'   => array(),
		);

		foreach ( $rest_args as $arg_name => $arg_schema ) {

			if ( ! preg_match( '/^[a-zA-Z0-9_-]{1,64}$/', $arg_name ) ) {
				// Log the invalid parameter name.
				ErrorHandler::log(
					"Invalid parameter name: {$arg_name} in {$route} {$method}. The parameter was skipped.",
					array(
						'parameter_name' => $arg_name,
						'route'          => $route,
						'http_method'    => $method,
						'tool_name'      => $this->args['name'] ?? 'unknown',
						'server_id'      => $this->server_context['server_id'] ?? 'unknown',
						'method'         => __METHOD__,
					)
				);
				continue; // Skip invalid parameter names.
			}

			$type = $arg_schema['type'];
			if ( is_array( $type ) ) {
				$type = reset( $type );
			}
			$input_schema['properties'][ $arg_name ] = array(
				'type'        => $type,
				'description' => $arg_schema['description'] ?? '',
			);

			// Handle array items if present.
			if ( isset( $arg_schema['items'] ) ) {
				$input_schema['properties'][ $arg_name ]['items'] = $arg_schema['items'];
			}

			// Handle enums if present and remove duplicates.
			if ( isset( $arg_schema['enum'] ) ) {
				$input_schema['properties'][ $arg_name ]['enum'] = array_values( array_unique( $arg_schema['enum'], SORT_REGULAR ) );
			}

			// Handle default values if present.
			if ( isset( $arg_schema['default'] ) && ! empty( $arg_schema['default'] ) ) {
				$input_schema['properties'][ $arg_name ]['default'] = $arg_schema['default'];
			}

			// Handle format if present.
			if ( isset( $arg_schema['format'] ) ) {
				$input_schema['properties'][ $arg_name ]['format'] = $arg_schema['format'];
			}

			// Handle minimum/maximum if present.
			if ( isset( $arg_schema['minimum'] ) ) {
				$input_schema['properties'][ $arg_name ]['minimum'] = $arg_schema['minimum'];
			}
			if ( isset( $arg_schema['maximum'] ) ) {
				$input_schema['properties'][ $arg_name ]['maximum'] = $arg_schema['maximum'];
			}

			// If the parameter has no default value and is not explicitly optional, mark it as required.
			if ( isset( $arg_schema['required'] ) && true === $arg_schema['required'] ) {
				$input_schema['required'][] = $arg_name;
			}
		}

		// Convert required array to object.
		if ( empty( $input_schema['properties'] ) ) {
			unset( $input_schema['properties'] );
		}
		if ( empty( $input_schema['required'] ) ) {
			unset( $input_schema['required'] );
		}

		return $input_schema;
	}

	/**
	 * Get available methods for a REST route.
	 *
	 * @param array $rest_route REST route endpoints.
	 *
	 * @return array Available methods.
	 */
	private function get_available_methods( array $rest_route ): array {
		$methods = array();
		foreach ( $rest_route as $endpoint ) {
			if ( isset( $endpoint['methods'] ) && is_array( $endpoint['methods'] ) ) {
				$methods = array_merge( $methods, array_keys( array_filter( $endpoint['methods'] ) ) );
			}
		}

		return array_unique( $methods );
	}

	/**
	 * Clear the cached REST routes (useful for testing or when routes change).
	 *
	 * @return void
	 */
	public static function clear_rest_routes_cache(): void {
		self::$cached_rest_routes         = null;
		self::$rest_routes_load_attempted = false;
	}

	/**
	 * Get registration statistics and debug information.
	 *
	 * @return array Debug information about the registration system.
	 */
	public static function get_registration_debug_info(): array {
		$debug_info = array(
			'rest_routes_cached'         => null !== self::$cached_rest_routes,
			'rest_routes_load_attempted' => self::$rest_routes_load_attempted,
			'rest_routes_count'          => null !== self::$cached_rest_routes ? count( self::$cached_rest_routes ) : 0,
			'rest_server_available'      => function_exists( 'rest_get_server' ),
		);

		if ( function_exists( 'rest_get_server' ) ) {
			try {
				$rest_server                        = rest_get_server();
				$debug_info['rest_server_instance'] = null !== $rest_server;
			} catch ( \Exception $e ) {
				$debug_info['rest_server_instance'] = false;
				$debug_info['rest_server_error']    = $e->getMessage();
			}
		}

		return $debug_info;
	}

	/**
	 * Validate the arguments.
	 *
	 * @return void
	 * @throws InvalidArgumentException When the arguments are invalid.
	 */
	private function validate_arguments(): void {
		$server_id      = $this->server_context['server_id'] ?? 'unknown';
		$existing_tools = $this->server_context['existing_tools'] ?? array();

		// name is required.
		if ( ! isset( $this->args['name'] ) ) {
			ErrorHandler::log(
				'Tool name is required.',
				array(
					'tool_args' => $this->args,
					'server_id' => $server_id,
					'method'    => __METHOD__,
				)
			);
			throw new InvalidArgumentException( 'The name is required.' );
		}

		// validate the name: must be a string and between 1 and 64 characters.
		if ( ! preg_match( '/^[a-zA-Z0-9_-]{1,64}$/', $this->args['name'] ) ) {
			ErrorHandler::log(
				'Tool name must be a string between 1 and 64 characters.',
				array(
					'tool_name' => $this->args['name'] ?? 'unknown',
					'server_id' => $server_id,
					'method'    => __METHOD__,
				)
			);
			throw new InvalidArgumentException( 'The name must be a string between 1 and 64 characters.' );
		}

		// description is required.
		if ( ! isset( $this->args['description'] ) ) {
			ErrorHandler::log(
				'Tool description is required.',
				array(
					'tool_name' => $this->args['name'] ?? 'unknown',
					'server_id' => $server_id,
					'method'    => __METHOD__,
				)
			);
			throw new InvalidArgumentException( 'The description is required.' );
		}

		// functionality_type is required.
		if ( ! isset( $this->args['type'] ) ) {
			ErrorHandler::log(
				'Tool type is required.',
				array(
					'tool_name' => $this->args['name'] ?? 'unknown',
					'server_id' => $server_id,
					'method'    => __METHOD__,
				)
			);
			throw new InvalidArgumentException( 'The functionality type is required.' );
		}

		// validate functionality type: must be one of 'create', 'read', 'update', 'delete', 'action'.
		$valid_types = array( 'create', 'read', 'update', 'delete', 'action' );
		if ( ! in_array( $this->args['type'], $valid_types, true ) ) {
			ErrorHandler::log(
				'Tool type must be one of: ' . implode( ', ', $valid_types ),
				array(
					'tool_name' => $this->args['name'] ?? 'unknown',
					'tool_type' => $this->args['type'] ?? 'unknown',
					'server_id' => $server_id,
					'method'    => __METHOD__,
				)
			);
			throw new InvalidArgumentException( 'The functionality type must be one of: ' . esc_html( implode( ', ', $valid_types ) ) );
		}

		// Check for duplicate tool names within this server.
		if ( isset( $existing_tools[ $this->args['name'] ] ) ) {
			ErrorHandler::log(
				"Tool '{$this->args['name']}' already exists in server '{$server_id}'.",
				array(
					'tool_name' => $this->args['name'],
					'server_id' => $server_id,
					'method'    => __METHOD__,
				)
			);
			throw new InvalidArgumentException( esc_html( "Tool '{$this->args['name']}' already exists in server '{$server_id}'." ) );
		}

		// if rest_alias is provided, the rest of the arguments are not required.
		if ( isset( $this->args['rest_alias'] ) ) {
			$this->validate_rest_alias();

			return;
		}

		// callback is required.
		if ( ! isset( $this->args['callback'] ) ) {
			ErrorHandler::log(
				'Tool callback is required.',
				array(
					'tool_name' => $this->args['name'] ?? 'unknown',
					'server_id' => $server_id,
					'method'    => __METHOD__,
				)
			);
			throw new InvalidArgumentException( 'The callback is required.' );
		}

		// callback must be callable.
		if ( ! is_callable( $this->args['callback'] ) ) {
			ErrorHandler::log(
				'Tool callback must be callable.',
				array(
					'tool_name' => $this->args['name'] ?? 'unknown',
					'server_id' => $server_id,
					'method'    => __METHOD__,
				)
			);
			throw new InvalidArgumentException( 'The callback must be a callable.' );
		}

		// permission_callback must be callable.
		if ( empty( $this->args['permission_callback'] ) ) {
			ErrorHandler::log(
				'Tool permission callback is required.',
				array(
					'tool_name' => $this->args['name'] ?? 'unknown',
					'server_id' => $server_id,
					'method'    => __METHOD__,
				)
			);
			throw new InvalidArgumentException( 'The permission callback is required.' );
		}

		// permission_callback must be callable.
		if ( ! is_callable( $this->args['permission_callback'] ) ) {
			ErrorHandler::log(
				'Tool permission callback must be callable.',
				array(
					'tool_name' => $this->args['name'] ?? 'unknown',
					'server_id' => $server_id,
					'method'    => __METHOD__,
				)
			);
			throw new InvalidArgumentException( 'The permission callback must be a callable.' );
		}

		// validate the input schema.
		$this->validate_input_schema();
	}

	/**
	 * Validate the rest api alias.
	 *
	 * @return void
	 * @throws InvalidArgumentException When the rest api alias is invalid.
	 */
	private function validate_rest_alias(): void {
		// route is required.
		if ( ! isset( $this->args['rest_alias']['route'] ) ) {
			throw new InvalidArgumentException( 'The route is required.' );
		}

		// method is required.
		if ( ! isset( $this->args['rest_alias']['method'] ) ) {
			throw new InvalidArgumentException( 'The method is required.' );
		}

		// validate the method: must be one of the following: GET, POST, PUT, PATCH, DELETE.
		if ( ! in_array(
			$this->args['rest_alias']['method'],
			array(
				'GET',
				'POST',
				'PUT',
				'PATCH',
				'DELETE',
			),
			true
		) ) {
			throw new InvalidArgumentException( 'The method must be one of the following: GET, POST, PUT, PATCH, DELETE.' );
		}
	}

	/**
	 * Validate the input schema.
	 *
	 * @return void
	 * @throws InvalidArgumentException When the input schema is invalid.
	 */
	private function validate_input_schema(): void {
		// Check if the input schema is provided.
		if ( empty( $this->args['inputSchema'] ) ) {
			throw new InvalidArgumentException( 'The input schema is required.' );
		}

		// Validate that the input schema is a valid JSON Schema object.
		if ( ! isset( $this->args['inputSchema']['type'] ) || 'object' !== $this->args['inputSchema']['type'] ) {
			throw new InvalidArgumentException( esc_html__( 'The input schema must be an object type.', 'wordpress-mcp' ) );
		}

		// Validate properties field exists and is an object.
		// If ( ! isset( $this->args['inputSchema']['properties'] ) || ! is_array($this->args['inputSchema']['properties'] ) ) {
		// throw new \InvalidArgumentException( esc_html__( 'The input schema must have a properties field that is an object.', 'wordpress-mcp' ) );
		// }.

		// Validate each property has a type.
		foreach ( $this->args['inputSchema']['properties'] as $property_name => $property ) {
			if ( ! isset( $property['type'] ) ) {
				// translators: %s: Property name.
				throw new InvalidArgumentException( sprintf( esc_html__( "Property '%s' must have a type field.", 'wordpress-mcp' ), esc_html( $property_name ) ) );
			}

			// Validate property type is a valid JSON Schema type.
			$valid_types = array( 'string', 'number', 'integer', 'boolean', 'array', 'object', 'null' );
			if ( ! in_array( $property['type'], $valid_types, true ) ) {
				// translators: 1: Property name, 2: Property type.
				throw new InvalidArgumentException( sprintf( esc_html__( "Property '%1\$s' has invalid type '%2\$s'.", 'wordpress-mcp' ), esc_html( $property_name ), esc_html( $property['type'] ) ) );
			}

			// If the type is array, the validate items field exists.
			if ( 'array' === $property['type'] && ! isset( $property['items'] ) ) {
				// translators: %s: Property name.
				throw new InvalidArgumentException( sprintf( esc_html__( "Array property '%s' must have an items field.", 'wordpress-mcp' ), esc_html( $property_name ) ) );
			}
		}

		// Validate the required field if present.
		if ( isset( $this->args['inputSchema']['required'] ) ) {
			// Ensure required field is an array.
			if ( ! is_array( $this->args['inputSchema']['required'] ) ) {
				throw new InvalidArgumentException( esc_html__( 'The required field must be an array.', 'wordpress-mcp' ) );
			}

			// Check all required properties exist in properties.
			foreach ( $this->args['inputSchema']['required'] as $required_property ) {
				if ( ! isset( $this->args['inputSchema']['properties'][ $required_property ] ) ) {
					// translators: %s: Required property.
					throw new InvalidArgumentException( sprintf( esc_html__( "Required property '%s' does not exist in properties.", 'wordpress-mcp' ), esc_html( $required_property ) ) );
				}
			}
		}
	}

	/**
	 * Recursively remove all null values from an array.
	 *
	 * @param array $array The array to clean.
	 *
	 * @return array The cleaned array.
	 */
	private function remove_null_recursive( array $array ): array {
		foreach ( $array as $key => &$value ) {
			if ( is_array( $value ) ) {
				$value = $this->remove_null_recursive( $value );
			} elseif ( is_null( $value ) ) {
				unset( $array[ $key ] );
			}
		}
		unset( $value ); // break reference.

		return $array;
	}

	/**
	 * Apply modifications to the input schema.
	 *
	 * @param array $input_schema The input schema.
	 * @param array $modifications The modifications to apply.
	 *
	 * @return array The modified input schema.
	 */
	private function apply_modifications( array $input_schema, array $modifications ): array {

		$result = array_replace_recursive( $input_schema, $modifications );

		// Ensure required field is always an array if it exists.
		if ( isset( $result['required'] ) && ! is_array( $result['required'] ) ) {
			$result['required'] = array();
		}

		return $this->remove_null_recursive( $result );
	}
}
