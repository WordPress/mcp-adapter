<?php
/**
 * Example of a simple monitoring transport wrapper.
 *
 * @package MCP\Demo
 */

// Example: Simple monitored transport (not active)
/*
class MonitoredRestTransport extends \WP\MCP\Transport\Http\RestTransport {
    
    public function handle( \WP\MCP\Transport\Infrastructure\McpTransportContext $context ): \WP_REST_Response {
        $start_time = microtime( true );
        
        // Log incoming request
        error_log( 'MCP Request: ' . $context->get_request_method() . ' ' . $context->get_endpoint() );
        
        // Call parent handler
        $response = parent::handle( $context );
        
        // Log response timing
        $duration = ( microtime( true ) - $start_time ) * 1000;
        error_log( "MCP Response: {$response->get_status()} in {$duration}ms" );
        
        return $response;
    }
}

// Example: How to use monitored transport (not active)
add_action( 'mcp_adapter_init', function( $adapter ) {
    $adapter->create_server(
        'monitored-server',
        'mcp',
        'monitored',
        'Monitored Server',
        'Server with request/response logging',
        '1.0.0',
        array(
            MonitoredRestTransport::class, // Custom transport with logging
        ),
        null,
        null,
        array(),
        array(),
        array()
    );
} );
*/