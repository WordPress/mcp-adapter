<?php
/**
 * The main plugin file.
 *
 * If we evolve from a canonical plugin into WordPress core, this file would be left behind.
 *
 * @package WP\MCP
 */

declare( strict_types=1 );

namespace WP\MCP;

use WP\MCP\Core\McpAdapter;
use WP_REST_Request;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - Plugin
 */
final class Plugin {
	/**
	 * The one true plugin.
	 *
	 * @var ?static
	 */
	private static ?self $instance = null;

	/**
	 * Gets the singleton instance of the plugin.
	 *
	 * @return self The plugin instance.
	 */
	public static function instance(): self {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
			self::$instance->setup();

			/**
			 * Fires after the main plugin class has been initialized.
			 *
			 * @since 0.1.0
			 *
			 * @param self $instance The main plugin class instance.
			 */
			do_action( 'wp_mcp_init', self::$instance );
		}

		return self::$instance;
	}

	/**
	 * Plugin constants.
	 */
	private function constants(): void {
		// This is locally defined in Autoloader::autoload() but is redefined here in case.
		if ( ! defined( 'WP_MCP_DIR' ) ) {
			/**
			 * Plugin directory path.
			 */
			define( 'WP_MCP_DIR', plugin_dir_path( __DIR__ ) );
		}

		/**
		 * Plugin version.
		 */
		define( 'WP_MCP_VERSION', McpAdapter::VERSION );
	}

	/**
	 * Sets up the plugin.
	 */
	private function setup(): void {
		// Define the plugin constants.
		$this->constants();

		McpAdapter::instance();

		// Tell the bundled OAuth server (oauth/) which routes to protect.
		add_filter( 'oauth_server_protected_resources', array( self::class, 'add_oauth_protected_resources' ) );

		// Let browser-based MCP clients on other origins send and read the MCP headers.
		add_filter( 'rest_allowed_cors_headers', array( self::class, 'allow_mcp_cors_headers' ), 10, 2 );
		add_filter( 'rest_exposed_cors_headers', array( self::class, 'expose_mcp_cors_headers' ), 10, 2 );
	}

	/**
	 * Allows the MCP request headers in cross-origin requests to MCP routes.
	 *
	 * @param string[]             $headers Allowed request headers.
	 * @param \WP_REST_Request|null $request Request.
	 * @return string[]
	 */
	public static function allow_mcp_cors_headers( $headers, $request = null ) {
		if ( ! is_array( $headers ) || ! self::is_mcp_request( $request ) ) {
			return $headers;
		}

		return array_merge( $headers, array( 'Mcp-Session-Id', 'MCP-Protocol-Version' ) );
	}

	/**
	 * Lets cross-origin clients read the session ID from MCP responses.
	 *
	 * @param string[]             $headers Exposed response headers.
	 * @param \WP_REST_Request|null $request Request.
	 * @return string[]
	 */
	public static function expose_mcp_cors_headers( $headers, $request = null ) {
		if ( ! is_array( $headers ) || ! self::is_mcp_request( $request ) ) {
			return $headers;
		}

		$headers[] = 'Mcp-Session-Id';

		return $headers;
	}

	/**
	 * Whether a REST request is for one of the MCP server routes.
	 *
	 * @param mixed $request Request.
	 */
	private static function is_mcp_request( $request ): bool {
		if ( ! $request instanceof WP_REST_Request ) {
			return false;
		}

		$route = '/' . trim( $request->get_route(), '/' );
		foreach ( McpAdapter::instance()->get_servers() as $server ) {
			if ( '/' . $server->get_server_route_namespace() . '/' . $server->get_server_route() === $route ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Adds every MCP server route to the OAuth server's protected resources.
	 *
	 * MCP servers are only created on rest_api_init, so this starts the REST
	 * server when called outside a REST request, such as for discovery documents.
	 *
	 * @param mixed $routes Protected REST routes.
	 * @return mixed
	 */
	public static function add_oauth_protected_resources( $routes ) {
		if ( ! is_array( $routes ) ) {
			return $routes;
		}

		if ( ! did_action( 'rest_api_init' ) ) {
			rest_get_server();
		}

		foreach ( McpAdapter::instance()->get_servers() as $server ) {
			$routes[] = '/' . $server->get_server_route_namespace() . '/' . $server->get_server_route();
		}

		return $routes;
	}

	/**
	 * Prevents the class from being cloned.
	 */
	public function __clone() {
		_doing_it_wrong(
			__FUNCTION__,
			sprintf(
			// translators: %s: Class name.
				esc_html__( 'The %s class should not be cloned.', 'mcp-adapter' ),
				esc_html( self::class ),
			),
			'0.1.0'
		);
	}

	/**
	 * Prevents the class from being deserialized.
	 */
	public function __wakeup() {
		_doing_it_wrong(
			__FUNCTION__,
			sprintf(
			// translators: %s: Class name.
				esc_html__( 'De-serializing instances of %s is not allowed.', 'mcp-adapter' ),
				esc_html( self::class ),
			),
			'0.1.0'
		);
	}
}
