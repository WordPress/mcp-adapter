<?php
/**
 * Example usage of MCP Client functionality.
 *
 * @package McpAdapter
 */

// Example 1: Basic MCP Client connection
add_action( 'mcp_client_init', function( $adapter ) {
    // Connect to an external AI analysis service
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

// Example 2: Using remote capabilities as WordPress abilities
add_action( 'wp_loaded', function() {
    // Execute a remote AI analysis tool
    $result = wp_execute_ability( 'mcp_ai-analyzer/analyze-content', array(
        'content' => 'This is some content to analyze',
        'type'    => 'sentiment',
    ) );

    if ( ! is_wp_error( $result ) ) {
        // Use the analysis result
        $sentiment = $result['content'][0]['text'] ?? 'neutral';
        // ... use the sentiment data
    }

    // Read a remote resource
    $user_data = wp_execute_ability( 'mcp_ai-analyzer/resource/user-profile', array() );

    // Get a remote prompt for content generation
    $seo_prompt = wp_execute_ability( 'mcp_ai-analyzer/prompt/seo-analysis', array(
        'url'     => 'https://example.com',
        'keyword' => 'WordPress',
    ) );
} );

// Example 3: Multiple clients for different services
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

// Example 4: WordPress hooks for MCP operations
add_action( 'init', function() {
    // Custom permission control
    add_filter( 'mcp_client_permission', function( $allowed ) {
        // Only allow editors and above to use remote MCP tools
        return current_user_can( 'edit_others_posts' );
    } );

    // Hook into ability execution for logging/caching
    add_action( 'wp_ability_executed', function( $ability_name, $result, $args, $context ) {
        if ( isset( $context['type'] ) && strpos( $context['type'], 'mcp_remote_' ) === 0 ) {
            // Log remote MCP calls
            error_log( "Remote MCP call: {$ability_name} via client {$context['client_id']}" );

            // Could implement caching here for expensive remote operations
        }
    }, 10, 4 );
} );

// Example 5: Direct client usage (for advanced scenarios)
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

// Example 6: Error handling and observability
add_action( 'mcp_client_init', function( $adapter ) {
    // Use custom error handler for client operations
    $client = $adapter->create_client(
        'monitored-service',
        'https://monitored.example.com/mcp',
        array(),
        'WP\\MCP\\Infrastructure\\ErrorHandling\\ErrorLogMcpErrorHandler', // Custom error handler
        'WP\\MCP\\Infrastructure\\Observability\\ErrorLogMcpObservabilityHandler' // Custom observability
    );
} );