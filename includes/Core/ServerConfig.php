<?php
/**
 * Server Configuration DTO for McpAdapter.
 *
 * @package WP\MCP\Core
 * @since   n.e.x.t
 */

declare( strict_types=1 );

namespace WP\MCP\Core;

use WP\MCP\Core\Contracts\ArrayTransformableInterface;
use WP\MCP\Core\Traits\ValidatesRequiredFieldsTrait;
use WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface;
use WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface;
use WP\MCP\Transport\Contracts\McpTransportInterface;

/**
 * Data Transfer Object for MCP Server configuration.
 *
 * Encapsulates the 13 parameters required to create an MCP server,
 * reducing parameter count in McpAdapter::create_server() and
 * McpServer::__construct().
 *
 * Implements ArrayTransformableInterface for easy serialization and
 * configuration-based instantiation.
 *
 * @since n.e.x.t
 */
final class ServerConfig implements ArrayTransformableInterface {

	use ValidatesRequiredFieldsTrait;

	/**
	 * Unique identifier for the server.
	 *
	 * @var string
	 */
	protected string $server_id;

	/**
	 * Server route namespace.
	 *
	 * @var string
	 */
	protected string $server_route_namespace;

	/**
	 * Server route.
	 *
	 * @var string
	 */
	protected string $server_route;

	/**
	 * Human-readable server name.
	 *
	 * @var string
	 */
	protected string $server_name;

	/**
	 * Server description.
	 *
	 * @var string
	 */
	protected string $server_description;

	/**
	 * Server version.
	 *
	 * @var string
	 */
	protected string $server_version;

	/**
	 * Array of transport class names to initialize.
	 *
	 * @var array<int, class-string<\WP\MCP\Transport\Contracts\McpTransportInterface>>
	 */
	protected array $mcp_transports;

	/**
	 * Error handler class name.
	 *
	 * @var class-string<\WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface>|null
	 */
	protected ?string $error_handler;

	/**
	 * Observability handler class name.
	 *
	 * @var class-string<\WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface>|null
	 */
	protected ?string $observability_handler;

	/**
	 * Tool ability names to register.
	 *
	 * @var array<int, string>
	 */
	protected array $tools;

	/**
	 * Resource ability names to register.
	 *
	 * @var array<int, string>
	 */
	protected array $resources;

	/**
	 * Prompt ability names to register.
	 *
	 * @var array<int, string>
	 */
	protected array $prompts;

	/**
	 * Transport permission callback.
	 *
	 * @var callable|null
	 */
	protected $transport_permission_callback;

	/**
	 * Constructor.
	 *
	 * @since n.e.x.t
	 *
	 * @param string                                             $server_id                      Unique identifier for the server.
	 * @param string                                             $server_route_namespace         Server route namespace.
	 * @param string                                             $server_route                   Server route.
	 * @param string                                             $server_name                    Human-readable server name.
	 * @param string                                             $server_description             Server description.
	 * @param string                                             $server_version                 Server version.
	 * @param array<int, class-string<\WP\MCP\Transport\Contracts\McpTransportInterface>>    $mcp_transports                 Array of transport class names.
	 * @param class-string<\WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface>|null        $error_handler                  Error handler class name.
	 * @param class-string<\WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface>|null $observability_handler         Observability handler class name.
	 * @param array<int, string>                                 $tools                          Tool ability names.
	 * @param array<int, string>                                 $resources                      Resource ability names.
	 * @param array<int, string>                                 $prompts                        Prompt ability names.
	 * @param callable|null                                      $transport_permission_callback Permission callback.
	 */
	public function __construct(
		string $server_id,
		string $server_route_namespace,
		string $server_route,
		string $server_name,
		string $server_description,
		string $server_version,
		array $mcp_transports,
		?string $error_handler = null,
		?string $observability_handler = null,
		array $tools = array(),
		array $resources = array(),
		array $prompts = array(),
		?callable $transport_permission_callback = null
	) {
		$this->server_id                     = $server_id;
		$this->server_route_namespace        = $server_route_namespace;
		$this->server_route                  = $server_route;
		$this->server_name                   = $server_name;
		$this->server_description            = $server_description;
		$this->server_version                = $server_version;
		$this->mcp_transports                = $mcp_transports;
		$this->error_handler                 = $error_handler;
		$this->observability_handler         = $observability_handler;
		$this->tools                         = $tools;
		$this->resources                     = $resources;
		$this->prompts                       = $prompts;
		$this->transport_permission_callback = $transport_permission_callback;
	}

	/**
	 * Creates an instance from array data.
	 *
	 * Required fields: server_id, server_route_namespace, server_route,
	 * server_name, server_description, server_version, mcp_transports.
	 *
	 * Optional fields with defaults:
	 * - error_handler: null
	 * - observability_handler: null
	 * - tools: []
	 * - resources: []
	 * - prompts: []
	 * - transport_permission_callback: null
	 *
	 * @since n.e.x.t
	 *
	 * @param array{
	 *     server_id: string,
	 *     server_route_namespace: string,
	 *     server_route: string,
	 *     server_name: string,
	 *     server_description: string,
	 *     server_version: string,
	 *     mcp_transports: array<int, class-string<\WP\MCP\Transport\Contracts\McpTransportInterface>>,
	 *     error_handler?: class-string<\WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface>|null,
	 *     observability_handler?: class-string<\WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface>|null,
	 *     tools?: array<int, string>,
	 *     resources?: array<int, string>,
	 *     prompts?: array<int, string>,
	 *     transport_permission_callback?: callable|null
	 * } $data The configuration data array.
	 * @phpstan-param array<string, mixed> $data
	 *
	 * @return self The created ServerConfig instance.
	 *
	 * @throws \InvalidArgumentException If required fields are missing or invalid.
	 */
	public static function from_array( array $data ): self {
		self::assert_required(
			$data,
			array(
				'server_id',
				'server_route_namespace',
				'server_route',
				'server_name',
				'server_description',
				'server_version',
				'mcp_transports',
			)
		);

		// Runtime validation ensures these are valid class-strings implementing the correct interfaces.
		// PHPStan cannot statically verify this, so we use inline type assertions.

		/** @var array<int, class-string<\WP\MCP\Transport\Contracts\McpTransportInterface>> $mcp_transports */
		$mcp_transports = self::as_class_string_array( $data['mcp_transports'], McpTransportInterface::class );

		/** @var class-string<\WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface>|null $error_handler */
		$error_handler = self::as_class_string_or_null( $data['error_handler'] ?? null, McpErrorHandlerInterface::class );

		/** @var class-string<\WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface>|null $observability_handler */
		$observability_handler = self::as_class_string_or_null( $data['observability_handler'] ?? null, McpObservabilityHandlerInterface::class );

		return new self(
			self::as_string( $data['server_id'] ),
			self::as_string( $data['server_route_namespace'] ),
			self::as_string( $data['server_route'] ),
			self::as_string( $data['server_name'] ),
			self::as_string( $data['server_description'] ),
			self::as_string( $data['server_version'] ),
			$mcp_transports,
			$error_handler,
			$observability_handler,
			self::as_string_array_or_empty( $data['tools'] ?? null ),
			self::as_string_array_or_empty( $data['resources'] ?? null ),
			self::as_string_array_or_empty( $data['prompts'] ?? null ),
			self::as_callable_or_null( $data['transport_permission_callback'] ?? null )
		);
	}

	/**
	 * Converts the instance to an array representation.
	 *
	 * Null values are omitted from the output for cleaner serialization.
	 * Empty arrays are included to preserve explicit empty configurations.
	 *
	 * Note: transport_permission_callback is omitted as callables cannot
	 * be reliably serialized.
	 *
	 * @since n.e.x.t
	 *
	 * @return array<string, mixed> The array representation.
	 */
	public function to_array(): array {
		$result = array(
			'server_id'              => $this->server_id,
			'server_route_namespace' => $this->server_route_namespace,
			'server_route'           => $this->server_route,
			'server_name'            => $this->server_name,
			'server_description'     => $this->server_description,
			'server_version'         => $this->server_version,
			'mcp_transports'         => $this->mcp_transports,
			'tools'                  => $this->tools,
			'resources'              => $this->resources,
			'prompts'                => $this->prompts,
		);

		if ( null !== $this->error_handler ) {
			$result['error_handler'] = $this->error_handler;
		}

		if ( null !== $this->observability_handler ) {
			$result['observability_handler'] = $this->observability_handler;
		}

		// Note: transport_permission_callback is intentionally omitted
		// as callables cannot be reliably serialized to arrays.

		return $result;
	}

	/**
	 * Gets the unique server identifier.
	 *
	 * @since n.e.x.t
	 *
	 * @return string The server ID.
	 */
	public function get_server_id(): string {
		return $this->server_id;
	}

	/**
	 * Gets the server route namespace.
	 *
	 * @since n.e.x.t
	 *
	 * @return string The route namespace.
	 */
	public function get_server_route_namespace(): string {
		return $this->server_route_namespace;
	}

	/**
	 * Gets the server route.
	 *
	 * @since n.e.x.t
	 *
	 * @return string The route.
	 */
	public function get_server_route(): string {
		return $this->server_route;
	}

	/**
	 * Gets the human-readable server name.
	 *
	 * @since n.e.x.t
	 *
	 * @return string The server name.
	 */
	public function get_server_name(): string {
		return $this->server_name;
	}

	/**
	 * Gets the server description.
	 *
	 * @since n.e.x.t
	 *
	 * @return string The description.
	 */
	public function get_server_description(): string {
		return $this->server_description;
	}

	/**
	 * Gets the server version.
	 *
	 * @since n.e.x.t
	 *
	 * @return string The version.
	 */
	public function get_server_version(): string {
		return $this->server_version;
	}

	/**
	 * Gets the transport class names.
	 *
	 * @since n.e.x.t
	 *
	 * @return array<int, class-string<\WP\MCP\Transport\Contracts\McpTransportInterface>> The transport classes.
	 */
	public function get_mcp_transports(): array {
		return $this->mcp_transports;
	}

	/**
	 * Gets the error handler class name.
	 *
	 * @since n.e.x.t
	 *
	 * @return class-string<\WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface>|null The error handler class or null.
	 */
	public function get_error_handler(): ?string {
		return $this->error_handler;
	}

	/**
	 * Gets the observability handler class name.
	 *
	 * @since n.e.x.t
	 *
	 * @return class-string<\WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface>|null The observability handler class or null.
	 */
	public function get_observability_handler(): ?string {
		return $this->observability_handler;
	}

	/**
	 * Gets the tool ability names.
	 *
	 * @since n.e.x.t
	 *
	 * @return array<int, string> The tool ability names.
	 */
	public function get_tools(): array {
		return $this->tools;
	}

	/**
	 * Gets the resource ability names.
	 *
	 * @since n.e.x.t
	 *
	 * @return array<int, string> The resource ability names.
	 */
	public function get_resources(): array {
		return $this->resources;
	}

	/**
	 * Gets the prompt ability names.
	 *
	 * @since n.e.x.t
	 *
	 * @return array<int, string> The prompt ability names.
	 */
	public function get_prompts(): array {
		return $this->prompts;
	}

	/**
	 * Gets the transport permission callback.
	 *
	 * @since n.e.x.t
	 *
	 * @return callable|null The permission callback or null.
	 */
	public function get_transport_permission_callback(): ?callable {
		return $this->transport_permission_callback;
	}
}
