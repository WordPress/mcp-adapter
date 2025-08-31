<?php
/**
 * Example usage of MCP Client functionality.
 *
 * @package MCP\Demo
 */

// Example 1: Connect to WordPress Domains MCP server
add_action( 'mcp_client_init', function( $adapter ) {
    $client = $adapter->create_client(
        'wpcom-domains',
        'https://wpcom-domains-mcp.a8cai.workers.dev/mcp',
        array(
            'timeout' => 30,
        )
    );

    if ( is_wp_error( $client ) ) {
        error_log( 'Failed to connect to WordPress Domains: ' . $client->get_error_message() );
        return;
    }

    // Remote tools are automatically registered as WordPress abilities:
    // - mcp-wpcom-domains/searchdomains
    // - mcp-wpcom-domains/checkdomainavailability
    // - mcp-wpcom-domains/getsuggestedtlds
} );

// Example 2: Client with authentication
/*
add_action( 'mcp_client_init', function( $adapter ) {
    $client = $adapter->create_client(
        'my-service',
        'https://api.example.com/mcp',
        array(
            'auth' => array(
                'type'  => 'bearer',
                'token' => 'your-api-token-here',
            ),
            'timeout' => 30,
        )
    );
} );
*/

// Example 3: Custom permission control
add_action( 'init', function() {
    add_filter( 'mcp_client_permission', function( $allowed ) {
        return current_user_can( 'edit_others_posts' );
    } );
} );