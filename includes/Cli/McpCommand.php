<?php
/**
 * WP-CLI Command for MCP STDIO Transport
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Cli;

use WP\MCP\Core\McpAdapter;
use function WP_CLI\Utils\format_items;

/**
 * Manage MCP servers via WP-CLI.
 *
 * Provides commands to serve MCP servers over STDIO transport for
 * communication with MCP clients via subprocess.
 */
class McpCommand extends \WP_CLI_Command { // phpcs:ignore

	/**
	 * Serve an MCP server via STDIO transport.
	 *
	 * This command starts an MCP server that communicates via standard input/output
	 * using the JSON-RPC 2.0 protocol. It's designed to be launched as a subprocess
	 * by MCP clients.
	 *
	 * Use the global `--user` flag to specify the user context for the server. If not provided, runs as unauthenticated (limited capabilities).
	 *
	 * ## OPTIONS
	 *
	 * [--server=<server-id>]
	 * : The ID of the MCP server to serve. If not specified, uses the first available server.
	 *
	 * ## EXAMPLES
	 *
	 *     # Serve the default MCP server as admin user
	 *     wp mcp serve --user=admin
	 *
	 *     # Serve a specific server as user with ID 1
	 *     wp mcp serve --server=my-mcp-server --user=1
	 *
	 *     # Serve without authentication (limited capabilities)
	 *     wp mcp serve --server=public-server
	 *
	 * @when after_wp_load
	 * @synopsis [--server=<server-id>]
	 */
	public function serve( array $args, array $assoc_args ): void {

		// Get the MCP adapter instance
		$adapter = McpAdapter::instance();

		// Get all registered servers
		$servers = $adapter->get_servers();

		if ( empty( $servers ) ) {
			\WP_CLI::error( 'No MCP servers are registered. Please register at least one server first.' );
		}

		// Determine which server to use
		$server_id = $assoc_args['server'] ?? null;
		$server    = null;

		if ( $server_id ) {
			$server = $adapter->get_server( $server_id );
			if ( ! $server ) {
				\WP_CLI::error( sprintf( 'Server with ID "%s" not found.', $server_id ) );
			}
		} else {
			// Use the first available server
			$server    = array_values( $servers )[0];
			$server_id = $server->get_server_id();
			\WP_CLI::debug( sprintf( 'Using server: %s', $server_id ) );
		}

		// Create and start STDIO server bridge
		try {
			\WP_CLI::debug( sprintf( 'Starting STDIO bridge for server: %s', $server_id ) );

			// Create STDIO server bridge
			$stdio_bridge = new StdioServerBridge( $server );

			// Start serving (this blocks until terminated)
			$stdio_bridge->serve();
		} catch ( \RuntimeException $e ) {
			\WP_CLI::error( $e->getMessage() );
		} catch ( \Throwable $e ) {
			\WP_CLI::error( 'Failed to start STDIO bridge: ' . $e->getMessage() );
		}
	}

	/**
	 * List all registered MCP servers.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # List all MCP servers
	 *     wp mcp list
	 *
	 *     # List servers in JSON format
	 *     wp mcp list --format=json
	 *
	 * @when after_wp_load
	 * @synopsis [--format=<format>]
	 */
	public function list( array $args, array $assoc_args ): void {
		$adapter = McpAdapter::instance();

		$servers = $adapter->get_servers();

		if ( empty( $servers ) ) {
			\WP_CLI::line( 'No MCP servers registered.' );

			return;
		}

		$items = array();
		foreach ( $servers as $server ) {
			$items[] = array(
				'ID'          => $server->get_server_id(),
				'Name'        => $server->get_server_name(),
				'Version'     => $server->get_server_version(),
				'Tools'       => count( $server->get_tools() ),
				'Resources'   => count( $server->get_resources() ),
				'Prompts'     => count( $server->get_prompts() ),
				'Description' => $server->get_server_description(),
			);
		}

		$format = $assoc_args['format'] ?? 'table';
		format_items( $format, $items, array( 'ID', 'Name', 'Version', 'Tools', 'Resources', 'Prompts' ) );
	}
}
