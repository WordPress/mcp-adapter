<?php
/**
 * Example usage of MCP Server functionality.
 *
 * @package McpAdapter
 */

// Example 1: Basic MCP Server setup
add_action( 'mcp_adapter_init', function( $adapter ) {
    // Create a basic MCP server that exposes WordPress abilities
    $adapter->create_server(
        'wordpress-abilities',                    // server ID
        'mcp',                                   // REST namespace
        'core',                                  // REST route
        'WordPress Abilities Server',             // server name
        'Exposes WordPress abilities as MCP tools, resources, and prompts', // description
        '1.0.0',                                 // version
        array(                                   // transports
            \WP\MCP\Transport\Http\RestTransport::class,
        ),
        null,                                    // error handler (null = default)
        null,                                    // observability handler (null = default)
        array(),                                 // tools (empty = auto-discover from abilities)
        array(),                                 // resources (empty for now)
        array()                                  // prompts (empty for now)
    );
} );

// Example 2: Register abilities first, then create server
add_action( 'abilities_api_init', function() {
    // Only register if the function exists
    if ( ! function_exists( 'wp_register_ability' ) ) {
        return;
    }
    
    // Register some sample abilities first
    wp_register_ability(
        'mcp-examples/get-post-content',
        array(
            'label'               => 'Get Post Content',
            'description'         => 'Get the content of a WordPress post',
            'input_schema'        => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id' => array(
                        'type'        => 'integer',
                        'description' => 'The ID of the post to retrieve',
                    ),
                ),
                'required'   => array( 'post_id' ),
            ),
            'output_schema'       => array(
                'type'       => 'object',
                'properties' => array(
                    'title'   => array( 'type' => 'string' ),
                    'content' => array( 'type' => 'string' ),
                    'excerpt' => array( 'type' => 'string' ),
                ),
            ),
            'execute_callback'    => function ( $args ) {
                $post = get_post( $args['post_id'] );
                if ( ! $post ) {
                    return new \WP_Error( 'post_not_found', 'Post not found' );
                }
                return array(
                    'title'   => $post->post_title,
                    'content' => $post->post_content,
                    'excerpt' => $post->post_excerpt,
                );
            },
            'permission_callback' => function () {
                return current_user_can( 'read' );
            },
        )
    );

    wp_register_ability(
        'mcp-examples/create-post',
        array(
            'label'               => 'Create Post',
            'description'         => 'Create a new WordPress post',
            'input_schema'        => array(
                'type'       => 'object',
                'properties' => array(
                    'title'   => array(
                        'type'        => 'string',
                        'description' => 'The title of the post',
                    ),
                    'content' => array(
                        'type'        => 'string',
                        'description' => 'The content of the post',
                    ),
                    'status'  => array(
                        'type'        => 'string',
                        'enum'        => array( 'draft', 'publish', 'private' ),
                        'default'     => 'draft',
                        'description' => 'The status of the post',
                    ),
                ),
                'required'   => array( 'title', 'content' ),
            ),
            'output_schema'       => array(
                'type'       => 'object',
                'properties' => array(
                    'post_id' => array( 'type' => 'integer' ),
                    'success' => array( 'type' => 'boolean' ),
                ),
            ),
            'execute_callback'    => function ( $args ) {
                $post_id = wp_insert_post( array(
                    'post_title'   => $args['title'],
                    'post_content' => $args['content'],
                    'post_status'  => $args['status'] ?? 'draft',
                    'post_type'    => 'post',
                ) );

                if ( is_wp_error( $post_id ) ) {
                    return $post_id;
                }

                return array(
                    'post_id' => $post_id,
                    'success' => true,
                );
            },
            'permission_callback' => function () {
                return current_user_can( 'publish_posts' );
            },
        )
    );
} );

// Create server with the registered abilities
add_action( 'mcp_adapter_init', function( $adapter ) {
    // Create server with specific tools
    $adapter->create_server(
        'wordpress-content',
        'mcp',
        'content', 
        'WordPress Content Server',
        'MCP server for WordPress content management',
        '1.0.0',
        array(
            \WP\MCP\Transport\Http\RestTransport::class,
        ),
        null,
        null,
        array( 'mcp-examples/get-post-content', 'mcp-examples/create-post' ), // specific abilities to expose
        array(),
        array()
    );
} );

// Example 3: Server with resources
add_action( 'mcp_adapter_init', function( $adapter ) {
    $adapter->create_server(
        'wordpress-data',
        'mcp',
        'data',
        'WordPress Data Server', 
        'MCP server exposing WordPress data as resources',
        '1.0.0',
        array(
            \WP\MCP\Transport\Http\RestTransport::class,
        ),
        null,
        null,
        array(),
        array(
            array(
                'uri'         => 'wordpress://posts/recent',
                'name'        => 'Recent Posts',
                'description' => 'List of recent WordPress posts',
            ),
            array(
                'uri'         => 'wordpress://users/active', 
                'name'        => 'Active Users',
                'description' => 'List of active WordPress users',
            ),
        ),
        array()
    );
} );

// Example 4: Server with custom permissions
add_action( 'mcp_adapter_init', function( $adapter ) {
    $adapter->create_server(
        'admin-only',
        'mcp',
        'admin',
        'Admin Only Server',
        'MCP server for admin operations only',
        '1.0.0', 
        array(
            \WP\MCP\Transport\Http\RestTransport::class,
        ),
        null,
        null,
        array(),
        array(),
        array(),
        function() {
            // Custom permission callback - only admins can access
            return current_user_can( 'manage_options' );
        }
    );
} );