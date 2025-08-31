<?php
/**
 * Example usage of MCP Server functionality.
 *
 * @package MCP\Demo
 */

// Example 1: Basic MCP Server
add_action( 'mcp_adapter_init', function( $adapter ) {
    $adapter->create_server(
        'basic-server',
        'mcp',
        'basic',
        'Basic WordPress Server',
        'Simple MCP server exposing WordPress abilities',
        '1.0.0',
        array(
            \WP\MCP\Transport\Http\RestTransport::class,
        ),
        null, // default error handler
        null, // default observability handler
        array(), // auto-discover abilities as tools
        array(), // no resources
        array()  // no prompts
    );
} );

// Example 2: Public MCP Server (no authentication)
add_action( 'mcp_adapter_init', function( $adapter ) {
    $adapter->create_server(
        'public-server',
        'mcp',
        'public',
        'Public WordPress Server',
        'Public MCP server - no authentication required',
        '1.0.0',
        array(
            \WP\MCP\Transport\Http\RestTransport::class,
        ),
        null,
        null,
        array(), // auto-discover abilities
        array(),
        array(),
        function() { return true; } // public access
    );
} );

// Example 3: Admin-only MCP Server
add_action( 'mcp_adapter_init', function( $adapter ) {
    $adapter->create_server(
        'admin-server',
        'mcp',
        'admin',
        'Admin WordPress Server',
        'Admin-only MCP server for management operations',
        '1.0.0',
        array(
            \WP\MCP\Transport\Http\RestTransport::class,
        ),
        null,
        null,
        array(),
        array(),
        array(),
        function() { return current_user_can( 'manage_options' ); }
    );
} );