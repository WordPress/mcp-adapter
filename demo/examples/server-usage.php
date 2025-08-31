<?php
/**
 * Example usage of MCP Server functionality.
 *
 * Note: The demo plugin creates two main servers automatically:
 * - Content Management Server (mcp/content)  
 * - Analytics Server (mcp/analytics)
 *
 * @package MCP\Demo
 */

// Example 1: Create a custom MCP Server (commented out - demo plugin creates servers)
/*
add_action( 'mcp_adapter_init', function( $adapter ) {
    $adapter->create_server(
        'custom-server',
        'mcp',
        'custom',
        'Custom Server',
        'Example of creating a custom MCP server',
        '1.0.0',
        array(
            \WP\MCP\Transport\Http\RestTransport::class,
        ),
        null,
        null,
        array( 'your-ability-name' ), // specify abilities to expose
        array(), // resources
        array()  // prompts
    );
} );
*/

// Example 2: Server with custom permissions (commented out)
/*
add_action( 'mcp_adapter_init', function( $adapter ) {
    $adapter->create_server(
        'admin-server',
        'mcp',
        'admin',
        'Admin Server',
        'Admin-only MCP server',
        '1.0.0',
        array(
            \WP\MCP\Transport\Http\RestTransport::class,
        ),
        null,
        null,
        array(),
        array(),
        array(),
        function() { return current_user_can( 'manage_options' ); } // admin only
    );
} );
*/