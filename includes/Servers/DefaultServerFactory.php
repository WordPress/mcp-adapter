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
	 * @throws \Exception
	 */
	public static function create(): void {

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
			'resources'              => array(),
			'prompts'                => array(),
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
		$adapter->create_server(
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
	}
}
