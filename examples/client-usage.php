<?php
/**
 * Example usage of MCP Client functionality.
 *
 * @package McpAdapter
 */

// Example 1: Working MCP Client connection to WordPress Domains
add_action( 'mcp_client_init', function( $adapter ) {
    error_log( 'MCP Client Init: Attempting to create WordPress Domains client' );
    
    // Connect to WordPress Domains MCP server
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

    error_log( 'WordPress Domains client created successfully, connected: ' . ( $client->is_connected() ? 'yes' : 'no' ) );

    // Client automatically registers remote capabilities as WordPress abilities
    // Remote tools become: mcp_wpcom-domains/searchDomains, mcp_wpcom-domains/checkDomainAvailability, etc.
} );

// Example 2: Basic MCP Client connection (non-working example)
/*
add_action( 'mcp_client_init', function( $adapter ) {
    // Connect to an external AI analysis service (example only - URL doesn't exist)
    $client = $adapter->create_client(
        'ai-analyzer',
        'https://api.example-ai.com/mcp',
        array(
            'auth' => array(
                'type'  => 'bearer',
                'token' => 'your-api-token-here',
            ),
            'timeout' => 30,
        )
    );

    if ( is_wp_error( $client ) ) {
        error_log( 'Failed to connect to AI analyzer: ' . $client->get_error_message() );
        return;
    }

    // Client automatically registers remote capabilities as WordPress abilities
    // Remote tools become: mcp_ai-analyzer/analyze-content, mcp_ai-analyzer/generate-summary, etc.
    // Remote resources become: mcp_ai-analyzer/resource/user-data, etc.
    // Remote prompts become: mcp_ai-analyzer/prompt/seo-analysis, etc.
} );
*/

// Example 2: Using remote capabilities as WordPress abilities
// Note: Remote capabilities are automatically registered as abilities with mcp_{client_id}/ prefix
// They can be used once the Abilities API provides an execution function

// Example 3: Multiple clients for different services
/*
add_action( 'mcp_client_init', function( $adapter ) {
    // Connect to a translation service
    $adapter->create_client(
        'translator',
        'https://translate-api.example.com/mcp',
        array(
            'auth' => array(
                'type' => 'api_key',
                'key'  => 'translate-api-key',
            ),
        )
    );

    // Connect to an image processing service
    $adapter->create_client(
        'image-processor',
        'https://images.example.com/mcp',
        array(
            'auth' => array(
                'type'     => 'basic',
                'username' => 'user',
                'password' => 'pass',
            ),
        )
    );

    // Now you can use abilities like:
    // - mcp_translator/translate-text
    // - mcp_image-processor/resize-image
    // - mcp_image-processor/generate-alt-text
} );
*/

// Example 4: WordPress hooks for MCP operations
add_action( 'init', function() {
    // Custom permission control for remote MCP tools
    add_filter( 'mcp_client_permission', function( $allowed ) {
        // Only allow editors and above to use remote MCP tools
        return current_user_can( 'edit_others_posts' );
    } );
} );

// Example 4: Direct client usage (for advanced scenarios)
/*
add_action( 'mcp_client_init', function( $adapter ) {
    $client = $adapter->create_client( 'direct-usage', 'https://api.example.com/mcp', array() );

    if ( ! is_wp_error( $client ) ) {
        // Direct method calls (bypassing abilities)
        $tools = $client->list_tools();
        $result = $client->call_tool( 'analyze', array( 'text' => 'Hello' ) );
        $resource = $client->read_resource( 'data://user-profile' );
        $prompt = $client->get_prompt( 'summary', array( 'length' => 'short' ) );
    }
} );
*/

// Example 5: Error handling and observability - REMOVED FOR CLEANER TESTING