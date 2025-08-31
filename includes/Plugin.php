<?php
/**
 * The main plugin file.
 *
 * If we evolve from a canonical plugin into WordPress core, this file would be left behind.
 *
 * @package WP\MCP
 */

declare(strict_types = 1);

namespace WP\MCP;

use WP\MCP\Core\McpAdapter;
use WP\MCP\Admin\McpTestPage;

/**
 * Class - Plugin
 */
final class Plugin {
	/**
	 * The one true plugin.
	 *
	 * @var ?static
	 */
	private static $instance;

	/**
	 * {@inheritDoc}
	 */
	public static function instance(): self {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
			self::$instance->setup();

			/**
			 * Fires after the main plugin class has been initialized.
			 *
			 * @param self $instance The main plugin class instance.
			 */
			do_action( 'wp_mcp_init', self::$instance );
		}

		return self::$instance;
	}

	/**
	 * Setup the plugin.
	 */
	private function setup(): void {
		McpAdapter::instance();
		
		// Initialize admin interface
		if ( is_admin() ) {
			$test_page = new McpTestPage();
			$test_page->init();
		}
		
		// Register abilities during the correct action
		add_action( 'abilities_api_init', array( $this, 'register_default_abilities' ) );
		
		// Set up default MCP server
		add_action( 'mcp_adapter_init', array( $this, 'setup_default_server' ) );
		
		// Load examples early so their hooks are registered before mcp_adapter_init fires
		$this->load_examples();
	}
	
	/**
	 * Register default abilities during abilities_api_init.
	 */
	public function register_default_abilities(): void {
		// Only register if the function exists
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		
		// Register a basic ability for testing
		$result = wp_register_ability(
			'mcp-adapter/get-site-info',
			array(
				'label'               => 'Get Site Info',
				'description'         => 'Get basic WordPress site information',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'site_name' => array( 'type' => 'string' ),
						'site_url'  => array( 'type' => 'string' ),
						'wp_version' => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => function () {
					return array(
						'site_name'  => get_bloginfo( 'name' ),
						'site_url'   => get_site_url(),
						'wp_version' => get_bloginfo( 'version' ),
					);
				},
				'permission_callback' => function () {
					return true; // Allow public access for basic info
				},
			)
		);
		
		// Log if registration failed
		if ( ! $result ) {
			error_log( 'MCP: Failed to register ability mcp-adapter/get-site-info' );
		}

		// Register additional public abilities
		wp_register_ability(
			'mcp-adapter/get-posts',
			array(
				'label'               => 'Get Recent Posts',
				'description'         => 'Get recent blog posts from the WordPress site',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'count' => array(
							'type'        => 'integer',
							'description' => 'Number of posts to return (max 10)',
							'default'     => 5,
							'maximum'     => 10,
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'posts' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'title'   => array( 'type' => 'string' ),
									'excerpt' => array( 'type' => 'string' ),
									'url'     => array( 'type' => 'string' ),
									'date'    => array( 'type' => 'string' ),
								),
							),
						),
					),
				),
				'execute_callback'    => function ( $args ) {
					$count = min( intval( $args['count'] ?? 5 ), 10 );
					$posts = get_posts( array(
						'numberposts' => $count,
						'post_status' => 'publish',
					) );

					$result = array();
					foreach ( $posts as $post ) {
						$result[] = array(
							'title'   => get_the_title( $post ),
							'excerpt' => wp_trim_words( get_the_excerpt( $post ), 20 ),
							'url'     => get_permalink( $post ),
							'date'    => get_the_date( 'Y-m-d H:i:s', $post ),
						);
					}

					return array( 'posts' => $result );
				},
				'permission_callback' => function () {
					return true; // Public access
				},
			)
		);

		wp_register_ability(
			'mcp-adapter/search-content',
			array(
				'label'               => 'Search Content',
				'description'         => 'Search through WordPress posts and pages',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'query' => array(
							'type'        => 'string',
							'description' => 'Search term to look for',
							'minLength'   => 1,
						),
						'limit' => array(
							'type'        => 'integer',
							'description' => 'Maximum number of results (max 20)',
							'default'     => 10,
							'maximum'     => 20,
						),
					),
					'required' => array( 'query' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'results' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'title'   => array( 'type' => 'string' ),
									'excerpt' => array( 'type' => 'string' ),
									'url'     => array( 'type' => 'string' ),
									'type'    => array( 'type' => 'string' ),
								),
							),
						),
						'total' => array( 'type' => 'integer' ),
					),
				),
				'execute_callback'    => function ( $args ) {
					$query = sanitize_text_field( $args['query'] );
					$limit = min( intval( $args['limit'] ?? 10 ), 20 );

					// Use get_posts instead of WP_Query for simplicity
					$posts = get_posts( array(
						's'           => $query,
						'numberposts' => $limit,
						'post_type'   => array( 'post', 'page' ),
						'post_status' => 'publish',
					) );

					$results = array();
					foreach ( $posts as $post ) {
						$results[] = array(
							'title'   => get_the_title( $post ),
							'excerpt' => wp_trim_words( get_the_excerpt( $post ), 20 ),
							'url'     => get_permalink( $post ),
							'type'    => get_post_type( $post ),
						);
					}

					return array(
						'results' => $results,
						'total'   => count( $results ),
					);
				},
				'permission_callback' => function () {
					return true; // Public access
				},
			)
		);

		wp_register_ability(
			'mcp-adapter/get-menu',
			array(
				'label'               => 'Get Navigation Menu',
				'description'         => 'Get the main navigation menu items',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'menu_location' => array(
							'type'        => 'string',
							'description' => 'Menu location (default: primary)',
							'default'     => 'primary',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'menu_items' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'title' => array( 'type' => 'string' ),
									'url'   => array( 'type' => 'string' ),
								),
							),
						),
					),
				),
				'execute_callback'    => function ( $args ) {
					$location = sanitize_text_field( $args['menu_location'] ?? 'primary' );
					$locations = get_nav_menu_locations();
					
					if ( ! isset( $locations[ $location ] ) ) {
						return array( 'menu_items' => array() );
					}

					$menu = wp_get_nav_menu_object( $locations[ $location ] );
					if ( ! $menu ) {
						return array( 'menu_items' => array() );
					}

					$menu_items = wp_get_nav_menu_items( $menu );
					$result = array();

					if ( $menu_items ) {
						foreach ( $menu_items as $item ) {
							$result[] = array(
								'title' => $item->title,
								'url'   => $item->url,
							);
						}
					}

					return array( 'menu_items' => $result );
				},
				'permission_callback' => function () {
					return true; // Public access
				},
			)
		);
	}
	
	/**
	 * Set up a default MCP server for basic WordPress functionality.
	 */
	public function setup_default_server( McpAdapter $adapter ): void {
		// Create default MCP server (abilities should already be registered by now)
		$adapter->create_server(
			'wordpress-default',
			'mcp',
			'core',
			'WordPress Default Server',
			'Basic MCP server exposing WordPress abilities',
			'1.0.0',
			array(
				\WP\MCP\Transport\Http\RestTransport::class,
			),
			null,
			null,
			array( 
				'mcp-adapter/get-site-info',
				'mcp-adapter/get-posts',
				'mcp-adapter/search-content', 
				'mcp-adapter/get-menu'
			), // expose public abilities
			array(),
			array(),
			function() { return true; } // Make MCP server public (no authentication required)
		);
	}
	
	/**
	 * Load examples after WordPress is fully loaded.
	 */
	public function load_examples(): void {
		// Load server examples
		$server_examples_file = WP_MCP_DIR . 'examples/server-usage.php';
		if ( file_exists( $server_examples_file ) ) {
			include_once $server_examples_file;
		}
		
		// Load client examples  
		$client_examples_file = WP_MCP_DIR . 'examples/client-usage.php';
		if ( file_exists( $client_examples_file ) ) {
			include_once $client_examples_file;
		}
	}

	/**
	 * Prevent the class from being cloned.
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
	 * Prevent the class from being deserialized.
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
