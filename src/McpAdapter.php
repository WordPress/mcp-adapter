<?php
/**
 * WordPress MCP Adapter - Main entry point for MCP server functionality.
 *
 * This class provides a simplified interface for creating and managing MCP servers
 * with sensible defaults, while allowing for customization when needed.
 *
 * @package McpAdapterRegistry
 */

declare( strict_types=1 );


use Exception;
use WP\MCP\Core\McpAdapterRegistry;
use WP\MCP\Transport\Http\RestTransport;

/**
 * WordPress MCP Adapter
 */
class McpAdapter {
	/**
	 * The registry instance.
	 *
	 * @var McpAdapterRegistry|null
	 */
	private McpAdapterRegistry $registry;

	/**
	 * Initialize the MCP adapter server.
	 */
	public function __construct( ) {
		$this->registry = McpAdapterRegistry::instance();
		add_action( 'mcp_adapter_init', array( $this, 'create_server' ) );
	}

	/**
	 * Create the default MCP server with configuration from WordPress filters.
	 *
	 * @return void
	 * @throws Exception
	 */
	public function create_server(): void {
		// Get configuration from WordPress filters
		$server_config = self::get_config();

		$abilities = wp_get_abilities();
		$tools     = array();
		$resources = array();
		$prompts   = array();
		foreach ( $abilities as $ability_name => $ability ) {
			$meta = $ability->get_meta();
			if ( empty( $meta['type'] ) || $meta['type'] === 'tool' ) {
				$tools[] = $ability_name;
			} elseif ( $meta['type'] === 'resource' ) {
				$resources[] = $ability_name;
			} elseif ( $meta['type'] === 'prompt' ) {
				$prompts[] = $ability_name;
			}
		}

		$this->registry->create_server(
			$server_config['server_id'],
			$server_config['server_route_namespace'],
			$server_config['server_route'],
			$server_config['server_name'],
			$server_config['server_description'],
			$server_config['server_version'],
			$server_config['transports'],
			$server_config['error_handler'],
			$server_config['observability_handler'],
			$tools,
			$resources,
			$prompts,
			$server_config['transport_permission_callback'] ?? null
		);
	}

	/**
	 * Get the default server configuration with WordPress filters applied.
	 *
	 * @return array Default configuration array with filter overrides applied.
	 */
	private static function get_config(): array {
		$base_config = array(
			'server_id'                     => 'wordpress-mcp',
			'server_route_namespace'        => 'wp/v2',
			'server_route'                  => 'mcp',
			'server_name'                   => 'WordPress MCP Server',
			'server_description'            => 'WordPress MCP server providing access to WordPress functionality',
			'server_version'                => '1.0.0',
			'error_handler'                 => null,
			'observability_handler'         => null,
			'transports'                    => array( RestTransport::class ),
			'transport_permission_callback' => null,
		);

		/**
		 * Filter the default MCP server configuration.
		 *
		 * This filter allows developers to modify the default configuration
		 * for the MCP adapter server before it's created.
		 *
		 * @param array $config {
		 *     Default server configuration.
		 *
		 * @type string $server_id Unique server identifier.
		 * @type string $server_route_namespace REST API namespace.
		 * @type string $server_route REST API route.
		 * @type string $server_name Human-readable server name.
		 * @type string $server_description Server description.
		 * @type string $server_version Server version.
		 * @type string $error_handler Error handler class name.
		 * @type string $observability_handler Observability handler class name.
		 * @type array $transports Array of transport class names.
		 * @type array $tools Array of tool ability names.
		 * @type array $resources Array of resource configurations.
		 * @type array $prompts Array of prompt configurations.
		 * @type callable $transport_permission_callback Permission callback for transport.
		 * }
		 * @since 1.0.0
		 *
		 */
		$config = apply_filters( 'mcp_adapter_default_config', $base_config );

		// Apply individual filters for each configuration option
		$config['server_id']                     = apply_filters( 'mcp_adapter_default_server_id', $config['server_id'] );
		$config['server_route_namespace']        = apply_filters( 'mcp_adapter_default_route_namespace', $config['server_route_namespace'] );
		$config['server_route']                  = apply_filters( 'mcp_adapter_default_route', $config['server_route'] );
		$config['server_name']                   = apply_filters( 'mcp_adapter_default_server_name', $config['server_name'] );
		$config['server_description']            = apply_filters( 'mcp_adapter_default_server_description', $config['server_description'] );
		$config['server_version']                = apply_filters( 'mcp_adapter_default_server_version', $config['server_version'] );
		$config['error_handler']                 = apply_filters( 'mcp_adapter_default_error_handler', $config['error_handler'] );
		$config['observability_handler']         = apply_filters( 'mcp_adapter_default_observability_handler', $config['observability_handler'] );
		$config['transports']                    = apply_filters( 'mcp_adapter_default_transports', $config['transports'] );
		$config['transport_permission_callback'] = apply_filters( 'mcp_adapter_default_transport_permission_callback', $config['transport_permission_callback'] );

		return $config;
	}
}
