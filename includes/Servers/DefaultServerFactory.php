<?php
/**
 * Factory for creating the default WordPress MCP server.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Servers;

use WP\MCP\Core\McpAdapter;
use WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler;
use WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler;
use WP\MCP\Transport\HttpTransport;

/**
 * Factory for creating the default WordPress MCP server.
 *
 * This server automatically discovers and exposes abilities with mcp.public=true metadata:
 * - discover-abilities: Lists all publicly available WordPress abilities
 * - get-ability-info: Gets detailed information about specific abilities
 * - execute-ability: Executes WordPress abilities with provided parameters
 */
class DefaultServerFactory {

	/**
	 * Create default server for WordPress MCP Adapter with WordPress filters support.
	 *
	 * This method creates a server using WordPress-specific defaults and applies
	 * WordPress filters for customization, making it perfect for use within
	 * the McpAdapter.
	 *
	 * @return void
	 */
	public static function create(): void {

		// Auto-discover resources and prompts from abilities
		$auto_discovered_resources = self::discover_abilities_by_type( 'resource' );
		$auto_discovered_prompts   = self::discover_abilities_by_type( 'prompt' );

		// WordPress-specific defaults
		$wordpress_defaults = array(
			'server_id'              => 'mcp-adapter-default-server',
			'server_route_namespace' => 'mcp',
			'server_route'           => 'mcp-adapter-default-server',
			'server_name'            => 'MCP Adapter Default Server',
			'server_description'     => 'Default MCP server for WordPress abilities discovery and execution',
			'server_version'         => 'v1.0.0',
			'mcp_transports'         => array( HttpTransport::class ),
			'error_handler'          => ErrorLogMcpErrorHandler::class,
			'observability_handler'  => NullMcpObservabilityHandler::class,
			'tools'                  => array(
				'mcp-adapter/discover-abilities',
				'mcp-adapter/get-ability-info',
				'mcp-adapter/execute-ability',
			),
			'resources'              => $auto_discovered_resources,
			'prompts'                => $auto_discovered_prompts,
		);

		// Apply WordPress filter for customization
		$config = apply_filters( 'mcp_adapter_default_server_config', $wordpress_defaults );

		// Ensure config is an array and merge with defaults
		if ( ! is_array( $config ) ) {
			$config = $wordpress_defaults;
		}
		$config = wp_parse_args( $config, $wordpress_defaults );

		// Use McpAdapter to create the server with full validation
		$adapter = McpAdapter::instance();
		$result  = $adapter->create_server(
			$config['server_id'],
			$config['server_route_namespace'],
			$config['server_route'],
			$config['server_name'],
			$config['server_description'],
			$config['server_version'],
			$config['mcp_transports'],
			$config['error_handler'],
			$config['observability_handler'],
			$config['tools'],
			$config['resources'],
			$config['prompts']
		);

		// Log error if server creation failed, but don't halt execution.
		// This allows other servers to be registered even if default server fails.
		if ( ! is_wp_error( $result ) ) {
			return;
		}

		_doing_it_wrong(
			__METHOD__,
			sprintf(
				'MCP Adapter: Failed to create default server. Error: %s (Code: %s)',
				esc_html( $result->get_error_message() ),
				esc_html( (string) $result->get_error_code() )
			),
			'n.e.x.t'
		);
	}

	/**
	 * Discover abilities by MCP type.
	 *
	 * Scans all registered abilities and returns those with the specified type
	 * and public MCP exposure.
	 *
	 * @param string $type The MCP type to filter by ('tool', 'resource', or 'prompt').
	 *
	 * @return array Array of ability names matching the specified type.
	 */
	private static function discover_abilities_by_type( string $type ): array {
		$abilities = wp_get_abilities();
		$filtered  = array();

		foreach ( $abilities as $ability ) {
			$ability_name = $ability->get_name();
			$meta         = $ability->get_meta();

			// Skip if not publicly exposed
			if ( ! ( $meta['mcp']['public'] ?? false ) ) {
				continue;
			}

			// Get the type (defaults to 'tool' if not specified)
			$ability_type = $meta['mcp']['type'] ?? 'tool';

			// Add to filtered list if type matches
			if ( $ability_type !== $type ) {
				continue;
			}

			$filtered[] = $ability_name;
		}

		return $filtered;
	}
}
