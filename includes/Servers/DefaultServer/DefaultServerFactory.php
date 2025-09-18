<?php
/**
 * Factory for creating the default WordPress MCP server.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Servers\DefaultServer;

use WP\MCP\Core\McpAdapter;
use WP\MCP\Core\McpServer;
use WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler;
use WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler;
use WP\MCP\Servers\DefaultServer\Tools\DiscoverAbilitiesTool;
use WP\MCP\Servers\DefaultServer\Tools\ExecuteAbilityTool;
use WP\MCP\Servers\DefaultServer\Tools\GetAbilityInfoTool;
use WP\MCP\Transport\HttpTransport;

/**
 * Factory for creating the default WordPress MCP server.
 *
 * This server provides the standard WordPress MCP implementation with:
 * - System tools for discovery, planning, and execution
 * - Ability-based tools from registered WordPress abilities
 * - Clean separation between system and business logic
 */
class DefaultServerFactory {

	/**
	 * Create default server for WordPress MCP Adapter with WordPress filters support.
	 *
	 * This method creates a server using WordPress-specific defaults and applies
	 * WordPress filters for customization, making it perfect for use within
	 * the McpAdapter.
	 *
	 * @return \WP\MCP\Core\McpServer The configured MCP server instance.
	 * @throws \Exception
	 */
	public static function create(): McpServer {

		// WordPress-specific defaults
		$wordpress_defaults = array(
			'server_id'              => 'mcp-adapter-default-server',
			'server_route_namespace' => 'mcp-adapter',
			'server_route'           => 'mcp',
			'server_name'            => 'MCP Adapter Default Server',
			'server_description'     => 'Default MCP server with system tools for WordPress abilities discovery and execution',
			'server_version'         => 'v1.0.0',
			'mcp_transports'         => array( HttpTransport::class ),
			'error_handler'          => ErrorLogMcpErrorHandler::class,
			'observability_handler'  => NullMcpObservabilityHandler::class,
			'tools'                  => array(
				DiscoverAbilitiesTool::class,
				GetAbilityInfoTool::class,
				ExecuteAbilityTool::class,
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

		// Return the created server
		return $adapter->get_server( $config['server_id'] );
	}
}
